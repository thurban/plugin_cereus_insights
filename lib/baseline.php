<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Baseline Computation & Anomaly Detection              |
 +-------------------------------------------------------------------------+
*/

/**
 * Build or refresh seasonality baselines for a single datasource.
 *
 * Buckets are (hour_of_day 0-23) x (day_of_week 0-6, Sun=0).
 * Computes mean and sample stddev via two-pass algorithm.
 *
 * @param  int $local_data_id
 * @param  int $days  Days of RRD history to use
 * @return bool  true on success, false on no data / error
 */
function cereus_insights_compute_baselines(int $local_data_id, int $days, array $excluded_ds = []): bool {
	$rows = cereus_insights_rrd_fetch($local_data_id, $days);

	if (empty($rows)) {
		return false;
	}

	/* Bucket raw values by (ds_name, hour_of_day, day_of_week) */
	$buckets = array();  /* ['dsname']['H:D'] => [values...] */

	foreach ($rows as $row) {
		$ts  = $row['timestamp'];
		$hr  = (int) gmdate('G', $ts);
		$dow = (int) gmdate('w', $ts);
		$key = $hr . ':' . $dow;

		foreach ($row['sources'] as $ds => $val) {
			if ($val === null) {
				continue;
			}
			if (!isset($buckets[$ds][$key])) {
				$buckets[$ds][$key] = array();
			}
			$buckets[$ds][$key][] = $val;
		}
	}

	if (empty($buckets)) {
		return false;
	}

	$now = date('Y-m-d H:i:s');

	foreach ($buckets as $ds => $hour_buckets) {
		if ($excluded_ds && in_array($ds, $excluded_ds, true)) {
			continue;
		}
		foreach ($hour_buckets as $key => $values) {
			list($hr, $dow) = explode(':', $key);
			$hr  = (int) $hr;
			$dow = (int) $dow;
			$n   = count($values);

			if ($n < 2) {
				continue;
			}

			/* First pass: mean */
			$mean = array_sum($values) / $n;

			/* Second pass: sample stddev */
			$sq_sum = 0.0;
			foreach ($values as $v) {
				$sq_sum += ($v - $mean) ** 2;
			}
			$stddev = sqrt($sq_sum / ($n - 1));

			db_execute_prepared(
				"INSERT INTO plugin_cereus_insights_baselines
					(local_data_id, datasource, hour_of_day, day_of_week, mean, stddev, sample_count, updated_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
				 ON DUPLICATE KEY UPDATE
					mean         = VALUES(mean),
					stddev       = VALUES(stddev),
					sample_count = VALUES(sample_count),
					updated_at   = VALUES(updated_at)",
				array($local_data_id, $ds, $hr, $dow, $mean, $stddev, $n, $now)
			);
		}
	}

	return true;
}

/**
 * Detect anomalies for the current hour/dow against all baselines with
 * sufficient sample counts.
 *
 * Inserts into plugin_cereus_insights_breaches; skips if a breach for
 * the same local_data_id+datasource was inserted in the last 15 minutes.
 *
 * @return int  Number of new breaches recorded
 */
function cereus_insights_detect_anomalies(array $excluded_ds = []): int {
	$sigma       = (float)  (read_config_option('cereus_insights_sigma')       ?: CEREUS_INSIGHTS_DEFAULT_SIGMA);
	$min_samples = (int)    (read_config_option('cereus_insights_min_samples') ?: CEREUS_INSIGHTS_DEFAULT_MIN_SAMPLES);

	$override_rows = db_fetch_assoc("SELECT local_data_id, datasource, sigma FROM plugin_cereus_insights_sigma_overrides");
	$sigma_overrides = array();
	if (cacti_sizeof($override_rows)) {
		foreach ($override_rows as $ov) {
			$sigma_overrides[$ov['local_data_id'] . ':' . $ov['datasource']] = (float) $ov['sigma'];
		}
	}

	$hour = (int) date('G');
	$dow  = (int) date('w');

	/* Join baselines with the last-read value from thold_data,
	 * and pull in host info for logging */
	$candidates = db_fetch_assoc_prepared(
		"SELECT
			b.local_data_id,
			b.datasource,
			b.mean,
			b.stddev,
			td.lastread AS thold_lastread,
			dl.host_id,
			h.description AS host_description,
			COALESCE(td.name_cache, dtd.name_cache) AS name_cache
		 FROM plugin_cereus_insights_baselines b
		 JOIN data_local dl ON dl.id = b.local_data_id
		 JOIN host h ON h.id = dl.host_id
		 JOIN data_template_data dtd ON dtd.local_data_id = b.local_data_id
		 JOIN thold_data td
			ON td.local_data_id = b.local_data_id
			AND td.data_source_name = b.datasource
		 WHERE b.hour_of_day  = ?
		   AND b.day_of_week  = ?
		   AND b.sample_count >= ?
		   AND b.stddev        > 0
		   AND NOT EXISTS (SELECT 1 FROM plugin_cereus_insights_ds_exclusions ex WHERE ex.datasource = b.datasource)",
		array($hour, $dow, $min_samples)
	);

	$new_breaches = 0;

	if (!cacti_sizeof($candidates)) {
		return 0;
	}

	foreach ($candidates as $row) {
		$last_value = $row['thold_lastread'];
		if ($last_value === null || $last_value === '') {
			continue;
		}

		$last_value = (float) $last_value;
		$mean       = (float) $row['mean'];
		$stddev     = (float) $row['stddev'];

		$ldi = (int) $row['local_data_id'];
		$ds  = $row['datasource'];

		$key       = $ldi . ':' . $ds;
		$eff_sigma = $sigma_overrides[$key] ?? $sigma;

		$z_score = ($last_value - $mean) / $stddev;

		if (abs($z_score) <= $eff_sigma) {
			continue;
		}

		/* De-duplicate: skip if a breach was already recorded in the last 15 min */
		$recent = db_fetch_cell_prepared(
			"SELECT COUNT(*) FROM plugin_cereus_insights_breaches
			 WHERE local_data_id = ? AND datasource = ?
			   AND breached_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
			array($ldi, $ds)
		);

		if ((int) $recent > 0) {
			continue;
		}

		$expected_hi = $mean + ($eff_sigma * $stddev);

		db_execute_prepared(
			"INSERT INTO plugin_cereus_insights_breaches
				(local_data_id, datasource, host_id, host_description, name_cache,
				 value, expected_mean, expected_hi, z_score, breached_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
			array(
				$ldi,
				$ds,
				(int)   $row['host_id'],
				(string) $row['host_description'],
				(string) ($row['name_cache'] ?? ''),
				$last_value,
				$mean,
				$expected_hi,
				$z_score,
			)
		);

		$new_breaches++;
	}

	return $new_breaches;
}
