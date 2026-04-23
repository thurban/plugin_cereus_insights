<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - AI Alert Summaries Viewer                             |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cereus_insights/includes/constants.php');
include_once('./plugins/cereus_insights/lib/license_check.php');
include_once('./plugins/cereus_insights/includes/tab_bar.php');
include_once('./plugins/cereus_insights/includes/stats.php');

top_header();
cereus_insights_summaries_list();
bottom_footer();

/* =========================================================================
 * Summaries List
 * ====================================================================== */

function cereus_insights_summaries_list() {
	global $config;

	cereus_insights_tab_bar('summaries');

	if (!cereus_insights_tables_installed()) {
		html_start_box('', '100%', '', '3', 'center', '');
		print '<tr><td class="center" style="padding:20px;color:#888;">'
		    . __('Plugin tables are being created — please wait for the next poller cycle, then reload.', 'cereus_insights')
		    . '</td></tr>';
		html_end_box();
		return;
	}

	html_start_box(__('Processing Status', 'cereus_insights'), '100%', '', '3', 'center', '');
	print '<tr><td style="padding:12px 8px 8px;">';
	cereus_insights_stats_box();
	print '</td></tr>';
	html_end_box();

	/* ---- license gate ---- */
	if (!cereus_insights_has_llm()) {
		html_start_box(__('AI Alert Summaries', 'cereus_insights'), '100%', '', '3', 'center', '');
		print '<tr><td class="textArea center">'
		    . '<p>' . __('AI alert summarization requires an Enterprise Cereus Insights license.', 'cereus_insights') . '</p>'
		    . '<p><a href="https://www.urban-software.com" target="_blank">'
		    . __('Upgrade your license', 'cereus_insights') . '</a></p>'
		    . '</td></tr>';
		html_end_box();
		return;
	}

	/* ---- filter persistence ---- */
	if (isset_request_var('clear')) {
		kill_session_var('sess_cis_date_from');
		kill_session_var('sess_cis_date_to');
		kill_session_var('sess_cis_rows');
		kill_session_var('sess_cis_page');
		unset_request_var('date_from');
		unset_request_var('date_to');
		unset_request_var('rows');
		unset_request_var('page');
	}

	load_current_session_value('date_from', 'sess_cis_date_from', date('Y-m-d', strtotime('-7 days')));
	load_current_session_value('date_to',   'sess_cis_date_to',   date('Y-m-d'));
	load_current_session_value('rows',      'sess_cis_rows',      '-1');
	load_current_session_value('page',      'sess_cis_page',      '1');

	$date_from = get_request_var('date_from');
	$date_to   = get_request_var('date_to');
	$rows      = (int)  get_request_var('rows');
	$page      = max(1, (int) get_request_var('page'));

	if ($rows <= 0) {
		$rows = read_config_option('num_rows_table');
	}
	$rows = max(1, (int) $rows);

	/* ---- query ---- */
	$sql_where  = "WHERE DATE(s.created_at) BETWEEN ? AND ?";
	$sql_params = array($date_from, $date_to);

	$total_rows = db_fetch_cell_prepared(
		"SELECT COUNT(*) FROM plugin_cereus_insights_summaries s $sql_where",
		$sql_params
	);

	$offset   = ($page - 1) * $rows;
	$page_sql = " ORDER BY s.created_at DESC LIMIT $rows OFFSET $offset";

	$summaries = db_fetch_assoc_prepared(
		"SELECT s.* FROM plugin_cereus_insights_summaries s $sql_where $page_sql",
		$sql_params
	);

	/* ---- render ---- */
	$nav = html_nav_bar('cereus_insights_summaries.php', MAX_DISPLAY_PAGES, $page, $rows, $total_rows, 5, __('AI Alert Summaries', 'cereus_insights'), 'page', 'main');

	/* Filter box */
	html_start_box(__('Summary Filters', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr class="even noprint">
		<td>
		<form id="form_cis" method="post" action="cereus_insights_summaries.php">
		<table class="filterTable">
			<tr>
				<td><?php print __('From', 'cereus_insights'); ?></td>
				<td><input type="date" id="date_from" name="date_from" value="<?php print html_escape($date_from); ?>"></td>
				<td><?php print __('To', 'cereus_insights'); ?></td>
				<td><input type="date" id="date_to" name="date_to" value="<?php print html_escape($date_to); ?>"></td>
				<td><?php print __('Rows', 'cereus_insights'); ?></td>
				<td>
					<select id="rows" name="rows">
						<?php
						foreach (array(10, 25, 50, 100, -1) as $r) {
							$label = $r == -1 ? __('Default', 'cereus_insights') : $r;
							$sel   = $rows == $r ? 'selected' : '';
							print "<option value=\"$r\" $sel>$label</option>";
						}
						?>
					</select>
				</td>
				<td>
					<span>
						<input type="submit" id="go" value="<?php print __esc('Go', 'cereus_insights'); ?>">
						<input type="button" id="clear" value="<?php print __esc('Clear', 'cereus_insights'); ?>" onClick="clearSummaryFilter()">
					</span>
				</td>
			</tr>
		</table>
		</form>
		</td>
	</tr>
	<?php
	html_end_box();

	/* Table */
	print $nav;

	$columns = array(
		array('display' => __('Time', 'cereus_insights'),          'align' => 'left',   'sort' => 'ASC'),
		array('display' => __('Alerts', 'cereus_insights'),        'align' => 'center', 'sort' => 'ASC'),
		array('display' => __('Summary', 'cereus_insights'),       'align' => 'left',   'sort' => 'ASC'),
		array('display' => __('Model', 'cereus_insights'),         'align' => 'left',   'sort' => 'ASC'),
		array('display' => __('Tokens', 'cereus_insights'),        'align' => 'right',  'sort' => 'ASC'),
		array('display' => __('Detail', 'cereus_insights'),        'align' => 'center', 'sort' => 'ASC'),
	);

	html_start_box('', '100%', '', '3', 'center', '');
	html_header($columns);

	if (cacti_sizeof($summaries)) {
		foreach ($summaries as $row) {
			$summary_short = strlen($row['summary']) > 200
				? html_escape(substr($row['summary'], 0, 200)) . '&hellip;'
				: html_escape($row['summary']);

			form_alternate_row('sum_' . $row['id'], true);
			?>
			<td><?php print html_escape($row['created_at']); ?></td>
			<td class="center"><?php print (int) $row['alert_count']; ?></td>
			<td><?php print $summary_short; ?></td>
			<td><?php print html_escape($row['model']); ?></td>
			<td class="right"><?php print number_format((int) $row['tokens_used']); ?></td>
			<td class="center">
				<button type="button" class="cis-expand-btn" data-id="<?php print (int)$row['id']; ?>">
					<?php print __('Expand', 'cereus_insights'); ?>
				</button>
			</td>
			<?php
			form_end_row();

			/* Hidden detail row */
			$raw_decoded = json_decode($row['raw_alerts'], true);
			$raw_pretty  = is_array($raw_decoded)
				? htmlspecialchars(json_encode($raw_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_HTML5, 'UTF-8')
				: html_escape($row['raw_alerts']);

			print '<tr id="cis-detail-' . (int)$row['id'] . '" class="cis-detail-row" style="display:none;">';
			print '<td colspan="6">';
			print '<div style="padding:10px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;">';
			print '<strong>' . __('Full Summary:', 'cereus_insights') . '</strong>';
			print '<p>' . nl2br(html_escape($row['summary'])) . '</p>';
			print '<strong>' . __('Raw Alert Data:', 'cereus_insights') . '</strong>';
			print '<pre style="max-height:300px;overflow:auto;font-size:11px;background:#fff;border:1px solid #ccc;padding:8px;">';
			print $raw_pretty;
			print '</pre>';
			print '</div>';
			print '</td>';
			print '</tr>';
		}
	} else {
		print '<tr><td colspan="6" class="center">'
		    . __('No LLM summaries found for the selected date range.', 'cereus_insights')
		    . '</td></tr>';
	}

	html_end_box();
	print $nav;

	?>
	<script type="text/javascript">
	function clearSummaryFilter() {
		var today  = new Date().toISOString().split('T')[0];
		var week   = new Date(Date.now() - 7*86400000).toISOString().split('T')[0];
		$('#date_from').val(week);
		$('#date_to').val(today);
		$('#rows').val('-1');
		$('#form_cis').append('<input type="hidden" name="clear" value="1">');
		$('#form_cis').submit();
	}

	/* Use namespace so navigating back doesn't stack duplicate handlers */
	$(document).off('click.cis_expand').on('click.cis_expand', '.cis-expand-btn', function() {
		var id      = $(this).data('id');
		var $detail = $('#cis-detail-' + id);
		$detail.toggle();
		$(this).text($detail.is(':visible')
			? '<?php print __esc('Collapse', 'cereus_insights'); ?>'
			: '<?php print __esc('Expand',   'cereus_insights'); ?>');
	});
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Tab Bar
 * ---------------------------------------------------------------------- */

