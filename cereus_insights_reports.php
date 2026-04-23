<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Weekly Intelligence Reports Viewer                    |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cereus_insights/includes/constants.php');
include_once('./plugins/cereus_insights/lib/license_check.php');
include_once('./plugins/cereus_insights/includes/tab_bar.php');

top_header();
cereus_insights_reports_page();
bottom_footer();

function cereus_insights_reports_page() {
	global $config;
	cereus_insights_tab_bar('reports');

	if (!cereus_insights_license_at_least('enterprise')) {
		html_start_box(__('Weekly Intelligence Reports', 'cereus_insights'), '100%', '', '3', 'center', '');
		print '<tr><td class="textArea center">' . __('Weekly Reports require an Enterprise license.', 'cereus_insights') . '</td></tr>';
		html_end_box();
		return;
	}

	$reports = db_fetch_assoc("SELECT * FROM plugin_cereus_insights_reports ORDER BY generated_at DESC LIMIT 52");

	html_start_box(__('Weekly Intelligence Reports', 'cereus_insights'), '100%', '', '3', 'center', '');

	if (!cacti_sizeof($reports)) {
		print '<tr><td class="center" style="padding:16px;">'
			. __('No reports generated yet. Reports are sent on the configured day and hour when LLM Alert Summarization is enabled.', 'cereus_insights')
			. '</td></tr>';
	} else {
		foreach ($reports as $r) {
			$rid = (int)$r['id'];
			?>
			<tr class="tableRow odd">
				<td style="padding:8px 12px;">
					<strong><?php print html_escape($r['subject']); ?></strong>
					<span style="color:#888;font-size:11px;margin-left:8px;"><?php print html_escape($r['generated_at']); ?> &mdash; <?php print html_escape($r['model']); ?> &mdash; <?php print number_format((int)$r['tokens_used']); ?> tokens</span>
					<br>
					<div id="report-body-<?php print $rid; ?>" style="display:none;margin-top:8px;padding:10px 14px;background:#f9f9f9;border:1px solid #eee;border-radius:3px;white-space:pre-wrap;font-size:13px;line-height:1.6;"><?php print html_escape($r['report_text']); ?></div>
					<a href="#" onclick="document.getElementById('report-body-<?php print $rid; ?>').style.display=document.getElementById('report-body-<?php print $rid; ?>').style.display==='none'?'block':'none';return false;" style="font-size:12px;"><?php print __('Toggle', 'cereus_insights'); ?></a>
				</td>
			</tr>
			<?php
		}
	}
	html_end_box();
}
