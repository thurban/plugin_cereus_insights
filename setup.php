<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Plugin Setup                                          |
 +-------------------------------------------------------------------------+
*/

function plugin_cereus_insights_install() {
	/* UI hooks */
	api_plugin_register_hook('cereus_insights', 'config_arrays',        'cereus_insights_config_arrays',   'includes/arrays.php');
	api_plugin_register_hook('cereus_insights', 'config_settings',      'cereus_insights_config_settings', 'includes/settings.php');
	api_plugin_register_hook('cereus_insights', 'draw_navigation_text', 'cereus_insights_draw_navigation', 'setup.php');
	api_plugin_register_hook('cereus_insights', 'page_head',            'cereus_insights_page_head',       'setup.php');

	/* Poller hooks */
	api_plugin_register_hook('cereus_insights', 'poller_bottom',       'cereus_insights_poller_bottom',       'setup.php');
	api_plugin_register_hook('cereus_insights', 'boost_poller_bottom', 'cereus_insights_boost_poller_bottom', 'setup.php');

	/* Realms */
	api_plugin_register_realm('cereus_insights', 'cereus_insights.php,cereus_insights_help.php,cereus_insights_api_test.php',
		__('Cereus Insights - Anomaly Breaches', 'cereus_insights'), 1);
	api_plugin_register_realm('cereus_insights', 'cereus_insights_forecasts.php',
		__('Cereus Insights - Capacity Forecasts', 'cereus_insights'), 1);
	api_plugin_register_realm('cereus_insights', 'cereus_insights_summaries.php',
		__('Cereus Insights - AI Alert Summaries', 'cereus_insights'), 1);
	api_plugin_register_realm('cereus_insights', 'cereus_insights_thsuggestions.php,cereus_insights_suggest_ajax.php',
		__('Cereus Insights - Threshold Suggestions', 'cereus_insights'), 1);
	api_plugin_register_realm('cereus_insights', 'cereus_insights_reports.php',
		__('Cereus Insights - Weekly Reports', 'cereus_insights'), 1);

	/* Enable all hooks */
	api_plugin_enable_hooks('cereus_insights');

	/* Create tables */
	cereus_insights_setup_tables();

	/* Seed the seen row with the current max thold log id */
	$max_log_id = db_fetch_cell("SELECT COALESCE(MAX(id), 0) FROM plugin_thold_log");
	if ($max_log_id === false) {
		$max_log_id = 0;
	}

	db_execute_prepared(
		"INSERT INTO plugin_cereus_insights_seen
			(id, last_log_id, last_baseline_run, last_forecast_run, last_purge_run, baseline_cursor, forecast_cursor)
		 VALUES (1, ?, 0, 0, 0, 0, 0)
		 ON DUPLICATE KEY UPDATE last_log_id = VALUES(last_log_id)",
		array($max_log_id)
	);
}

function plugin_cereus_insights_uninstall() {
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_baselines');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_forecasts');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_breaches');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_alert_queue');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_summaries');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_alert_seen');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_suggest_skip');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_suggest_cache');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_seen');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_ds_exclusions');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_reports');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_anomaly_stats');
	db_execute('DROP TABLE IF EXISTS plugin_cereus_insights_sigma_overrides');
}

function plugin_cereus_insights_version() {
	global $config;

	$info = parse_ini_file($config['base_path'] . '/plugins/cereus_insights/INFO', true);

	return $info['info'];
}

function plugin_cereus_insights_check_config() {
	return true;
}

function plugin_cereus_insights_upgrade($info) {
	return false;
}

/* -------------------------------------------------------------------------
 * Table creation
 * ---------------------------------------------------------------------- */

function cereus_insights_setup_tables() {
	$charset = 'ENGINE=InnoDB ROW_FORMAT=Dynamic DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_baselines (
		id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		local_data_id   INT UNSIGNED NOT NULL DEFAULT 0,
		datasource      VARCHAR(64) NOT NULL DEFAULT '',
		hour_of_day     TINYINT UNSIGNED NOT NULL DEFAULT 0,
		day_of_week     TINYINT UNSIGNED NOT NULL DEFAULT 0,
		mean            DOUBLE NOT NULL DEFAULT 0,
		stddev          DOUBLE NOT NULL DEFAULT 0,
		sample_count    INT UNSIGNED NOT NULL DEFAULT 0,
		updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY idx_bucket (local_data_id, datasource, hour_of_day, day_of_week),
		KEY idx_ldi (local_data_id),
		KEY idx_hour_dow (hour_of_day, day_of_week)
	) $charset");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_forecasts (
		id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		local_data_id    INT UNSIGNED NOT NULL DEFAULT 0,
		datasource       VARCHAR(64) NOT NULL DEFAULT '',
		host_id          MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		name_cache       VARCHAR(255) NOT NULL DEFAULT '',
		slope            DOUBLE NOT NULL DEFAULT 0,
		intercept        DOUBLE NOT NULL DEFAULT 0,
		r_squared        FLOAT NOT NULL DEFAULT 0,
		last_value       DOUBLE NOT NULL DEFAULT 0,
		threshold_value  DOUBLE NOT NULL DEFAULT 0,
		forecast_days    SMALLINT NULL DEFAULT NULL,
		forecast_date    DATE NULL DEFAULT NULL,
		updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY idx_ldi_ds (local_data_id, datasource),
		KEY idx_host (host_id),
		KEY idx_forecast_date (forecast_date),
		KEY idx_forecast_days (forecast_days)
	) $charset");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_breaches (
		id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		local_data_id    INT UNSIGNED NOT NULL DEFAULT 0,
		datasource       VARCHAR(64) NOT NULL DEFAULT '',
		host_id          MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		host_description VARCHAR(255) NOT NULL DEFAULT '',
		name_cache       VARCHAR(255) NOT NULL DEFAULT '',
		value            DOUBLE NOT NULL DEFAULT 0,
		expected_mean    DOUBLE NOT NULL DEFAULT 0,
		expected_hi      DOUBLE NOT NULL DEFAULT 0,
		z_score          FLOAT NOT NULL DEFAULT 0,
		breached_at      DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY idx_host (host_id),
		KEY idx_breached_at (breached_at),
		KEY idx_ldi (local_data_id),
		KEY idx_ldi_ds_bat (local_data_id, datasource, breached_at)
	) $charset");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_alert_queue (
		id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		thold_id         INT UNSIGNED NOT NULL DEFAULT 0,
		host_id          MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
		hostname         VARCHAR(255) NOT NULL DEFAULT '',
		host_description VARCHAR(255) NOT NULL DEFAULT '',
		name_cache       VARCHAR(255) NOT NULL DEFAULT '',
		current_value    VARCHAR(50) NOT NULL DEFAULT '',
		threshold_value  VARCHAR(50) NOT NULL DEFAULT '',
		status           TINYINT UNSIGNED NOT NULL DEFAULT 0,
		queued_at        DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY idx_host (host_id),
		KEY idx_queued_at (queued_at)
	) $charset");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_summaries (
		id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		alert_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
		summary     TEXT NOT NULL,
		raw_alerts  TEXT NOT NULL,
		model       VARCHAR(50) NOT NULL DEFAULT '',
		tokens_used INT UNSIGNED NOT NULL DEFAULT 0,
		created_at  DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY idx_created_at (created_at)
	) $charset");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_alert_seen (
		thold_id      INT UNSIGNED NOT NULL DEFAULT 0,
		queue_status  TINYINT UNSIGNED NOT NULL DEFAULT 0,
		last_notified DATETIME NOT NULL,
		PRIMARY KEY (thold_id)
	) $charset");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_suggest_skip (
		local_data_id INT UNSIGNED NOT NULL DEFAULT 0,
		datasource    VARCHAR(64) NOT NULL DEFAULT '',
		skipped_at    DATETIME NOT NULL,
		PRIMARY KEY (local_data_id, datasource)
	) $charset");

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
	) $charset");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_seen (
		id                 INT UNSIGNED NOT NULL DEFAULT 1,
		last_log_id        INT UNSIGNED NOT NULL DEFAULT 0,
		last_baseline_run  INT UNSIGNED NOT NULL DEFAULT 0,
		last_forecast_run  INT UNSIGNED NOT NULL DEFAULT 0,
		last_purge_run     INT UNSIGNED NOT NULL DEFAULT 0,
		baseline_cursor    INT UNSIGNED NOT NULL DEFAULT 0,
		forecast_cursor    INT UNSIGNED NOT NULL DEFAULT 0,
		last_report_run    INT UNSIGNED NOT NULL DEFAULT 0,
		PRIMARY KEY (id)
	) $charset");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_ds_exclusions (
		datasource  VARCHAR(64) NOT NULL DEFAULT '',
		created_at  DATETIME NOT NULL,
		PRIMARY KEY (datasource)
	) $charset");

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
	) $charset");

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
	) $charset");

	db_execute("CREATE TABLE IF NOT EXISTS plugin_cereus_insights_sigma_overrides (
		local_data_id INT UNSIGNED NOT NULL DEFAULT 0,
		datasource    VARCHAR(64) NOT NULL DEFAULT '',
		sigma         FLOAT NOT NULL DEFAULT 3,
		updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (local_data_id, datasource)
	) $charset");
}

/* -------------------------------------------------------------------------
 * Navigation Hook
 * ---------------------------------------------------------------------- */

function cereus_insights_draw_navigation($nav) {
	$nav['cereus_insights.php:'] = array(
		'title'   => __('Anomaly Breaches', 'cereus_insights'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_insights.php',
		'level'   => '1',
	);
	$nav['cereus_insights_forecasts.php:'] = array(
		'title'   => __('Capacity Forecasts', 'cereus_insights'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_insights_forecasts.php',
		'level'   => '1',
	);
	$nav['cereus_insights_summaries.php:'] = array(
		'title'   => __('AI Alert Summaries', 'cereus_insights'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_insights_summaries.php',
		'level'   => '1',
	);
	$nav['cereus_insights_thsuggestions.php:'] = array(
		'title'   => __('Threshold Suggestions', 'cereus_insights'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_insights_thsuggestions.php',
		'level'   => '1',
	);
	$nav['cereus_insights_help.php:'] = array(
		'title'   => __('Cereus Insights Help', 'cereus_insights'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_insights_help.php',
		'level'   => '1',
	);
	$nav['cereus_insights_reports.php:'] = array(
		'title'   => __('Weekly Reports', 'cereus_insights'),
		'mapping' => 'index.php:',
		'url'     => 'cereus_insights_reports.php',
		'level'   => '1',
	);

	return $nav;
}

/* -------------------------------------------------------------------------
 * Page Head Hook — inject API key test button on settings tab
 * ---------------------------------------------------------------------- */

function cereus_insights_page_head() {
	global $config;
	?>
	<script type='text/javascript'>
	(function() {
		var _apiTestUrl = '<?php print $config['url_path']; ?>plugins/cereus_insights/cereus_insights_api_test.php';

		function cereus_insights_init_api_test() {
			var $keyInput = $('#cereus_insights_llm_api_key');
			if (!$keyInput.length) return;
			if ($('#cereus_insights_api_test_btn').length) return;

			var $btn    = $('<input id="cereus_insights_api_test_btn" type="button" class="ui-button ui-corner-all ui-widget" style="margin-left:6px;">').val('<?php print __esc('Test API Key', 'cereus_insights'); ?>');
			var $result = $('<span id="cereus_insights_api_test_result" style="margin-left:8px;font-weight:bold;"></span>');
			$keyInput.after($result).after($btn);

			$btn.on('click', function() {
				var key      = $keyInput.val();
				var model    = $('#cereus_insights_llm_model').val() || '';
				var provider = $('#cereus_insights_llm_provider').val() || '';
				var data     = { api_key: key, model: model, provider: provider };

				/* Explicitly include Cacti's CSRF token so csrf-magic does not reject
				   the POST and trigger session_regenerate_id(), which would invalidate
				   the settings form's embedded token and break the next Save. */
				if (typeof csrfMagicName !== 'undefined' && typeof csrfMagicToken !== 'undefined') {
					data[csrfMagicName] = csrfMagicToken;
				}

				$result.html('<span style="color:#888;"><?php print __esc('Testing…', 'cereus_insights'); ?></span>');
				$btn.prop('disabled', true);

				$.ajax({
					url:      _apiTestUrl,
					type:     'POST',
					data:     data,
					dataType: 'json',
					timeout:  20000
				}).done(function(d) {
					if (d.ok) {
						$result.html('<span style="color:#27ae60;">&#10003; <?php print __esc('Valid', 'cereus_insights'); ?> &mdash; ' + $('<span>').text(d.model).html() + '</span>');
					} else {
						$result.html('<span style="color:#c0392b;">&#10007; ' + $('<span>').text(d.error || '<?php print __esc('Unknown error', 'cereus_insights'); ?>').html() + '</span>');
					}
				}).fail(function() {
					$result.html('<span style="color:#c0392b;">&#10007; <?php print __esc('Request failed', 'cereus_insights'); ?></span>');
				}).always(function() {
					$btn.prop('disabled', false);
					setTimeout(function() { $result.html(''); }, 10000);
				});
			});
		}

		/* MutationObserver re-fires on every Cacti AJAX navigation — unlike
		   $(document).ready which only fires once on the initial page load. */
		new MutationObserver(function() {
			cereus_insights_init_api_test();
		}).observe(document.documentElement, { childList: true, subtree: true });

		if (document.readyState !== 'loading') {
			cereus_insights_init_api_test();
		}
	})();
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Poller Hook
 * ---------------------------------------------------------------------- */

function cereus_insights_poller_bottom() {
	global $config;

	if (isset($config['poller_id']) && $config['poller_id'] != 1) {
		return;
	}

	include_once($config['base_path'] . '/plugins/cereus_insights/poller_cereus_insights.php');

	cereus_insights_run_poller(false);
}

function cereus_insights_boost_poller_bottom() {
	global $config;

	if (isset($config['poller_id']) && $config['poller_id'] != 1) {
		return;
	}

	include_once($config['base_path'] . '/plugins/cereus_insights/poller_cereus_insights.php');

	cereus_insights_run_poller(true);
}
