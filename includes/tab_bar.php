<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Shared Tab Bar                                        |
 +-------------------------------------------------------------------------+
*/

function cereus_insights_tab_bar(string $active): void {
	global $config;

	$tabs = array(
		'forecasts' => array(
			'url'   => $config['url_path'] . 'plugins/cereus_insights/cereus_insights_forecasts.php',
			'label' => __('Capacity Forecasts', 'cereus_insights'),
		),
		'breaches' => array(
			'url'   => $config['url_path'] . 'plugins/cereus_insights/cereus_insights.php',
			'label' => __('Anomaly Breaches', 'cereus_insights'),
		),
		'suggestions' => array(
			'url'   => $config['url_path'] . 'plugins/cereus_insights/cereus_insights_thsuggestions.php',
			'label' => __('Threshold Suggestions', 'cereus_insights'),
		),
		'summaries' => array(
			'url'   => $config['url_path'] . 'plugins/cereus_insights/cereus_insights_summaries.php',
			'label' => __('AI Summaries', 'cereus_insights'),
		),
		'help' => array(
			'url'   => $config['url_path'] . 'plugins/cereus_insights/cereus_insights_help.php',
			'label' => __('Help', 'cereus_insights'),
		),
	);

	print '<div class="tabs" id="cereus-insights-tabs" style="margin-bottom:8px;">' . PHP_EOL;
	print '<nav><ul>' . PHP_EOL;

	foreach ($tabs as $key => $tab) {
		$class = ($key === $active) ? 'selected' : '';
		print '<li class="' . $class . '"><a href="' . html_escape($tab['url']) . '">'
		    . html_escape($tab['label']) . '</a></li>' . PHP_EOL;
	}

	print '</ul></nav>' . PHP_EOL;
	print '</div>' . PHP_EOL;
}
