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

	if (!cereus_insights_tables_installed()) {
		cereus_insights_tab_bar('breaches');
		html_start_box('', '100%', '', '3', 'center', '');
		print '<tr><td class="center" style="padding:20px;color:#888;">'
		    . __('Plugin tables are being created — please wait for the next poller cycle, then reload.', 'cereus_insights')
		    . '</td></tr>';
		html_end_box();
		return;
	}

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

	/* ---- Noise Analysis (Professional+) ---- */
	if (cereus_insights_has_anomaly_detection()) {
		$global_sigma = (float) (read_config_option('cereus_insights_sigma') ?: CEREUS_INSIGHTS_DEFAULT_SIGMA);

		$noise_rows = db_fetch_assoc_prepared(
			"SELECT s.local_data_id, s.datasource, s.total_anomalies, s.signal_count,
			        s.noise_count, s.noise_pct, s.suggested_sigma,
			        COALESCE(ov.sigma, ?) AS current_sigma,
			        COALESCE(dtd.name_cache, s.datasource) AS name_cache,
			        COALESCE(h.description, h.hostname, '') AS host_description
			 FROM plugin_cereus_insights_anomaly_stats s
			 JOIN data_local dl ON dl.id = s.local_data_id
			 LEFT JOIN data_template_data dtd ON dtd.local_data_id = s.local_data_id
			 LEFT JOIN host h ON h.id = dl.host_id
			 LEFT JOIN plugin_cereus_insights_sigma_overrides ov
			     ON ov.local_data_id = s.local_data_id AND ov.datasource = s.datasource
			 WHERE s.suggested_sigma IS NOT NULL
			 ORDER BY s.noise_pct DESC
			 LIMIT 25",
			array($global_sigma)
		);

		if (cacti_sizeof($noise_rows)) {
			$ajax_url = $config['url_path'] . 'plugins/cereus_insights/cereus_insights_suggest_ajax.php';

			html_start_box(__('Anomaly Noise Analysis', 'cereus_insights'), '100%', '', '3', 'center', '');
			?>
			<tr class="tableHeader">
				<th><?php print __('Device', 'cereus_insights'); ?></th>
				<th><?php print __('Datasource', 'cereus_insights'); ?></th>
				<th class="right"><?php print __('Anomalies', 'cereus_insights'); ?></th>
				<th class="right"><?php print __('Signal', 'cereus_insights'); ?></th>
				<th class="right"><?php print __('Noise %', 'cereus_insights'); ?></th>
				<th class="right"><?php print __('Current &sigma;', 'cereus_insights'); ?></th>
				<th class="right"><?php print __('Suggested &sigma;', 'cereus_insights'); ?></th>
				<th class="center"><?php print __('Actions', 'cereus_insights'); ?></th>
			</tr>
			<?php
			foreach ($noise_rows as $nr) {
				$ldi          = (int)   $nr['local_data_id'];
				$ds           = (string) $nr['datasource'];
				$cur_sigma    = (float) $nr['current_sigma'];
				$sug_sigma    = (float) $nr['suggested_sigma'];
				$noise_pct    = (int)   $nr['noise_pct'];
				$noise_color  = $noise_pct >= 90 ? '#d9534f' : '#f0ad4e';
				$show_reset   = (abs($cur_sigma - $global_sigma) > 0.01);

				form_alternate_row();
				?>
				<td><?php print html_escape($nr['host_description']); ?></td>
				<td><?php print html_escape($nr['name_cache']); ?></td>
				<td class="right"><?php print number_format((int)$nr['total_anomalies']); ?></td>
				<td class="right"><?php print number_format((int)$nr['signal_count']); ?></td>
				<td class="right" style="color:<?php print $noise_color; ?>;font-weight:bold;"><?php print $noise_pct; ?>%</td>
				<td class="right" id="cur-sigma-<?php print $ldi; ?>-<?php print html_escape($ds); ?>"><?php print number_format($cur_sigma, 1); ?></td>
				<td class="right"><?php print number_format($sug_sigma, 1); ?></td>
				<td class="center">
					<button class="ui-button ui-corner-all ui-widget noise-apply-btn"
					        style="font-size:11px;padding:2px 8px;"
					        data-ldi="<?php print $ldi; ?>"
					        data-ds="<?php print html_escape($ds); ?>"
					        data-sigma="<?php print $sug_sigma; ?>"
					        data-ajax="<?php print html_escape($ajax_url); ?>"
					        title="<?php print __esc('Apply suggested sigma override for this datasource', 'cereus_insights'); ?>">
						<?php print __('Apply &sigma;', 'cereus_insights'); ?>
					</button>
					<?php if ($show_reset): ?>
					<button class="ui-button ui-corner-all ui-widget noise-reset-btn"
					        style="font-size:11px;padding:2px 8px;margin-left:4px;"
					        data-ldi="<?php print $ldi; ?>"
					        data-ds="<?php print html_escape($ds); ?>"
					        data-ajax="<?php print html_escape($ajax_url); ?>"
					        title="<?php print __esc('Reset sigma to global default', 'cereus_insights'); ?>">
						<?php print __('Reset', 'cereus_insights'); ?>
					</button>
					<?php endif; ?>
				</td>
				<?php
				form_end_row();
			}
			html_end_box();
		}
	}

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

	$(document).on('click', '.noise-apply-btn', function(e) {
		e.preventDefault();
		var $btn   = $(this);
		var ldi    = $btn.data('ldi');
		var ds     = $btn.data('ds');
		var sigma  = $btn.data('sigma');
		var url    = $btn.data('ajax');
		var data   = { action: 'apply_sigma', local_data_id: ldi, datasource: ds, sigma: sigma };
		if (typeof csrfMagicName !== 'undefined' && typeof csrfMagicToken !== 'undefined') {
			data[csrfMagicName] = csrfMagicToken;
		}
		$btn.prop('disabled', true);
		$.post(url, data, function(d) {
			if (d && d.ok) {
				$('#cur-sigma-' + ldi + '-' + ds).text(parseFloat(sigma).toFixed(1));
				$btn.hide();
			} else {
				alert(d ? d.error : '<?php print __esc('Request failed', 'cereus_insights'); ?>');
				$btn.prop('disabled', false);
			}
		}, 'json').fail(function() {
			alert('<?php print __esc('Request failed', 'cereus_insights'); ?>');
			$btn.prop('disabled', false);
		});
	});

	$(document).on('click', '.noise-reset-btn', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var ldi  = $btn.data('ldi');
		var ds   = $btn.data('ds');
		var url  = $btn.data('ajax');
		var data = { action: 'reset_sigma', local_data_id: ldi, datasource: ds };
		if (typeof csrfMagicName !== 'undefined' && typeof csrfMagicToken !== 'undefined') {
			data[csrfMagicName] = csrfMagicToken;
		}
		$btn.prop('disabled', true);
		$.post(url, data, function(d) {
			if (d && d.ok) {
				location.reload();
			} else {
				alert(d ? d.error : '<?php print __esc('Request failed', 'cereus_insights'); ?>');
				$btn.prop('disabled', false);
			}
		}, 'json').fail(function() {
			alert('<?php print __esc('Request failed', 'cereus_insights'); ?>');
			$btn.prop('disabled', false);
		});
	});
	</script>
	<?php
}

