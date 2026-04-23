<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Weekly Intelligence Reports                           |
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

/* =========================================================================
 * Reports Page
 * ====================================================================== */

function cereus_insights_reports_page() {
	global $config;

	cereus_insights_tab_bar('reports');

	if (!cereus_insights_tables_installed()) {
		html_start_box('', '100%', '', '3', 'center', '');
		print '<tr><td class="center" style="padding:20px;color:#888;">'
		    . __('Plugin initializing — please reload this page.', 'cereus_insights')
		    . '</td></tr>';
		html_end_box();
		return;
	}

	/* ---- license gate ---- */
	if (!cereus_insights_license_at_least('enterprise')) {
		html_start_box(__('Weekly Intelligence Reports', 'cereus_insights'), '100%', '', '3', 'center', '');
		print '<tr><td class="textArea center" style="padding:20px;">'
		    . '<p>' . __('Weekly Intelligence Reports require an Enterprise license.', 'cereus_insights') . '</p>'
		    . '<p><a href="https://www.urban-software.com" target="_blank">' . __('Upgrade your license', 'cereus_insights') . '</a></p>'
		    . '</td></tr>';
		html_end_box();
		return;
	}

	$reports = db_fetch_assoc("SELECT * FROM plugin_cereus_insights_reports ORDER BY generated_at DESC LIMIT 52");

	html_start_box(__('Weekly Intelligence Reports', 'cereus_insights'), '100%', '', '3', 'center', '');

	if (!cacti_sizeof($reports)) {
		?>
		<tr><td style="padding:20px;">
			<p style="margin:0 0 8px;"><?php print __('No reports have been generated yet.', 'cereus_insights'); ?></p>
			<p style="margin:0;color:#666;font-size:13px;"><?php print __('Reports are generated automatically on the configured day and hour. To enable: go to <strong>Settings &rarr; Cereus Insights</strong>, ensure <em>Enable LLM Alert Summarization</em> is checked, and configure the <em>Weekly Intelligence Report</em> schedule.', 'cereus_insights'); ?></p>
		</td></tr>
		<?php
	} else {
		$columns = array(
			array('display' => __('Generated',     'cereus_insights'), 'align' => 'left'),
			array('display' => __('Period',         'cereus_insights'), 'align' => 'left'),
			array('display' => __('Model',          'cereus_insights'), 'align' => 'left'),
			array('display' => __('Tokens',         'cereus_insights'), 'align' => 'right'),
			array('display' => __('',               'cereus_insights'), 'align' => 'center'),
		);
		html_header($columns);

		$odd = true;
		foreach ($reports as $r) {
			$rid      = (int) $r['id'];
			$row_class = $odd ? 'odd' : 'even';
			$odd = !$odd;

			$period = date('M j', strtotime($r['period_start']))
			        . ' &ndash; '
			        . date('M j, Y', strtotime($r['period_end']));

			$model_short = html_escape(strlen($r['model']) > 30 ? substr($r['model'], 0, 28) . '…' : $r['model']);
			?>
			<tr id="report-row-<?php print $rid; ?>" class="tableRow <?php print $row_class; ?>">
				<td style="padding:8px 12px;">
					<strong><?php print html_escape(date('D M j, Y H:i', strtotime($r['generated_at']))); ?></strong>
				</td>
				<td style="color:#555;"><?php print $period; ?></td>
				<td style="color:#555;font-size:12px;"><?php print $model_short; ?></td>
				<td class="right" style="color:#555;font-size:12px;"><?php print number_format((int)$r['tokens_used']); ?></td>
				<td class="center">
					<input type="button"
						class="report-toggle-btn ui-button ui-corner-all ui-widget"
						data-rid="<?php print $rid; ?>"
						value="<?php print __esc('View Report', 'cereus_insights'); ?>"
						style="min-width:100px;">
				</td>
			</tr>
			<tr id="report-body-<?php print $rid; ?>" style="display:none;">
				<td colspan="5" style="padding:0;">
					<div style="margin:0 8px 12px;padding:16px 20px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;">
						<div style="font-size:12px;color:#888;margin-bottom:10px;border-bottom:1px solid #eee;padding-bottom:6px;">
							<?php print html_escape($r['subject']); ?>
						</div>
						<div style="white-space:pre-wrap;font-size:13px;line-height:1.75;color:#333;"><?php print html_escape($r['report_text']); ?></div>
					</div>
				</td>
			</tr>
			<?php
		}
	}

	html_end_box();
	?>
	<script type="text/javascript">
	$(document).off('click.report_toggle').on('click.report_toggle', '.report-toggle-btn', function() {
		var rid   = $(this).data('rid');
		var $body = $('#report-body-' + rid);
		var open  = $body.is(':visible');
		$body.toggle(!open);
		$(this).val(open ? <?php print json_encode(__('View Report',  'cereus_insights')); ?>
		                 : <?php print json_encode(__('Hide Report',  'cereus_insights')); ?>);
	});
	</script>
	<?php
}
