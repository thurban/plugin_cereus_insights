<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Constants                                             |
 +-------------------------------------------------------------------------+
*/

/* Alert queue status codes — mirrors thold status classification */
define('CEREUS_INSIGHTS_STATUS_BREACH',   0);
define('CEREUS_INSIGHTS_STATUS_WARNING',  2);
define('CEREUS_INSIGHTS_STATUS_RESTORAL', 4);

/* Declared global so they survive include() inside a function (poller_bottom hook) */
global $cereus_insights_status_labels, $cereus_insights_thold_status_map;

$cereus_insights_status_labels = array(
	CEREUS_INSIGHTS_STATUS_BREACH   => __('Breach', 'cereus_insights'),
	CEREUS_INSIGHTS_STATUS_WARNING  => __('Warning', 'cereus_insights'),
	CEREUS_INSIGHTS_STATUS_RESTORAL => __('Restoral', 'cereus_insights'),
);

/* Thold status to queue status mapping
 * 0=ST_RESTORAL, 1=ST_TRIGGERA, 2=ST_NOTIFYRA, 3=ST_NOTIFYWA,
 * 4=ST_NOTIFYAL, 5=ST_NOTIFYRS, 6=ST_TRIGGERW, 7=ST_NOTIFYAW, 8=ST_NOTIFYRAW
 */
$cereus_insights_thold_status_map = array(
	0 => CEREUS_INSIGHTS_STATUS_RESTORAL,
	1 => CEREUS_INSIGHTS_STATUS_BREACH,
	2 => CEREUS_INSIGHTS_STATUS_BREACH,
	3 => CEREUS_INSIGHTS_STATUS_WARNING,
	4 => CEREUS_INSIGHTS_STATUS_BREACH,
	5 => CEREUS_INSIGHTS_STATUS_RESTORAL,
	6 => CEREUS_INSIGHTS_STATUS_WARNING,
	7 => CEREUS_INSIGHTS_STATUS_WARNING,
	8 => CEREUS_INSIGHTS_STATUS_WARNING,
);

/* Default settings */
define('CEREUS_INSIGHTS_DEFAULT_SIGMA',           3);
define('CEREUS_INSIGHTS_DEFAULT_MIN_SAMPLES',     50);
define('CEREUS_INSIGHTS_DEFAULT_FORECAST_HI_PCT', 90);
define('CEREUS_INSIGHTS_DEFAULT_WARN_DAYS',       30);
define('CEREUS_INSIGHTS_DEFAULT_BASELINE_DAYS',   30);
define('CEREUS_INSIGHTS_DEFAULT_FORECAST_DAYS',   90);
define('CEREUS_INSIGHTS_DEFAULT_BATCH_SIZE',      500);
define('CEREUS_INSIGHTS_DEFAULT_BASELINE_INT',   300);
define('CEREUS_INSIGHTS_DEFAULT_FORECAST_INT',   3600);
define('CEREUS_INSIGHTS_DEFAULT_BREACH_RET',      30);
define('CEREUS_INSIGHTS_DEFAULT_SUMMARY_RET',     90);
define('CEREUS_INSIGHTS_DEFAULT_LLM_BATCH_WIN',   300);
define('CEREUS_INSIGHTS_DEFAULT_LLM_COOLDOWN',   3600);
define('CEREUS_INSIGHTS_CONF_MIN_SAMPLES',         3);   /* min samples/bucket for confident suggestion; 3 works with 2-hour RRD averages */
define('CEREUS_INSIGHTS_DEFAULT_LLM_PROVIDER',    'anthropic');
define('CEREUS_INSIGHTS_DEFAULT_LLM_MODEL',       'claude-haiku-4-5-20251001');
define('CEREUS_INSIGHTS_DEFAULT_LLM_MODEL_OAI',   'gpt-4o-mini');
define('CEREUS_INSIGHTS_DEFAULT_LLM_MODEL_GOOGLE', 'gemini-1.5-flash');

/**
 * Return true when the plugin tables exist, creating them if they are missing.
 *
 * Handles the case where a user visits a page before the Plugin Manager
 * Install step has run (or if the install failed). CREATE TABLE IF NOT EXISTS
 * is idempotent so calling this multiple times is safe.
 *
 * Uses a static cache so the DB is only queried once per request.
 */
function cereus_insights_tables_installed(): bool {
	static $installed = null;
	if ($installed !== null) return $installed;

	/* All 13 tables that must exist for the plugin to work. */
	static $required_tables = array(
		'plugin_cereus_insights_seen',
		'plugin_cereus_insights_baselines',
		'plugin_cereus_insights_forecasts',
		'plugin_cereus_insights_breaches',
		'plugin_cereus_insights_alert_queue',
		'plugin_cereus_insights_alert_seen',
		'plugin_cereus_insights_summaries',
		'plugin_cereus_insights_suggest_skip',
		'plugin_cereus_insights_suggest_cache',
		'plugin_cereus_insights_ds_exclusions',
		'plugin_cereus_insights_reports',
		'plugin_cereus_insights_anomaly_stats',
		'plugin_cereus_insights_sigma_overrides',
	);

	$list    = "'" . implode("','", $required_tables) . "'";
	$present = (int) db_fetch_cell(
		"SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.TABLES
		 WHERE TABLE_SCHEMA = DATABASE()
		   AND TABLE_NAME IN ($list)"
	);

	$installed = ($present >= count($required_tables));

	if (!$installed) {
		/* One or more tables missing — create all missing ones now. */
		global $config;
		if (!empty($config['base_path'])) {
			$setup = $config['base_path'] . '/plugins/cereus_insights/setup.php';
			if (file_exists($setup)) {
				include_once($setup);
				if (function_exists('cereus_insights_setup_tables')) {
					cereus_insights_setup_tables();
					db_execute(
						"INSERT IGNORE INTO plugin_cereus_insights_seen
						     (id, last_log_id, last_baseline_run, last_forecast_run,
						      last_purge_run, baseline_cursor, forecast_cursor, last_report_run)
						 VALUES (1, 0, 0, 0, 0, 0, 0, 0)"
					);
					$installed = true;
				}
			}
		}
	}

	return $installed;
}

/**
 * Return true when a low value is BAD (free disk, free memory, idle CPU…).
 * These metrics need thold_low / thold_warning_low instead of thold_hi / thold_warning_hi.
 */
function cereus_insights_is_inverted_metric(string $datasource, string $name_cache): bool {
	$hay = strtolower($datasource . ' ' . $name_cache);
	foreach (array('free', 'avail', 'remain', 'idle', 'unused') as $kw) {
		if (strpos($hay, $kw) !== false) {
			return true;
		}
	}
	return false;
}
