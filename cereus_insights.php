<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Anomaly Breaches Viewer                               |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cereus_insights/includes/constants.php');
include_once('./plugins/cereus_insights/lib/license_check.php');
include_once('./plugins/cereus_insights/includes/tab_bar.php');
include_once('./plugins/cereus_insights/includes/stats.php');

$action = get_nfilter_request_var('action', '');

switch ($action) {
	case 'delete':
		cereus_insights_breach_delete();
		break;
	case 'actions':
		cereus_insights_breach_actions();
		break;
	default:
		top_header();
		cereus_insights_breach_list();
		bottom_footer();
		break;
}

/* =========================================================================
 * Bulk Actions
 * ====================================================================== */

function cereus_insights_breach_delete() {
	$id = get_filter_request_var('id');
	if ($id > 0) {
		db_execute_prepared("DELETE FROM plugin_cereus_insights_breaches WHERE id = ?", array($id));
	}
	header('Location: cereus_insights.php');
	exit;
}

function cereus_insights_breach_actions() {
	$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));
	$drp_action     = get_nfilter_request_var('drp_action', '');

	if ($selected_items !== false && $drp_action === '1') {
		foreach ($selected_items as $id) {
			db_execute_prepared("DELETE FROM plugin_cereus_insights_breaches WHERE id = ?", array((int) $id));
		}
	}

	header('Location: cereus_insights.php');
	exit;
}

/* =========================================================================
 * Breach List
 * ====================================================================== */

function cereus_insights_breach_list() {
	global $config;

	/* ---- license gate ---- */
	if (!cereus_insights_has_anomaly_detection()) {
		cereus_insights_tab_bar('breaches');
		html_start_box(__('Anomaly Breaches', 'cereus_insights'), '100%', '', '3', 'center', '');
		print '<tr><td class="textArea center">'
		    . '<p>' . __('Anomaly detection requires a Professional or higher Cereus Insights license.', 'cereus_insights') . '</p>'
		    . '<p><a href="https://www.urban-software.com" target="_blank">'
		    . __('Upgrade your license', 'cereus_insights') . '</a></p>'
		    . '</td></tr>';
		html_end_box();
		return;
	}

	/* ---- filter persistence ---- */
	if (isset_request_var('clear')) {
		kill_session_var('sess_cib_filter');
		kill_session_var('sess_cib_period');
		kill_session_var('sess_cib_zscore');
		kill_session_var('sess_cib_rows');
		kill_session_var('sess_cib_page');
		unset_request_var('filter');
		unset_request_var('period');
		unset_request_var('zscore');
		unset_request_var('rows');
		unset_request_var('page');
	}

	load_current_session_value('filter', 'sess_cib_filter', '');
	load_current_session_value('period', 'sess_cib_period', '86400');
	load_current_session_value('zscore', 'sess_cib_zscore', '0');
	load_current_session_value('rows',   'sess_cib_rows',   '-1');
	load_current_session_value('page',   'sess_cib_page',   '1');

	$filter = get_request_var('filter');
	$period = (int)   get_request_var('period');
	$zscore = (float) get_request_var('zscore');
	$rows   = (int)   get_request_var('rows');
	$page   = max(1,  (int) get_request_var('page'));

	if ($rows <= 0) {
		$rows = read_config_option('num_rows_table');
	}
	$rows = max(1, (int) $rows);

	/* ---- query ---- */
	$sql_where  = "WHERE b.breached_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";
	$sql_params = array($period);

	if (!empty($filter)) {
		$safe = str_replace(array('%','_'), array('\\%','\\_'), $filter);
		$sql_where  .= " AND (b.host_description LIKE ? OR b.name_cache LIKE ?)";
		$sql_params[] = '%' . $safe . '%';
		$sql_params[] = '%' . $safe . '%';
	}

	if ($zscore > 0) {
		$sql_where  .= " AND ABS(b.z_score) >= ?";
		$sql_params[] = $zscore;
	}

	$total_rows = db_fetch_cell_prepared(
		"SELECT COUNT(*) FROM plugin_cereus_insights_breaches b $sql_where",
		$sql_params
	);

	$offset   = ($page - 1) * $rows;
	$page_sql = " ORDER BY b.breached_at DESC LIMIT $rows OFFSET $offset";

	$breaches = db_fetch_assoc_prepared(
		"SELECT b.*
		 FROM plugin_cereus_insights_breaches b
		 $sql_where $page_sql",
		$sql_params
	);

	$sigma = (float) (read_config_option('cereus_insights_sigma') ?: CEREUS_INSIGHTS_DEFAULT_SIGMA);

	/* ---- render ---- */
	cereus_insights_tab_bar('breaches');

	html_start_box(__('Processing Status', 'cereus_insights'), '100%', '', '3', 'center', '');
	print '<tr><td style="padding:12px 8px 8px;">';
	cereus_insights_stats_box();
	print '</td></tr>';
	html_end_box();

	$nav = html_nav_bar('cereus_insights.php', MAX_DISPLAY_PAGES, $page, $rows, $total_rows, 5, __('Anomaly Breaches', 'cereus_insights'), 'page', 'main');

	/* Filter box */
	html_start_box(__('Anomaly Breach Filters', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr class="even noprint">
		<td>
		<form id="form_cib" method="post" action="cereus_insights.php">
		<table class="filterTable">
			<tr>
				<td><?php print __('Search', 'cereus_insights'); ?></td>
				<td><input type="text" id="filter" name="filter" size="25" value="<?php print html_escape($filter); ?>"></td>
				<td><?php print __('Period', 'cereus_insights'); ?></td>
				<td>
					<select id="period" name="period">
						<option value="3600"   <?php print ($period ==    3600 ? 'selected' : ''); ?>><?php print __('1 Hour', 'cereus_insights'); ?></option>
						<option value="21600"  <?php print ($period ==   21600 ? 'selected' : ''); ?>><?php print __('6 Hours', 'cereus_insights'); ?></option>
						<option value="86400"  <?php print ($period ==   86400 ? 'selected' : ''); ?>><?php print __('24 Hours', 'cereus_insights'); ?></option>
						<option value="604800" <?php print ($period ==  604800 ? 'selected' : ''); ?>><?php print __('7 Days', 'cereus_insights'); ?></option>
						<option value="2592000"<?php print ($period == 2592000 ? 'selected' : ''); ?>><?php print __('30 Days', 'cereus_insights'); ?></option>
					</select>
				</td>
				<td><?php print __('Min Z-Score', 'cereus_insights'); ?></td>
				<td><input type="text" id="zscore" name="zscore" size="5" value="<?php print html_escape($zscore > 0 ? $zscore : ''); ?>"></td>
				<td><?php print __('Rows', 'cereus_insights'); ?></td>
				<td>
					<select id="rows" name="rows">
						<?php
						$row_opts = array(10, 25, 50, 100, -1);
						foreach ($row_opts as $r) {
							$label = $r == -1 ? __('Default', 'cereus_insights') : $r;
							$sel   = ($rows == $r || ($r == -1 && $rows == read_config_option('num_rows_table'))) ? 'selected' : '';
							print "<option value=\"$r\" $sel>$label</option>";
						}
						?>
					</select>
				</td>
				<td>
					<span>
						<input type="submit" id="go" value="<?php print __esc('Go', 'cereus_insights'); ?>" title="<?php print __esc('Apply filter', 'cereus_insights'); ?>">
						<input type="button" id="clear" value="<?php print __esc('Clear', 'cereus_insights'); ?>" title="<?php print __esc('Clear filter', 'cereus_insights'); ?>" onClick="clearFilter()">
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
		array('display' => __('Date/Time', 'cereus_insights'),    'align' => 'left',  'sort' => 'ASC'),
		array('display' => __('Device', 'cereus_insights'),       'align' => 'left',  'sort' => 'ASC'),
		array('display' => __('Datasource', 'cereus_insights'),   'align' => 'left',  'sort' => 'ASC'),
		array('display' => __('Value', 'cereus_insights'),        'align' => 'right', 'sort' => 'ASC'),
		array('display' => __('Expected Mean', 'cereus_insights'), 'align' => 'right', 'sort' => 'ASC'),
		array('display' => __('Expected Hi (&plusmn;&sigma;)', 'cereus_insights'), 'align' => 'right', 'sort' => 'ASC'),
		array('display' => __('Z-Score', 'cereus_insights'),      'align' => 'right', 'sort' => 'ASC'),
		array('display' => __('Actions', 'cereus_insights'),      'align' => 'center','sort' => 'ASC'),
	);

	html_start_box('', '100%', '', '3', 'center', '');
	html_header_checkbox($columns);

	if (cacti_sizeof($breaches)) {
		foreach ($breaches as $row) {
			$z     = (float) $row['z_score'];
			$abs_z = abs($z);

			if ($abs_z > $sigma * 2) {
				$z_color = '#d9534f';
			} elseif ($abs_z > $sigma) {
				$z_color = '#f0ad4e';
			} else {
				$z_color = '#f5c518';
			}

			form_alternate_row('line_' . $row['id'], true);
			?>
			<td><?php print html_escape($row['breached_at']); ?></td>
			<td><?php print html_escape($row['host_description']); ?></td>
			<td><?php print html_escape($row['name_cache'] ?: ($row['datasource'])); ?></td>
			<td class="right"><?php print number_format((float)$row['value'], 2); ?></td>
			<td class="right"><?php print number_format((float)$row['expected_mean'], 2); ?></td>
			<td class="right"><?php print number_format((float)$row['expected_hi'], 2); ?></td>
			<td class="right" style="color:<?php print $z_color; ?>; font-weight:bold;"><?php print number_format($z, 2); ?></td>
			<td class="center">
				<a class="delete" href="cereus_insights.php?action=delete&id=<?php print (int)$row['id']; ?>" title="<?php print __esc('Delete', 'cereus_insights'); ?>">
					<img src="<?php print $config['url_path']; ?>images/delete_object.png" alt="<?php print __esc('Delete', 'cereus_insights'); ?>">
				</a>
			</td>
			<?php
			form_end_row();
		}
	} else {
		print '<tr><td colspan="8" class="center">'
		    . __('No anomaly breaches found for the selected filters.', 'cereus_insights')
		    . '</td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($breaches)) {
		draw_actions_dropdown(array(1 => __('Delete Selected', 'cereus_insights')));
	}

	print $nav;

	?>
	<script type="text/javascript">
	function clearFilter() {
		$('#filter').val('');
		$('#period').val('86400');
		$('#zscore').val('');
		$('#rows').val('-1');
		$('#form_cib').append('<input type="hidden" name="clear" value="1">');
		$('#form_cib').submit();
	}
	</script>
	<?php
}

