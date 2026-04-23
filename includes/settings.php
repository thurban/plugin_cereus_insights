<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Settings Tab                                          |
 +-------------------------------------------------------------------------+
*/

function cereus_insights_config_settings() {
	global $tabs, $settings;

	include_once(__DIR__ . '/constants.php');
	include_once(__DIR__ . '/../lib/license_check.php');

	$tier       = cereus_insights_license_tier();
	$tier_label = ucfirst($tier) . ' ' . __('License', 'cereus_insights');

	$tabs['cereus_insights'] = __('Cereus Insights', 'cereus_insights');

	$settings['cereus_insights'] = array(

		/* ---- General ---- */
		'cereus_insights_general_header' => array(
			'friendly_name' => __('Cereus Insights - General', 'cereus_insights') . ' [' . $tier_label . ']',
			'method'        => 'spacer',
		),
		'cereus_insights_batch_size' => array(
			'friendly_name' => __('Batch Size', 'cereus_insights'),
			'description'   => __('Number of datasources to process per poller cycle for baselines and forecasts.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_BATCH_SIZE,
			'max_length'    => 5,
			'size'          => 5,
		),
		'cereus_insights_breach_retention' => array(
			'friendly_name' => __('Anomaly Breach Retention (days)', 'cereus_insights'),
			'description'   => __('Number of days to keep anomaly breach records.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_BREACH_RET,
			'max_length'    => 5,
			'size'          => 5,
		),
		'cereus_insights_summary_retention' => array(
			'friendly_name' => __('LLM Summary Retention (days)', 'cereus_insights'),
			'description'   => __('Number of days to keep LLM alert summary records.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_SUMMARY_RET,
			'max_length'    => 5,
			'size'          => 5,
		),

		/* ---- Anomaly Detection (Professional+) ---- */
		'cereus_insights_anomaly_header' => array(
			'friendly_name' => __('Anomaly Detection', 'cereus_insights')
				. ($tier === 'community' ? ' &mdash; ' . __('Professional or higher required', 'cereus_insights') : ''),
			'method'        => 'spacer',
		),
		'cereus_insights_sigma' => array(
			'friendly_name' => __('Sigma Threshold', 'cereus_insights'),
			'description'   => __('Standard deviation multiplier for anomaly detection. Lower values are more sensitive. Default: 3.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_SIGMA,
			'max_length'    => 5,
			'size'          => 5,
		),
		'cereus_insights_min_samples' => array(
			'friendly_name' => __('Minimum Samples per Bucket', 'cereus_insights'),
			'description'   => __('Minimum number of data points required in a baseline bucket before anomaly detection activates. Default: 50.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_MIN_SAMPLES,
			'max_length'    => 5,
			'size'          => 5,
		),
		'cereus_insights_baseline_days' => array(
			'friendly_name' => __('Baseline History (days)', 'cereus_insights'),
			'description'   => __('Days of RRD history to use when building seasonality baselines. Default: 30.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_BASELINE_DAYS,
			'max_length'    => 5,
			'size'          => 5,
		),

		/* ---- Capacity Forecasting (Community+) ---- */
		'cereus_insights_forecast_header' => array(
			'friendly_name' => __('Capacity Forecasting', 'cereus_insights'),
			'method'        => 'spacer',
		),
		'cereus_insights_baseline_interval' => array(
			'friendly_name' => __('Baseline Run Interval (seconds)', 'cereus_insights'),
			'description'   => __('How often the baseline batch runs. 300 = every poller cycle (default). Increase once the initial backlog is cleared to reduce CPU load.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_BASELINE_INT,
			'max_length'    => 6,
			'size'          => 6,
		),
		'cereus_insights_forecast_interval' => array(
			'friendly_name' => __('Forecast Run Interval (seconds)', 'cereus_insights'),
			'description'   => __('How often the forecast batch runs. 3600 = hourly (default). Increase once the initial backlog is cleared to reduce CPU load.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_FORECAST_INT,
			'max_length'    => 6,
			'size'          => 6,
		),
		'cereus_insights_forecast_days' => array(
			'friendly_name' => __('Forecast History (days)', 'cereus_insights'),
			'description'   => __('Days of RRD history to use for linear regression. Default: 90. Professional+ supports longer horizons.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_FORECAST_DAYS,
			'max_length'    => 5,
			'size'          => 5,
		),
		'cereus_insights_forecast_hi_pct' => array(
			'friendly_name' => __('Saturation Threshold (%)', 'cereus_insights'),
			'description'   => __('Percentage of the thold_hi value to use as the saturation point for forecasting. Default: 90.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_FORECAST_HI_PCT,
			'max_length'    => 3,
			'size'          => 4,
		),
		'cereus_insights_forecast_warn_days' => array(
			'friendly_name' => __('Forecast Warning Threshold (days)', 'cereus_insights'),
			'description'   => __('Warn when projected saturation is within this many days. Default: 30.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_WARN_DAYS,
			'max_length'    => 5,
			'size'          => 5,
		),

		/* ---- Threshold Suggestions (Professional+) ---- */
		'cereus_insights_suggestions_header' => array(
			'friendly_name' => __('Threshold Suggestions', 'cereus_insights')
				. ($tier === 'community' ? ' &mdash; ' . __('Professional or higher required', 'cereus_insights') : ''),
			'method'        => 'spacer',
		),
		'cereus_insights_conf_min_samples' => array(
			'friendly_name' => __('Confidence Min Samples per Bucket', 'cereus_insights'),
			'description'   => __('A suggestion is "confident" when this many data points exist for a given (hour-of-day × day-of-week) time bucket. Confidence % = fraction of buckets meeting this threshold. With a 30-day baseline at 2-hour RRD resolution each bucket accumulates ~4–5 points; with 60 days ~8–10 points. Raise this value to require more history before trusting a suggestion. Default: 3.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_CONF_MIN_SAMPLES,
			'max_length'    => 3,
			'size'          => 4,
		),

		/* ---- LLM Summarization (Enterprise) ---- */
		'cereus_insights_llm_header' => array(
			'friendly_name' => __('LLM Alert Summarization', 'cereus_insights')
				. ($tier !== 'enterprise' ? ' &mdash; ' . __('Enterprise license required', 'cereus_insights') : ''),
			'method'        => 'spacer',
		),
		'cereus_insights_llm_provider' => array(
			'friendly_name' => __('LLM Provider', 'cereus_insights'),
			'description'   => __('Which LLM provider to use for alert summarization. Choose the provider that matches your API key.', 'cereus_insights'),
			'method'        => 'drop_array',
			'default'       => CEREUS_INSIGHTS_DEFAULT_LLM_PROVIDER,
			'array'         => array(
				'anthropic' => 'Anthropic (Claude)',
				'openai'    => 'OpenAI (GPT)',
				'google'    => 'Google (Gemini)',
			),
		),
		'cereus_insights_llm_api_key' => array(
			'friendly_name' => __('LLM API Key', 'cereus_insights'),
			'description'   => __('API key for the selected LLM provider (Enterprise only). Anthropic: starts with sk-ant-. OpenAI: starts with sk-. Google: AIza... Stored encrypted by Cacti.', 'cereus_insights'),
			'method'        => 'textbox_password',
			'default'       => '',
			'max_length'    => 200,
			'size'          => 60,
		),
		'cereus_insights_llm_model' => array(
			'friendly_name' => __('LLM Model', 'cereus_insights'),
			'description'   => __('Model name for alert summarization. Anthropic: claude-haiku-4-5-20251001 / claude-sonnet-4-6. OpenAI: gpt-4o-mini / gpt-4o. Google: gemini-1.5-flash / gemini-1.5-pro. Leave blank to use the provider default.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => '',
			'max_length'    => 80,
			'size'          => 40,
		),
		'cereus_insights_llm_alert_cooldown' => array(
			'friendly_name' => __('Alert Re-notify Cooldown (seconds)', 'cereus_insights'),
			'description'   => __('How long to suppress duplicate summaries for the same threshold in the same state. A threshold that stays breached will not re-appear in summaries until this cooldown expires. State changes (breach → restoral, restoral → breach) always notify immediately. Default: 3600 (1 hour).', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_LLM_COOLDOWN,
			'max_length'    => 6,
			'size'          => 6,
		),
		'cereus_insights_llm_batch_window' => array(
			'friendly_name' => __('LLM Batch Window (seconds)', 'cereus_insights'),
			'description'   => __('Seconds to accumulate alerts before sending to LLM. Default: 300 (5 minutes).', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => CEREUS_INSIGHTS_DEFAULT_LLM_BATCH_WIN,
			'max_length'    => 6,
			'size'          => 6,
		),
		'cereus_insights_llm_notify_email' => array(
			'friendly_name' => __('LLM Summary Notify Email', 'cereus_insights'),
			'description'   => __('Email address for LLM alert summaries. Leave blank to use the Cacti admin email.', 'cereus_insights'),
			'method'        => 'textbox',
			'default'       => '',
			'max_length'    => 255,
			'size'          => 40,
		),
	);
}
