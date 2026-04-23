<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Config Arrays (menu, realms)                          |
 +-------------------------------------------------------------------------+
*/

function cereus_insights_config_arrays() {
	global $user_auth_realm_filenames, $menu;

	/* Map realm IDs to filenames — single query for all cereus_insights realms */
	$realm_rows = db_fetch_assoc(
		"SELECT id + 100 AS realm_id, file FROM plugin_realms WHERE plugin = 'cereus_insights'"
	);

	$realm_breaches    = 0;
	$realm_forecasts   = 0;
	$realm_summaries   = 0;
	$realm_suggestions = 0;
	$realm_reports     = 0;

	if (cacti_sizeof($realm_rows)) {
		foreach ($realm_rows as $r) {
			$f = $r['file'];
			if (strpos($f, 'cereus_insights_forecasts.php') !== false) {
				$realm_forecasts = (int) $r['realm_id'];
			} elseif (strpos($f, 'cereus_insights_reports.php') !== false) {
				$realm_reports = (int) $r['realm_id'];
			} elseif (strpos($f, 'cereus_insights_summaries.php') !== false) {
				$realm_summaries = (int) $r['realm_id'];
			} elseif (strpos($f, 'cereus_insights_thsuggestions.php') !== false) {
				$realm_suggestions = (int) $r['realm_id'];
			} elseif (strpos($f, 'cereus_insights.php') !== false) {
				$realm_breaches = (int) $r['realm_id'];
			}
		}
	}

	if ($realm_breaches) {
		$user_auth_realm_filenames['cereus_insights.php']          = $realm_breaches;
		$user_auth_realm_filenames['cereus_insights_help.php']     = $realm_breaches;
		$user_auth_realm_filenames['cereus_insights_api_test.php'] = $realm_breaches;
	}
	if ($realm_forecasts) {
		$user_auth_realm_filenames['cereus_insights_forecasts.php'] = $realm_forecasts;
	}
	if ($realm_summaries) {
		$user_auth_realm_filenames['cereus_insights_summaries.php'] = $realm_summaries;
	}
	if ($realm_suggestions) {
		$user_auth_realm_filenames['cereus_insights_thsuggestions.php'] = $realm_suggestions;
		$user_auth_realm_filenames['cereus_insights_suggest_ajax.php']  = $realm_suggestions;
	}
	if ($realm_reports) {
		$user_auth_realm_filenames['cereus_insights_reports.php'] = $realm_reports;
	}

	/* Add menu items under Cereus Tools */
	$menu[__('Cereus Tools')]['plugins/cereus_insights/cereus_insights_forecasts.php'] =
		__('Insights &mdash; Forecasts', 'cereus_insights');
	$menu[__('Cereus Tools')]['plugins/cereus_insights/cereus_insights.php'] =
		__('Insights &mdash; Anomaly Breaches', 'cereus_insights');
	$menu[__('Cereus Tools')]['plugins/cereus_insights/cereus_insights_summaries.php'] =
		__('Insights &mdash; AI Summaries', 'cereus_insights');
	$menu[__('Cereus Tools')]['plugins/cereus_insights/cereus_insights_reports.php'] =
		__('Insights &mdash; Weekly Reports', 'cereus_insights');
}
