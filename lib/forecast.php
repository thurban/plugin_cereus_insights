<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Capacity Forecasting                                  |
 +-------------------------------------------------------------------------+
*/

/**
 * Compute linear-regression forecast for a single datasource.
 *
 * Aggregates RRD data to daily maximums, runs least-squares regression,
 * then determines days-to-saturation against the thold_hi threshold.
 *
 * @param  int $local_data_id
 * @param  int $days  Days of RRD history to use
 * @return bool
 */
function cereus_insights_compute_forecast(int $local_data_id, int $days, array $excluded_ds = []): bool {
	$rows = cereus_insights_rrd_fetch($local_data_id, $days);

	if (empty($rows)) {
		return false;
	}

	/* Aggregate: daily maximum per datasource */
	$daily = array();  /* ['dsname']['YYYY-MM-DD'] => max */

	foreach ($rows as $row) {
		$date = gmdate('Y-m-d', $row['timestamp']);
		foreach ($row['sources'] as $ds => $val) {
			if ($val === null) {
				continue;
			}
			if (!isset($daily[$ds][$date]) || $val > $daily[$ds][$date]) {
				$daily[$ds][$date] = $val;
			}
		}
	}

	if (empty($daily)) {
		return false;
	}

	/* Host info + thold + name_cache in one query */
	$meta = db_fetch_row_prepared(
		"SELECT dl.host_id,
		        td.thold_hi,
		        td.thold_low,
		        COALESCE(td.name_cache, dtd.name_cache, '') AS name_cache
		 FROM data_local dl
		 LEFT JOIN thold_data td          ON td.local_data_id = dl.id
		 LEFT JOIN data_template_data dtd ON dtd.local_data_id = dl.id
		 WHERE dl.id = ?
		 LIMIT 1",
		array($local_data_id)
	);

	if (empty($meta)) {
		return false;
	}

	$host_id    = (int)    $meta['host_id'];
	$name_cache = (string) $meta['name_cache'];
	$thold_row  = $meta;

	$hi_pct = (float) (read_config_option('cereus_insights_forecast_hi_pct') ?: CEREUS_INSIGHTS_DEFAULT_FORECAST_HI_PCT);
	$hi_pct = max(1.0, min(100.0, $hi_pct)) / 100.0;

	foreach ($daily as $ds => $day_map) {
		if ($excluded_ds && in_array($ds, $excluded_ds, true)) {
			continue;
		}
		ksort($day_map);

		/* Build points array: [timestamp, daily_max] */
		$points = array();
		$last_value = 0.0;

		foreach ($day_map as $date => $max_val) {
			$ts       = strtotime($date . ' 12:00:00');
			$points[] = array($ts, $max_val);
			$last_value = $max_val;
		}

		if (count($points) < 3) {
			continue;
		}

		$reg = cereus_insights_linear_regression($points);

		$is_inverted = cereus_insights_is_inverted_metric($ds, $name_cache);

		/* Determine threshold and forecast based on metric direction */
		$threshold_value = 0.0;
		$forecast_days   = null;
		$forecast_date   = null;

		if ($is_inverted) {
			/* Free/idle metrics — only concerning when the value is SHRINKING.
			 * Forecast when a declining free metric hits thold_low. */
			if (!empty($thold_row['thold_low']) && (float) $thold_row['thold_low'] > 0) {
				$threshold_value = (float) $thold_row['thold_low'];
			}

			if ($threshold_value > 0 && $reg['slope'] < 0 && $last_value > $threshold_value) {
				$remaining_val   = $last_value - $threshold_value;
				$daily_decrement = abs($reg['slope'] * 86400);

				if ($daily_decrement > 0) {
					$fd            = (int) ceil($remaining_val / $daily_decrement);
					$forecast_days = $fd;
					$forecast_date = date('Y-m-d', strtotime('+' . $fd . ' days'));
				}
			}
		} else {
			/* Normal metrics — alert when a growing value hits thold_hi. */
			if (!empty($thold_row['thold_hi']) && (float) $thold_row['thold_hi'] > 0) {
				$threshold_value = (float) $thold_row['thold_hi'] * $hi_pct;
			}

			if ($threshold_value > 0 && $reg['slope'] > 0 && $last_value < $threshold_value) {
				$remaining_val   = $threshold_value - $last_value;
				$daily_increment = $reg['slope'] * 86400;

				if ($daily_increment > 0) {
					$fd            = (int) ceil($remaining_val / $daily_increment);
					$forecast_days = $fd;
					$forecast_date = date('Y-m-d', strtotime('+' . $fd . ' days'));
				}
			}
		}

		db_execute_prepared(
			"INSERT INTO plugin_cereus_insights_forecasts
				(local_data_id, datasource, host_id, name_cache,
				 slope, intercept, r_squared, last_value, threshold_value,
				 forecast_days, forecast_date, updated_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
			 ON DUPLICATE KEY UPDATE
				host_id         = VALUES(host_id),
				name_cache      = VALUES(name_cache),
				slope           = VALUES(slope),
				intercept       = VALUES(intercept),
				r_squared       = VALUES(r_squared),
				last_value      = VALUES(last_value),
				threshold_value = VALUES(threshold_value),
				forecast_days   = VALUES(forecast_days),
				forecast_date   = VALUES(forecast_date),
				updated_at      = VALUES(updated_at)",
			array(
				$local_data_id,
				$ds,
				$host_id,
				$name_cache,
				$reg['slope'],
				$reg['intercept'],
				$reg['r_squared'],
				$last_value,
				$threshold_value,
				$forecast_days,
				$forecast_date,
			)
		);
	}

	return true;
}

/**
 * Standard least-squares linear regression.
 *
 * @param  array $points  Array of [x, y] pairs (x = Unix timestamp, y = value)
 * @return array ['slope' => float, 'intercept' => float, 'r_squared' => float]
 */
function cereus_insights_linear_regression(array $points): array {
	$n = count($points);

	if ($n < 2) {
		return array('slope' => 0.0, 'intercept' => 0.0, 'r_squared' => 0.0);
	}

	/* Normalize x to reduce floating-point errors */
	$x0   = (float) $points[0][0];
	$sum_x  = 0.0;
	$sum_y  = 0.0;
	$sum_xx = 0.0;
	$sum_xy = 0.0;

	foreach ($points as $p) {
		$x       = (float) $p[0] - $x0;
		$y       = (float) $p[1];
		$sum_x  += $x;
		$sum_y  += $y;
		$sum_xx += $x * $x;
		$sum_xy += $x * $y;
	}

	$denom = $n * $sum_xx - $sum_x * $sum_x;

	if (abs($denom) < PHP_FLOAT_EPSILON) {
		return array('slope' => 0.0, 'intercept' => 0.0, 'r_squared' => 0.0);
	}

	$slope     = ($n * $sum_xy - $sum_x * $sum_y) / $denom;
	$intercept = ($sum_y - $slope * $sum_x) / $n;

	/* R² */
	$y_mean   = $sum_y / $n;
	$ss_tot   = 0.0;
	$ss_res   = 0.0;

	foreach ($points as $p) {
		$x       = (float) $p[0] - $x0;
		$y       = (float) $p[1];
		$y_pred  = $slope * $x + $intercept;
		$ss_res += ($y - $y_pred) ** 2;
		$ss_tot += ($y - $y_mean) ** 2;
	}

	$r_squared = ($ss_tot > 0) ? (1.0 - $ss_res / $ss_tot) : 0.0;

	/* Convert slope back to per-second (x was relative seconds) */
	return array(
		'slope'     => $slope,
		'intercept' => $intercept + $slope * $x0,
		'r_squared' => max(0.0, min(1.0, $r_squared)),
	);
}
