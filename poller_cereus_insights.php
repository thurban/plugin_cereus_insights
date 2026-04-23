<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Poller Worker                                         |
 |                                                                         |
 | Called either from the setup.php poller_bottom hook (web poller) or    |
 | directly from the CLI. Always runs on main poller only.                 |
 +-------------------------------------------------------------------------+
*/

if (!defined('CACTI_VERSION')) {
	chdir(dirname(dirname(dirname(__FILE__))));
	include('./include/global.php');
}

global $config;

if (!isset($config) || empty($config['base_path'])) {
	return;
}

/* Main poller only */
if (isset($config['poller_id']) && $config['poller_id'] != 1) {
	return;
}

include_once($config['base_path'] . '/plugins/cereus_insights/includes/constants.php');
include_once($config['base_path'] . '/plugins/cereus_insights/lib/license_check.php');
include_once($config['base_path'] . '/plugins/cereus_insights/lib/rrd.php');
include_once($config['base_path'] . '/plugins/cereus_insights/lib/baseline.php');
include_once($config['base_path'] . '/plugins/cereus_insights/lib/forecast.php');
include_once($config['base_path'] . '/plugins/cereus_insights/lib/llm.php');
include_once($config['base_path'] . '/plugins/cereus_insights/lib/report.php');

/* One-time migrations for already-installed instances */
if (!read_config_option('cereus_insights_migration_v102')) {
	/* Add realm for threshold suggestions pages and grant to all local admins */
	db_execute("INSERT IGNORE INTO plugin_realms (plugin, file, display)
		VALUES ('cereus_insights', 'cereus_insights_thsuggestions.php,cereus_insights_suggest_ajax.php',
		        'Cereus Insights - Threshold Suggestions')");

	$new_realm_id = (int) db_fetch_cell(
		"SELECT id FROM plugin_realms
		 WHERE plugin = 'cereus_insights'
		 AND file LIKE '%cereus_insights_thsuggestions.php%'
		 LIMIT 1"
	);

	if ($new_realm_id > 0) {
		db_execute_prepared(
			"INSERT IGNORE INTO user_auth_realm (user_id, realm_id)
			 SELECT id, ?
			 FROM user_auth
			 WHERE realm = 0 AND enabled = 'on'",
			array($new_realm_id + 100)
		);
	}

	set_config_option('cereus_insights_migration_v102', '1');
}

/* One-time migration: create tables added after initial install */
db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_suggest_skip (
	local_data_id INT UNSIGNED NOT NULL DEFAULT 0,
	datasource    VARCHAR(64) NOT NULL DEFAULT '',
	skipped_at    DATETIME NOT NULL,
	PRIMARY KEY (local_data_id, datasource)
) ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_suggest_cache (
	local_data_id      INT UNSIGNED NOT NULL DEFAULT 0,
	datasource         VARCHAR(64) NOT NULL DEFAULT '',
	suggested_hi       DOUBLE NOT NULL DEFAULT 0,
	suggested_warn     DOUBLE NOT NULL DEFAULT 0,
	suggested_low_alert DOUBLE NOT NULL DEFAULT 0,
	suggested_low_warn DOUBLE NOT NULL DEFAULT 0,
	avg_mean           DOUBLE NOT NULL DEFAULT 0,
	buckets            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
	total_samples      INT UNSIGNED NOT NULL DEFAULT 0,
	conf_pct           TINYINT UNSIGNED NOT NULL DEFAULT 0,
	updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (local_data_id, datasource),
	KEY idx_conf (conf_pct)
) ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Add new columns to existing installs — safe no-ops if already present */
db_execute("ALTER TABLE plugin_cereus_insights_suggest_cache
	ADD COLUMN IF NOT EXISTS suggested_low_alert DOUBLE NOT NULL DEFAULT 0 AFTER suggested_warn,
	ADD COLUMN IF NOT EXISTS suggested_low_warn  DOUBLE NOT NULL DEFAULT 0 AFTER suggested_low_alert");

/* Ensure the forecasts table exists — may be missing on partially-installed instances */
$_charset = 'ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_forecasts (
	id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	local_data_id    INT UNSIGNED NOT NULL DEFAULT 0,
	datasource       VARCHAR(64) NOT NULL DEFAULT '',
	host_id          INT UNSIGNED NOT NULL DEFAULT 0,
	name_cache       VARCHAR(255) NOT NULL DEFAULT '',
	slope            DOUBLE NOT NULL DEFAULT 0,
	intercept        DOUBLE NOT NULL DEFAULT 0,
	r_squared        DOUBLE NOT NULL DEFAULT 0,
	last_rrd_value   DOUBLE NOT NULL DEFAULT 0,
	threshold_value  DOUBLE NOT NULL DEFAULT 0,
	forecast_days    INT DEFAULT NULL,
	forecast_date    VARCHAR(10) DEFAULT NULL,
	updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (id),
	UNIQUE KEY idx_ldi_ds (local_data_id, datasource),
	KEY idx_host (host_id)
) $_charset");
unset($_charset);

/* Performance indexes — safe to re-run; IF NOT EXISTS is a no-op when already present */
db_execute("ALTER TABLE thold_data ADD INDEX IF NOT EXISTS idx_ldi_ds (local_data_id, data_source_name)");
db_execute("ALTER TABLE plugin_cereus_insights_baselines ADD INDEX IF NOT EXISTS idx_hour_dow (hour_of_day, day_of_week)");
db_execute("ALTER TABLE plugin_cereus_insights_breaches ADD INDEX IF NOT EXISTS idx_ldi_ds_bat (local_data_id, datasource, breached_at)");
db_execute("ALTER TABLE plugin_cereus_insights_forecasts ADD INDEX IF NOT EXISTS idx_forecast_days (forecast_days)");

db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_alert_seen (
	thold_id      INT UNSIGNED NOT NULL DEFAULT 0,
	queue_status  TINYINT UNSIGNED NOT NULL DEFAULT 0,
	last_notified DATETIME NOT NULL,
	PRIMARY KEY (thold_id)
) ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_ds_exclusions (
	datasource  VARCHAR(64) NOT NULL DEFAULT '',
	created_at  DATETIME NOT NULL,
	PRIMARY KEY (datasource)
) ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

/* Feature additions — safe no-ops on fresh installs */
db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_reports (
	id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	generated_at DATETIME NOT NULL,
	period_start DATETIME NOT NULL,
	period_end   DATETIME NOT NULL,
	subject      VARCHAR(255) NOT NULL DEFAULT '',
	report_text  TEXT NOT NULL,
	model        VARCHAR(50) NOT NULL DEFAULT '',
	tokens_used  INT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (id),
	KEY idx_generated_at (generated_at)
) ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_anomaly_stats (
	local_data_id  INT UNSIGNED NOT NULL DEFAULT 0,
	datasource     VARCHAR(64) NOT NULL DEFAULT '',
	total_anomalies INT UNSIGNED NOT NULL DEFAULT 0,
	signal_count   INT UNSIGNED NOT NULL DEFAULT 0,
	noise_count    INT UNSIGNED NOT NULL DEFAULT 0,
	noise_pct      TINYINT UNSIGNED NOT NULL DEFAULT 0,
	suggested_sigma FLOAT NULL DEFAULT NULL,
	evaluated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (local_data_id, datasource)
) ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_sigma_overrides (
	local_data_id INT UNSIGNED NOT NULL DEFAULT 0,
	datasource    VARCHAR(64) NOT NULL DEFAULT '',
	sigma         FLOAT NOT NULL DEFAULT 3,
	updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (local_data_id, datasource)
) ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

db_execute("ALTER TABLE plugin_cereus_insights_seen ADD COLUMN IF NOT EXISTS last_report_run INT UNSIGNED NOT NULL DEFAULT 0");

/* Ensure cereus_insights_reports.php is in the summaries realm for already-installed instances */
db_execute(
	"UPDATE plugin_realms
	 SET file = CONCAT(file, ',cereus_insights_reports.php')
	 WHERE plugin = 'cereus_insights'
	   AND file LIKE '%cereus_insights_summaries%'
	   AND file NOT LIKE '%cereus_insights_reports%'"
);

/**
 * Main poller entry point (called from hook or CLI).
 */
function cereus_insights_run_poller(bool $from_boost = false): void {
	global $cereus_insights_thold_status_map;

	$start = microtime(true);
	$now   = time();

	/* Load state — single row, id=1 */
	$state = db_fetch_row("SELECT * FROM plugin_cereus_insights_seen WHERE id = 1");

	if ($state === false || empty($state)) {
		/* First run after install */
		$max_log_id = (int) db_fetch_cell("SELECT COALESCE(MAX(id), 0) FROM plugin_thold_log");
		db_execute_prepared(
			"INSERT IGNORE INTO plugin_cereus_insights_seen
				(id, last_log_id, last_baseline_run, last_forecast_run, last_purge_run, baseline_cursor, forecast_cursor, last_report_run)
			 VALUES (1, ?, 0, 0, 0, 0, 0, 0)",
			array($max_log_id)
		);
		$state = array(
			'last_log_id'       => $max_log_id,
			'last_baseline_run' => 0,
			'last_forecast_run' => 0,
			'last_purge_run'    => 0,
			'baseline_cursor'   => 0,
			'forecast_cursor'   => 0,
			'last_report_run'   => 0,
		);
	}

	$last_log_id       = (int) $state['last_log_id'];
	$last_baseline_run = (int) $state['last_baseline_run'];
	$last_forecast_run = (int) $state['last_forecast_run'];
	$last_purge_run    = (int) $state['last_purge_run'];
	$baseline_cursor   = (int) $state['baseline_cursor'];
	$forecast_cursor   = (int) $state['forecast_cursor'];
	$last_report_run   = (int) ($state['last_report_run'] ?? 0);

	$batch_size = (int) (read_config_option('cereus_insights_batch_size') ?: CEREUS_INSIGHTS_DEFAULT_BATCH_SIZE);

	/* Read DS exclusion list once per cycle — passed to baseline/forecast functions */
	$excl_rows   = db_fetch_assoc("SELECT datasource FROM plugin_cereus_insights_ds_exclusions");
	$excluded_ds = $excl_rows ? array_column($excl_rows, 'datasource') : array();

	/* ------------------------------------------------------------------ */
	/* 1. Enterprise: ingest new thold_log rows into alert queue           */
	/* ------------------------------------------------------------------ */

	$is_enterprise   = cereus_insights_license_at_least('enterprise');
	$is_professional = cereus_insights_license_at_least('professional');
	$llm_enabled     = ($is_enterprise && read_config_option('cereus_insights_llm_enabled') === 'on');

	/* Alert ingestion and LLM flush only run from the main poller cycle,
	 * not from boost_poller_bottom — avoids concurrent queue races. */
	if ($from_boost) {
		goto rrd_work;
	}

	if ($llm_enabled) {
		$alert_cooldown = (int) (read_config_option('cereus_insights_llm_alert_cooldown') ?: CEREUS_INSIGHTS_DEFAULT_LLM_COOLDOWN);

		$new_events = db_fetch_assoc_prepared(
			"SELECT
				tl.id,
				tl.threshold_id,
				tl.host_id,
				tl.status AS thold_status,
				tl.current,
				tl.threshold_value,
				td.name_cache,
				td.thold_hi,
				td.thold_low,
				h.hostname,
				h.description AS host_description
			 FROM plugin_thold_log tl
			 LEFT JOIN thold_data td ON tl.threshold_id = td.id
			 LEFT JOIN host h ON tl.host_id = h.id
			 WHERE tl.id > ?
			 ORDER BY tl.id ASC",
			array($last_log_id)
		);

		if (cacti_sizeof($new_events)) {
			foreach ($new_events as $event) {
				$thold_status = (int) $event['thold_status'];
				$queue_status = $cereus_insights_thold_status_map[$thold_status]
					?? CEREUS_INSIGHTS_STATUS_BREACH;

				/* tl.threshold_value is empty for restoral events — fall back to thold_data */
				$thresh_val = (string) ($event['threshold_value'] ?? '');
				if ($thresh_val === '') {
					if ($thold_status <= 2 || $thold_status == 4) {
						$thresh_val = (string) ($event['thold_hi'] ?? '');
					} else {
						$thresh_val = (string) ($event['thold_low'] ?? '');
						if ($thresh_val === '') {
							$thresh_val = (string) ($event['thold_hi'] ?? '');
						}
					}
				}

				/* Dedup: skip if same status was already notified within cooldown.
				 * Always queue on state change (different status from last). */
				$thold_id_int = (int) $event['threshold_id'];
				$seen_row = db_fetch_row_prepared(
					"SELECT queue_status, UNIX_TIMESTAMP(last_notified) AS ts
					 FROM plugin_cereus_insights_alert_seen
					 WHERE thold_id = ?",
					array($thold_id_int)
				);

				$should_queue = true;
				if (cacti_sizeof($seen_row)) {
					$same_status     = ((int) $seen_row['queue_status'] === (int) $queue_status);
					$within_cooldown = (($now - (int) $seen_row['ts']) < $alert_cooldown);
					if ($same_status && $within_cooldown) {
						$should_queue = false;
					}
				}

				if ($should_queue) {
					db_execute_prepared(
						"INSERT INTO plugin_cereus_insights_alert_queue
							(thold_id, host_id, hostname, host_description, name_cache,
							 current_value, threshold_value, status, queued_at)
						 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
						array(
							$thold_id_int,
							(int)    $event['host_id'],
							(string) ($event['hostname'] ?? ''),
							(string) ($event['host_description'] ?? ''),
							(string) ($event['name_cache'] ?? ''),
							(string) ($event['current'] ?? ''),
							(string) $thresh_val,
							$queue_status,
						)
					);
				}
			}

			$max_id = $new_events[cacti_sizeof($new_events) - 1]['id'];
			$last_log_id = (int) $max_id;
			db_execute_prepared(
				"UPDATE plugin_cereus_insights_seen SET last_log_id = ? WHERE id = 1",
				array($last_log_id)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* 2. Enterprise: flush LLM queue if batch window has elapsed          */
	/* ------------------------------------------------------------------ */

	if ($llm_enabled) {
		$batch_window = (int) (read_config_option('cereus_insights_llm_batch_window') ?: CEREUS_INSIGHTS_DEFAULT_LLM_BATCH_WIN);

		$queue_count = (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_cereus_insights_alert_queue");

		if ($queue_count > 0) {
			$oldest_queued = db_fetch_cell("SELECT MIN(UNIX_TIMESTAMP(queued_at)) FROM plugin_cereus_insights_alert_queue");
			if ($oldest_queued !== false && $oldest_queued !== null) {
				if (($now - (int) $oldest_queued) >= $batch_window) {
					cereus_insights_flush_llm_queue();
				}
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* 3. Enterprise: weekly intelligence report                           */
	/* ------------------------------------------------------------------ */
	if ($llm_enabled) {
		if (cereus_insights_check_weekly_report($now, $last_report_run)) {
			cereus_insights_generate_weekly_report();
			$last_report_run = $now;
			db_execute_prepared(
				"UPDATE plugin_cereus_insights_seen SET last_report_run = ? WHERE id = 1",
				array($now)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* 4. Professional+: anomaly detection                                 */
	/* ------------------------------------------------------------------ */

	$new_breaches = 0;
	if ($is_professional) {
		$new_breaches = cereus_insights_detect_anomalies($excluded_ds);
	}

	/* ------------------------------------------------------------------ */
	/* 5. Professional+: baseline refresh (batch)                          */
	/* ------------------------------------------------------------------ */

	rrd_work:
	/* When Boost is enabled, skip RRD reads in poller_bottom — we run from
	 * boost_poller_bottom instead, after Boost finishes writing the RRDs.
	 * This prevents rrdtool fetch calls from contending with Boost's writes. */
	$boost_active = (read_config_option('boost_rrd_update_enable') === 'on');
	$run_rrd_work = $from_boost || !$boost_active;

	$baseline_interval = (int) (read_config_option('cereus_insights_baseline_interval') ?: CEREUS_INSIGHTS_DEFAULT_BASELINE_INT);

	/* Advance an in-progress pass every cycle; only gate the START of a
	 * new pass (cursor == 0) by the configured interval. */
	$baseline_pass_due = ($baseline_cursor > 0) || (($now - $last_baseline_run) >= $baseline_interval);

	if ($run_rrd_work && $is_professional && $baseline_pass_due) {
		$baseline_days = (int) (read_config_option('cereus_insights_baseline_days') ?: CEREUS_INSIGHTS_DEFAULT_BASELINE_DAYS);

		$ldi_rows = db_fetch_assoc_prepared(
			"SELECT id AS local_data_id FROM data_local
			 WHERE id > ?
			 ORDER BY id ASC
			 LIMIT " . intval($batch_size),
			array($baseline_cursor)
		);

		if (cacti_sizeof($ldi_rows)) {
			foreach ($ldi_rows as $r) {
				cereus_insights_compute_baselines((int) $r['local_data_id'], $baseline_days, $excluded_ds);
			}

			/* Refresh suggestion cache for this batch: delete + re-aggregate from baselines */
			$ldi_list = implode(',', array_map('intval', array_column($ldi_rows, 'local_data_id')));
			db_execute("DELETE FROM plugin_cereus_insights_suggest_cache WHERE local_data_id IN ($ldi_list)");
			db_execute(
				"INSERT INTO plugin_cereus_insights_suggest_cache
					(local_data_id, datasource, suggested_hi, suggested_warn,
					 suggested_low_alert, suggested_low_warn,
					 avg_mean, buckets, total_samples, conf_pct)
				 SELECT local_data_id, datasource,
				        ROUND(MAX(mean + 3 * stddev), 4),
				        ROUND(MAX(mean + 2 * stddev), 4),
				        ROUND(GREATEST(AVG(mean) * 0.10, MIN(mean - 3 * stddev)), 4),
				        ROUND(GREATEST(AVG(mean) * 0.15, MIN(mean - 2 * stddev)), 4),
				        ROUND(AVG(mean), 4),
				        COUNT(*),
				        SUM(sample_count),
				        ROUND(100.0 * SUM(CASE WHEN sample_count >= " . (int)(read_config_option('cereus_insights_conf_min_samples') ?: CEREUS_INSIGHTS_CONF_MIN_SAMPLES) . " THEN 1 ELSE 0 END) / COUNT(*))
				 FROM plugin_cereus_insights_baselines
				 WHERE local_data_id IN ($ldi_list)
				 GROUP BY local_data_id, datasource
				 HAVING COUNT(*) >= 4"
			);

			$last_row_ldi    = (int) $ldi_rows[cacti_sizeof($ldi_rows) - 1]['local_data_id'];
			$baseline_cursor = $last_row_ldi;
		} else {
			/* Full pass complete — reset cursor and record completion time */
			$baseline_cursor   = 0;
			$last_baseline_run = $now;
		}

		db_execute_prepared(
			"UPDATE plugin_cereus_insights_seen
			 SET last_baseline_run = ?, baseline_cursor = ?
			 WHERE id = 1",
			array($last_baseline_run, $baseline_cursor)
		);
	}

	/* ------------------------------------------------------------------ */
	/* 6. Community+: forecast refresh (configurable interval, batch)     */
	/* ------------------------------------------------------------------ */

	$forecast_interval = (int) (read_config_option('cereus_insights_forecast_interval') ?: CEREUS_INSIGHTS_DEFAULT_FORECAST_INT);

	/* Advance an in-progress pass every cycle; only gate the START of a
	 * new pass (cursor == 0) by the configured interval. */
	$forecast_pass_due = ($forecast_cursor > 0) || (($now - $last_forecast_run) >= $forecast_interval);

	if ($run_rrd_work && $forecast_pass_due) {
		$forecast_days = (int) (read_config_option('cereus_insights_forecast_days') ?: CEREUS_INSIGHTS_DEFAULT_FORECAST_DAYS);

		$ldi_rows = db_fetch_assoc_prepared(
			"SELECT id AS local_data_id FROM data_local
			 WHERE id > ?
			 ORDER BY id ASC
			 LIMIT " . intval($batch_size),
			array($forecast_cursor)
		);

		if (cacti_sizeof($ldi_rows)) {
			foreach ($ldi_rows as $r) {
				cereus_insights_compute_forecast((int) $r['local_data_id'], $forecast_days, $excluded_ds);
			}
			$last_row_ldi    = (int) $ldi_rows[cacti_sizeof($ldi_rows) - 1]['local_data_id'];
			$forecast_cursor = $last_row_ldi;
		} else {
			/* Full pass complete — reset cursor and record completion time */
			$forecast_cursor   = 0;
			$last_forecast_run = $now;
		}

		db_execute_prepared(
			"UPDATE plugin_cereus_insights_seen
			 SET last_forecast_run = ?, forecast_cursor = ?
			 WHERE id = 1",
			array($last_forecast_run, $forecast_cursor)
		);
	}

	/* ------------------------------------------------------------------ */
	/* 7. Daily purge                                                      */
	/* ------------------------------------------------------------------ */

	if (($now - $last_purge_run) >= 86400) {
		$breach_ret  = (int) (read_config_option('cereus_insights_breach_retention')  ?: CEREUS_INSIGHTS_DEFAULT_BREACH_RET);
		$summary_ret = (int) (read_config_option('cereus_insights_summary_retention') ?: CEREUS_INSIGHTS_DEFAULT_SUMMARY_RET);

		db_execute_prepared(
			"DELETE FROM plugin_cereus_insights_breaches
			 WHERE breached_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
			array($breach_ret)
		);

		db_execute_prepared(
			"DELETE FROM plugin_cereus_insights_summaries
			 WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
			array($summary_ret)
		);

		/* Remove alert_seen entries for thresholds that no longer exist */
		db_execute(
			"DELETE s FROM plugin_cereus_insights_alert_seen s
			 LEFT JOIN thold_data td ON td.id = s.thold_id
			 WHERE td.id IS NULL"
		);

		/* Remove baselines, forecasts, and cache entries whose data source no longer exists */
		db_execute(
			"DELETE b FROM plugin_cereus_insights_baselines b
			 LEFT JOIN data_local dl ON dl.id = b.local_data_id
			 WHERE dl.id IS NULL"
		);

		db_execute(
			"DELETE c FROM plugin_cereus_insights_suggest_cache c
			 LEFT JOIN data_local dl ON dl.id = c.local_data_id
			 WHERE dl.id IS NULL"
		);

		db_execute(
			"DELETE f FROM plugin_cereus_insights_forecasts f
			 LEFT JOIN data_local dl ON dl.id = f.local_data_id
			 WHERE dl.id IS NULL"
		);

		/* Remove baselines and forecasts for now-excluded datasources */
		db_execute(
			"DELETE b FROM plugin_cereus_insights_baselines b
			 JOIN plugin_cereus_insights_ds_exclusions ex ON ex.datasource = b.datasource"
		);
		db_execute(
			"DELETE f FROM plugin_cereus_insights_forecasts f
			 JOIN plugin_cereus_insights_ds_exclusions ex ON ex.datasource = f.datasource"
		);

		/* Classify anomaly noise and update per-datasource stats */
		$global_sigma = (float)(read_config_option('cereus_insights_sigma') ?: CEREUS_INSIGHTS_DEFAULT_SIGMA);
		db_execute_prepared(
			"INSERT INTO plugin_cereus_insights_anomaly_stats
			     (local_data_id, datasource, total_anomalies, signal_count, noise_count, noise_pct, suggested_sigma)
			 SELECT
			     b.local_data_id,
			     b.datasource,
			     COUNT(*) AS total_anomalies,
			     SUM(CASE WHEN tl.id IS NOT NULL THEN 1 ELSE 0 END) AS signal_count,
			     SUM(CASE WHEN tl.id IS NULL     THEN 1 ELSE 0 END) AS noise_count,
			     ROUND(100.0 * SUM(CASE WHEN tl.id IS NULL THEN 1 ELSE 0 END) / COUNT(*)) AS noise_pct,
			     CASE
			         WHEN ROUND(100.0 * SUM(CASE WHEN tl.id IS NULL THEN 1 ELSE 0 END) / COUNT(*)) >= 90
			             THEN LEAST(5.0, COALESCE(ov.sigma, ?) + 1.0)
			         WHEN ROUND(100.0 * SUM(CASE WHEN tl.id IS NULL THEN 1 ELSE 0 END) / COUNT(*)) >= 70
			             THEN LEAST(5.0, COALESCE(ov.sigma, ?) + 0.5)
			         ELSE NULL
			     END AS suggested_sigma
			 FROM plugin_cereus_insights_breaches b
			 LEFT JOIN thold_data td
			     ON td.local_data_id = b.local_data_id AND td.data_source_name = b.datasource
			 LEFT JOIN plugin_thold_log tl
			     ON tl.threshold_id = td.id
			     AND tl.time BETWEEN UNIX_TIMESTAMP(b.breached_at) - 1800
			                     AND UNIX_TIMESTAMP(b.breached_at) + 1800
			 LEFT JOIN plugin_cereus_insights_sigma_overrides ov
			     ON ov.local_data_id = b.local_data_id AND ov.datasource = b.datasource
			 WHERE b.breached_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
			 GROUP BY b.local_data_id, b.datasource
			 HAVING COUNT(*) >= 10
			 ON DUPLICATE KEY UPDATE
			     total_anomalies = VALUES(total_anomalies),
			     signal_count    = VALUES(signal_count),
			     noise_count     = VALUES(noise_count),
			     noise_pct       = VALUES(noise_pct),
			     suggested_sigma = VALUES(suggested_sigma)",
			array($global_sigma, $global_sigma, $breach_ret)
		);

		db_execute_prepared(
			"UPDATE plugin_cereus_insights_seen SET last_purge_run = ? WHERE id = 1",
			array($now)
		);
	}

	$runtime = round(microtime(true) - $start, 3);
	$tier    = cereus_insights_license_tier();

	set_config_option('cereus_insights_stats', "Time:{$runtime}s Tier:{$tier} Breaches:{$new_breaches}");

	cacti_log(
		"CEREUS INSIGHTS: cycle complete in {$runtime}s tier={$tier} new_breaches={$new_breaches}",
		false, 'SYSTEM', POLLER_VERBOSITY_MEDIUM
	);
}

/* Allow direct CLI invocation */
if (php_sapi_name() === 'cli') {
	cereus_insights_run_poller();
}
