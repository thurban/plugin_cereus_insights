<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Weekly Intelligence Report                            |
 +-------------------------------------------------------------------------+
*/

function cereus_insights_check_weekly_report(int $now, int $last_report_run): bool {
	if (read_config_option('cereus_insights_report_enabled') !== 'on') return false;
	if (!cereus_insights_license_at_least('enterprise')) return false;
	if (($now - $last_report_run) < 72000) return false;
	$cfg_dow  = (int)(read_config_option('cereus_insights_report_dow')  ?: 1);
	$cfg_hour = (int)(read_config_option('cereus_insights_report_hour') ?: 6);
	return ((int)date('w', $now) === $cfg_dow && (int)date('G', $now) === $cfg_hour);
}

function cereus_insights_generate_weekly_report(): void {
	$now          = time();
	$period_end   = date('Y-m-d H:i:s', $now);
	$period_start = date('Y-m-d H:i:s', $now - 604800);
	$top_n        = max(1, (int)(read_config_option('cereus_insights_report_items') ?: 5));

	/* Gather data */
	$anomaly_7d = db_fetch_row(
		"SELECT COUNT(*) AS total,
		        COUNT(DISTINCT host_id) AS devices
		 FROM plugin_cereus_insights_breaches
		 WHERE breached_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
	);

	$top_devices = db_fetch_assoc_prepared(
		"SELECT COALESCE(h.description, h.hostname, b.host_description) AS device,
		        COUNT(*) AS cnt
		 FROM plugin_cereus_insights_breaches b
		 LEFT JOIN host h ON h.id = b.host_id
		 WHERE b.breached_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
		 GROUP BY b.host_id
		 ORDER BY cnt DESC
		 LIMIT ?",
		array($top_n)
	);

	$top_forecasts = db_fetch_assoc_prepared(
		"SELECT name_cache, datasource, forecast_days, r_squared
		 FROM plugin_cereus_insights_forecasts
		 WHERE forecast_days IS NOT NULL AND forecast_days > 0
		 ORDER BY forecast_days ASC
		 LIMIT ?",
		array($top_n)
	);

	$sev = db_fetch_row(
		"SELECT SUM(forecast_days < 7) AS critical,
		        SUM(forecast_days >= 7 AND forecast_days < 30) AS warning,
		        SUM(forecast_days >= 30 AND forecast_days < 90) AS watch
		 FROM plugin_cereus_insights_forecasts
		 WHERE forecast_days IS NOT NULL"
	);

	$eligible = (int) db_fetch_cell(
		"SELECT COUNT(*) FROM plugin_cereus_insights_suggest_cache sc
		 WHERE NOT EXISTS (SELECT 1 FROM thold_data td WHERE td.local_data_id = sc.local_data_id AND td.data_source_name = sc.datasource)
		 AND NOT EXISTS (SELECT 1 FROM plugin_cereus_insights_ds_exclusions ex WHERE ex.datasource = sc.datasource)"
	);
	$high_conf = (int) db_fetch_cell(
		"SELECT COUNT(*) FROM plugin_cereus_insights_suggest_cache sc
		 WHERE conf_pct >= 75
		 AND NOT EXISTS (SELECT 1 FROM thold_data td WHERE td.local_data_id = sc.local_data_id AND td.data_source_name = sc.datasource)
		 AND NOT EXISTS (SELECT 1 FROM plugin_cereus_insights_ds_exclusions ex WHERE ex.datasource = sc.datasource)"
	);

	$payload = array(
		'period'            => $period_start . ' to ' . $period_end,
		'anomalies_7d'      => (int)($anomaly_7d['total'] ?? 0),
		'anomalous_devices' => (int)($anomaly_7d['devices'] ?? 0),
		'top_anomalous_devices' => $top_devices ?: array(),
		'capacity_critical' => (int)($sev['critical'] ?? 0),
		'capacity_warning'  => (int)($sev['warning']  ?? 0),
		'capacity_watch'    => (int)($sev['watch']    ?? 0),
		'top_capacity_concerns' => $top_forecasts ?: array(),
		'unconfigured_datasources' => $eligible,
		'high_confidence_suggestions' => $high_conf,
	);

	$provider = read_config_option('cereus_insights_llm_provider') ?: CEREUS_INSIGHTS_DEFAULT_LLM_PROVIDER;
	$model    = read_config_option('cereus_insights_llm_model')    ?: cereus_insights_default_model($provider);
	$api_key  = read_config_option('cereus_insights_llm_api_key');

	if (empty($api_key)) {
		cacti_log('CEREUS INSIGHTS: Weekly report skipped — no API key', false, 'SYSTEM');
		return;
	}

	$system = 'You are an infrastructure monitoring assistant generating a weekly report for a network operations team. '
	        . 'You will receive JSON data from a Cacti monitoring system covering the past 7 days. '
	        . 'Write a concise weekly intelligence report with three sections: '
	        . '1. Capacity Concerns — top items approaching saturation. '
	        . '2. Anomaly Highlights — devices with unusual activity. '
	        . '3. Action Items — concrete recommendations. '
	        . 'Keep each section to 2-4 sentences. Be specific with names and numbers. Use plain text only.';

	$raw = cereus_insights_llm_dispatch($provider, $api_key, $model, $system,
		json_encode($payload, JSON_UNESCAPED_UNICODE), 600);

	if (!$raw['ok']) {
		cacti_log('CEREUS INSIGHTS: Weekly report LLM failed — ' . ($raw['error'] ?? 'unknown'), false, 'SYSTEM');
		return;
	}

	$subject = 'Cereus Insights: Weekly Report — '
	         . date('M j', strtotime($period_start)) . ' to ' . date('M j Y', $now);

	db_execute_prepared(
		"INSERT INTO plugin_cereus_insights_reports
		     (generated_at, period_start, period_end, subject, report_text, model, tokens_used)
		 VALUES (NOW(), ?, ?, ?, ?, ?, ?)",
		array($period_start, $period_end, $subject, $raw['text'], $raw['model'], $raw['tokens_used'])
	);

	cereus_insights_email_report($raw['text'], $subject, $payload);
}

function cereus_insights_email_report(string $report_text, string $subject, array $payload): void {
	if (!function_exists('mailer')) return;

	$notify_email = read_config_option('cereus_insights_report_email');
	if (empty($notify_email)) $notify_email = read_config_option('settings_from_email');
	if (empty($notify_email)) return;

	$from_email = read_config_option('settings_from_email');
	$from_name  = read_config_option('settings_from_name') ?: 'Cereus Insights';

	$stats_html = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:12px;">'
		. '<tr><td style="color:#666;padding:2px 8px;">Anomalies (7d)</td><td style="font-weight:bold;">' . (int)($payload['anomalies_7d'] ?? 0) . '</td>'
		. '<td style="color:#666;padding:2px 8px;">Devices affected</td><td style="font-weight:bold;">' . (int)($payload['anomalous_devices'] ?? 0) . '</td></tr>'
		. '<tr><td style="color:#666;padding:2px 8px;">Capacity critical (&lt;7d)</td><td style="font-weight:bold;color:#e74c3c;">' . (int)($payload['capacity_critical'] ?? 0) . '</td>'
		. '<td style="color:#666;padding:2px 8px;">Unthresholded datasources</td><td style="font-weight:bold;">' . (int)($payload['unconfigured_datasources'] ?? 0) . '</td></tr>'
		. '</table>';

	$body_html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;margin:0;padding:20px;">'
		. '<div style="max-width:700px;margin:0 auto;">'
		. '<div style="background:#2e86c1;color:#fff;padding:16px 20px;border-radius:4px 4px 0 0;"><strong>' . html_escape($subject) . '</strong></div>'
		. '<div style="border:1px solid #ddd;border-top:none;padding:20px;border-radius:0 0 4px 4px;">'
		. '<p style="line-height:1.7;white-space:pre-wrap;">' . nl2br(html_escape($report_text)) . '</p>'
		. $stats_html
		. '</div></div></body></html>';

	$body_text = $subject . "\n\n" . $report_text;

	mailer(array($from_email, $from_name), $notify_email, '', '', '', $subject, $body_html, $body_text, '', '', true);
}
