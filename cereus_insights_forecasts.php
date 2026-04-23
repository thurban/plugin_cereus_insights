<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Capacity Forecasts Viewer                             |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cereus_insights/includes/constants.php');
include_once('./plugins/cereus_insights/lib/license_check.php');
include_once('./plugins/cereus_insights/includes/tab_bar.php');
include_once('./plugins/cereus_insights/includes/stats.php');

top_header();
cereus_insights_forecasts_list();
bottom_footer();

/* =========================================================================
 * Forecasts List
 * ====================================================================== */

function cereus_insights_forecasts_list() {
	global $config;

	if (!cereus_insights_tables_installed()) {
		cereus_insights_tab_bar('forecasts');
		html_start_box('', '100%', '', '3', 'center', '');
		print '<tr><td class="center" style="padding:20px;color:#888;">'
		    . __('Plugin tables are being created — please wait for the next poller cycle, then reload.', 'cereus_insights')
		    . '</td></tr>';
		html_end_box();
		return;
	}

	/* ---- filter persistence ---- */
	if (isset_request_var('clear')) {
		kill_session_var('sess_cif_filter');
		kill_session_var('sess_cif_severity');
		kill_session_var('sess_cif_rows');
		kill_session_var('sess_cif_page');
		unset_request_var('filter');
		unset_request_var('severity');
		unset_request_var('rows');
		unset_request_var('page');
	}

	load_current_session_value('filter',   'sess_cif_filter',   '');
	load_current_session_value('severity', 'sess_cif_severity', 'all');
	load_current_session_value('rows',     'sess_cif_rows',     '-1');
	load_current_session_value('page',     'sess_cif_page',     '1');

	$filter   = get_request_var('filter');
	$severity = get_request_var('severity');
	$rows     = (int)  get_request_var('rows');
	$page     = max(1, (int) get_request_var('page'));

	if ($rows <= 0) {
		$rows = read_config_option('num_rows_table');
	}
	$rows = max(1, (int) $rows);

	/* ---- summary counts (single pass) ---- */
	$sev = db_fetch_row(
		"SELECT
			SUM(forecast_days < 7)                              AS cnt_critical,
			SUM(forecast_days >= 7  AND forecast_days < 30)     AS cnt_warning,
			SUM(forecast_days >= 30 AND forecast_days < 90)     AS cnt_watch
		 FROM plugin_cereus_insights_forecasts
		 WHERE forecast_days IS NOT NULL"
	);
	$cnt_critical = (int) ($sev['cnt_critical'] ?? 0);
	$cnt_warning  = (int) ($sev['cnt_warning']  ?? 0);
	$cnt_watch    = (int) ($sev['cnt_watch']    ?? 0);

	/* ---- query ---- */
	$sql_where  = "WHERE 1=1";
	$sql_params = array();

	if (!empty($filter)) {
		$safe = str_replace(array('%','_'), array('\\%','\\_'), $filter);
		$sql_where  .= " AND (f.name_cache LIKE ? OR h.description LIKE ? OR h.hostname LIKE ?)";
		$sql_params[] = '%' . $safe . '%';
		$sql_params[] = '%' . $safe . '%';
		$sql_params[] = '%' . $safe . '%';
	}

	switch ($severity) {
		case 'critical':
			$sql_where .= " AND f.forecast_days IS NOT NULL AND f.forecast_days < 7";
			break;
		case 'warning':
			$sql_where .= " AND f.forecast_days IS NOT NULL AND f.forecast_days >= 7 AND f.forecast_days < 30";
			break;
		case 'watch':
			$sql_where .= " AND f.forecast_days IS NOT NULL AND f.forecast_days >= 30 AND f.forecast_days < 90";
			break;
		case 'healthy':
			$sql_where .= " AND (f.forecast_days IS NULL OR f.forecast_days >= 90)";
			break;
	}

	$total_rows = db_fetch_cell_prepared(
		"SELECT COUNT(*)
		 FROM plugin_cereus_insights_forecasts f
		 LEFT JOIN host h ON h.id = f.host_id
		 $sql_where",
		$sql_params
	);

	$offset   = ($page - 1) * $rows;
	$page_sql = " ORDER BY f.forecast_days ASC LIMIT $rows OFFSET $offset";

	$forecasts = db_fetch_assoc_prepared(
		"SELECT f.*, h.description AS host_description, h.hostname
		 FROM plugin_cereus_insights_forecasts f
		 LEFT JOIN host h ON h.id = f.host_id
		 $sql_where $page_sql",
		$sql_params
	);

	/* ---- render ---- */
	cereus_insights_tab_bar('forecasts');

	html_start_box(__('Processing Status', 'cereus_insights'), '100%', '', '3', 'center', '');
	print '<tr><td style="padding:12px 8px 8px;">';
	cereus_insights_stats_box();
	print '</td></tr>';
	html_end_box();

	/* Summary counts */
	html_start_box(__('Capacity Forecast Status', 'cereus_insights'), '100%', '', '3', 'center', '');
	print '<tr><td>';
	print '<table class="filterTable"><tr>';
	printf(
		'<td><span style="color:#d9534f;font-weight:bold;">%s</span> %s</td>',
		$cnt_critical,
		__('Critical (&lt;7 days)', 'cereus_insights')
	);
	printf(
		'<td style="padding-left:20px;"><span style="color:#f0ad4e;font-weight:bold;">%s</span> %s</td>',
		$cnt_warning,
		__('Warning (&lt;30 days)', 'cereus_insights')
	);
	printf(
		'<td style="padding-left:20px;"><span style="color:#f5c518;font-weight:bold;">%s</span> %s</td>',
		$cnt_watch,
		__('Watch (&lt;90 days)', 'cereus_insights')
	);
	print '</tr></table>';
	print '</td></tr>';
	html_end_box();

	$nav = html_nav_bar('cereus_insights_forecasts.php', MAX_DISPLAY_PAGES, $page, $rows, $total_rows, 5, __('Capacity Forecasts', 'cereus_insights'), 'page', 'main');

	/* Filter box */
	html_start_box(__('Forecast Filters', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr class="even noprint">
		<td>
		<form id="form_cif" method="post" action="cereus_insights_forecasts.php">
		<table class="filterTable">
			<tr>
				<td><?php print __('Search', 'cereus_insights'); ?></td>
				<td><input type="text" id="filter" name="filter" size="25" value="<?php print html_escape($filter); ?>"></td>
				<td><?php print __('Severity', 'cereus_insights'); ?></td>
				<td>
					<select id="severity" name="severity">
						<option value="all"      <?php print ($severity === 'all'      ? 'selected' : ''); ?>><?php print __('All', 'cereus_insights'); ?></option>
						<option value="critical" <?php print ($severity === 'critical' ? 'selected' : ''); ?>><?php print __('Critical (&lt;7d)', 'cereus_insights'); ?></option>
						<option value="warning"  <?php print ($severity === 'warning'  ? 'selected' : ''); ?>><?php print __('Warning (&lt;30d)', 'cereus_insights'); ?></option>
						<option value="watch"    <?php print ($severity === 'watch'    ? 'selected' : ''); ?>><?php print __('Watch (&lt;90d)', 'cereus_insights'); ?></option>
						<option value="healthy"  <?php print ($severity === 'healthy'  ? 'selected' : ''); ?>><?php print __('Healthy / Stable', 'cereus_insights'); ?></option>
					</select>
				</td>
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
						<input type="button" id="clear" value="<?php print __esc('Clear', 'cereus_insights'); ?>" onClick="clearForecastFilter()">
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
		array('display' => __('Device', 'cereus_insights'),        'align' => 'left',   'sort' => 'ASC'),
		array('display' => __('Datasource', 'cereus_insights'),    'align' => 'left',   'sort' => 'ASC'),
		array('display' => __('Current Value', 'cereus_insights'), 'align' => 'right',  'sort' => 'ASC'),
		array('display' => __('Daily Trend', 'cereus_insights'),   'align' => 'right',  'sort' => 'ASC'),
		array('display' => __('Forecast Date', 'cereus_insights'), 'align' => 'center', 'sort' => 'ASC'),
		array('display' => __('Days Remaining', 'cereus_insights'),'align' => 'center', 'sort' => 'ASC'),
		array('display' => __('R&sup2;', 'cereus_insights'),       'align' => 'center', 'sort' => 'ASC'),
		array('display' => __('Updated', 'cereus_insights'),       'align' => 'left',   'sort' => 'ASC'),
	);

	html_start_box('', '100%', '', '3', 'center', '');
	html_header($columns);

	if (cacti_sizeof($forecasts)) {
		foreach ($forecasts as $row) {
			$fd = $row['forecast_days'];

			if ($fd !== null) {
				$fd = (int) $fd;
				if ($fd < 7) {
					$row_color = '#fdecea';
					$fd_color  = '#d9534f';
				} elseif ($fd < 30) {
					$row_color = '#fef8e7';
					$fd_color  = '#f0ad4e';
				} elseif ($fd < 90) {
					$row_color = '#fefde5';
					$fd_color  = '#c9aa06';
				} else {
					$row_color = '';
					$fd_color  = '#5cb85c';
				}
				$fd_text = $fd . ' ' . __('days', 'cereus_insights');
			} else {
				$row_color = '';
				$fd_color  = '#5cb85c';
				$fd_text   = __('Never / Stable', 'cereus_insights');
			}

			$slope_per_day = (float) $row['slope'] * 86400;
			$slope_text    = ($slope_per_day >= 0 ? '+' : '') . number_format($slope_per_day, 2) . '/day';

			form_alternate_row();
			if ($row_color) {
				print str_replace('<tr ', '<tr style="background-color:' . $row_color . ';" ', '');
			}
			?>
			<td><?php print html_escape($row['host_description'] ?: $row['hostname']); ?></td>
			<td><?php print html_escape($row['name_cache'] ?: $row['datasource']); ?></td>
			<td class="right"><?php print number_format((float)$row['last_value'], 2); ?></td>
			<td class="right"><?php print html_escape($slope_text); ?></td>
			<td class="center"><?php print $row['forecast_date'] ? html_escape($row['forecast_date']) : __('&mdash;', 'cereus_insights'); ?></td>
			<td class="center" style="color:<?php print $fd_color; ?>;font-weight:bold;"><?php print $fd_text; ?></td>
			<td class="center"><?php print number_format((float)$row['r_squared'], 3); ?></td>
			<td><?php print html_escape($row['updated_at']); ?></td>
			<?php
			form_end_row();
		}
	} else {
		print '<tr><td colspan="8" class="center">'
		    . __('No forecast data found. The poller will populate forecasts on its next cycle.', 'cereus_insights')
		    . '</td></tr>';
	}

	html_end_box();
	print $nav;

	?>
	<script type="text/javascript">
	function clearForecastFilter() {
		$('#filter').val('');
		$('#severity').val('all');
		$('#rows').val('-1');
		$('#form_cif').append('<input type="hidden" name="clear" value="1">');
		$('#form_cif').submit();
	}
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * Tab Bar (for forecasts page — same function, different active)
 * ---------------------------------------------------------------------- */

