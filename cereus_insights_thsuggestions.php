<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - AI Threshold Suggestions                              |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cereus_insights/includes/constants.php');
include_once('./plugins/cereus_insights/lib/license_check.php');
include_once('./plugins/cereus_insights/includes/tab_bar.php');
include_once('./plugins/cereus_insights/includes/stats.php');

top_header();
cereus_insights_thsuggestions_list();
bottom_footer();

/* =========================================================================
 * Suggestions List
 * ====================================================================== */

function cereus_insights_thsuggestions_list() {
	global $config;

	cereus_insights_tab_bar('suggestions');

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
	if (!cereus_insights_has_anomaly_detection()) {
		html_start_box(__('AI Threshold Suggestions', 'cereus_insights'), '100%', '', '3', 'center', '');
		print '<tr><td class="textArea center">'
		    . '<p>' . __('Threshold suggestions require a Professional or higher Cereus Insights license (baselines must be built first).', 'cereus_insights') . '</p>'
		    . '<p><a href="https://www.urban-software.com" target="_blank">'
		    . __('Upgrade your license', 'cereus_insights') . '</a></p>'
		    . '</td></tr>';
		html_end_box();
		return;
	}

	$is_enterprise = cereus_insights_has_llm();

	/* ---- filter persistence ---- */
	if (isset_request_var('clear')) {
		kill_session_var('sess_cts_filter');
		kill_session_var('sess_cts_conf');
		kill_session_var('sess_cts_rows');
		kill_session_var('sess_cts_page');
		unset_request_var('filter');
		unset_request_var('conf');
		unset_request_var('rows');
		unset_request_var('page');
	}

	load_current_session_value('filter', 'sess_cts_filter', '');
	load_current_session_value('conf',   'sess_cts_conf',   '0');
	load_current_session_value('rows',   'sess_cts_rows',   '-1');
	load_current_session_value('page',   'sess_cts_page',   '1');

	$filter = get_request_var('filter');
	$conf   = (int) get_request_var('conf');
	$rows   = (int) get_request_var('rows');
	$page   = max(1, (int) get_request_var('page'));

	if ($rows <= 0) {
		$rows = read_config_option('num_rows_table');
	}
	$rows = max(1, (int) $rows);

	/* ---- conf filter (direct column on cache table — no subquery needed) ---- */
	$conf_where_sql = '';
	$conf_params    = array();
	if ($conf > 0) {
		$conf_where_sql = "AND sc.conf_pct >= ?";
		$conf_params[]  = $conf;
	}

	/* ---- text filter ---- */
	$outer_where_sql = '';
	$outer_params    = array();
	if (!empty($filter)) {
		$safe            = str_replace(array('%','_'), array('\\%','\\_'), $filter);
		$like            = '%' . $safe . '%';
		$outer_where_sql = "AND (COALESCE(dtd.name_cache, sc.datasource) LIKE ? OR h.description LIKE ? OR h.hostname LIKE ?)";
		$outer_params[]  = $like;
		$outer_params[]  = $like;
		$outer_params[]  = $like;
	}

	/* ---- NOT EXISTS anti-joins ---- */
	$not_in_thold = "NOT EXISTS (
		SELECT 1 FROM thold_data td
		WHERE td.local_data_id = sc.local_data_id AND td.data_source_name = sc.datasource
	)";
	$not_in_skip = "NOT EXISTS (
		SELECT 1 FROM plugin_cereus_insights_suggest_skip sk
		WHERE sk.local_data_id = sc.local_data_id AND sk.datasource = sc.datasource
	)";
	$not_in_excl = "NOT EXISTS (SELECT 1 FROM plugin_cereus_insights_ds_exclusions ex WHERE ex.datasource = sc.datasource)";

	/* ---- count query (no joins when text filter inactive) ---- */
	if (!empty($filter)) {
		$total_rows = (int) db_fetch_cell_prepared(
			"SELECT COUNT(*)
			 FROM plugin_cereus_insights_suggest_cache sc
			 JOIN data_local dl ON dl.id = sc.local_data_id
			 LEFT JOIN data_template_data dtd ON dtd.local_data_id = sc.local_data_id
			 LEFT JOIN host h ON h.id = dl.host_id
			 WHERE $not_in_thold AND $not_in_skip AND $not_in_excl
			 $conf_where_sql $outer_where_sql",
			array_merge($conf_params, $outer_params)
		);
	} else {
		$total_rows = (int) db_fetch_cell_prepared(
			"SELECT COUNT(*)
			 FROM plugin_cereus_insights_suggest_cache sc
			 WHERE $not_in_thold AND $not_in_skip AND $not_in_excl
			 $conf_where_sql",
			$conf_params
		);
	}

	$offset = ($page - 1) * $rows;

	/* ---- main query: flat cache scan with per-page joins ---- */
	$suggestions = db_fetch_assoc_prepared(
		"SELECT sc.local_data_id, sc.datasource,
			sc.suggested_hi, sc.suggested_warn,
			sc.suggested_low_alert, sc.suggested_low_warn,
			sc.avg_mean,
			sc.buckets, sc.total_samples, sc.conf_pct,
			COALESCE(dtd.name_cache, sc.datasource) AS name_cache,
			dl.host_id,
			COALESCE(h.description, h.hostname, '') AS host_description
		 FROM plugin_cereus_insights_suggest_cache sc
		 JOIN data_local dl ON dl.id = sc.local_data_id
		 LEFT JOIN data_template_data dtd ON dtd.local_data_id = sc.local_data_id
		 LEFT JOIN host h ON h.id = dl.host_id
		 WHERE $not_in_thold AND $not_in_skip AND $not_in_excl
		 $conf_where_sql $outer_where_sql
		 ORDER BY sc.conf_pct DESC, sc.total_samples DESC
		 LIMIT $rows OFFSET $offset",
		array_merge($conf_params, $outer_params)
	);

	/* ---- render ---- */
	$ajax_url    = $config['url_path'] . 'plugins/cereus_insights/cereus_insights_suggest_ajax.php';

	$nav = html_nav_bar('cereus_insights_thsuggestions.php', MAX_DISPLAY_PAGES, $page, $rows, $total_rows, 5, __('Threshold Suggestions', 'cereus_insights'), 'page', 'main');

	/* Filter box */
	html_start_box(__('AI Threshold Suggestion Filters', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr class="even noprint">
		<td>
		<form id="form_cts" method="post" action="cereus_insights_thsuggestions.php">
		<table class="filterTable">
			<tr>
				<td><?php print __('Search', 'cereus_insights'); ?></td>
				<td><input type="text" id="filter" name="filter" size="25" value="<?php print html_escape($filter); ?>"></td>
				<td><?php print __('Min Confidence', 'cereus_insights'); ?></td>
				<td>
					<select id="conf" name="conf">
						<option value="0"  <?php print ($conf == 0  ? 'selected' : ''); ?>><?php print __('Any (show all)', 'cereus_insights'); ?></option>
						<option value="25" <?php print ($conf == 25 ? 'selected' : ''); ?>>&ge;25%</option>
						<option value="50" <?php print ($conf == 50 ? 'selected' : ''); ?>>&ge;50%</option>
						<option value="75" <?php print ($conf == 75 ? 'selected' : ''); ?>>&ge;75%</option>
						<option value="90" <?php print ($conf == 90 ? 'selected' : ''); ?>>&ge;90%</option>
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
						<input type="button" id="clear" value="<?php print __esc('Clear', 'cereus_insights'); ?>" onClick="clearSuggestFilter()">
					</span>
				</td>
			</tr>
		</table>
		</form>
		</td>
	</tr>
	<tr class="even noprint">
		<td><?php print __('Exclude DS Name', 'cereus_insights'); ?></td>
		<td colspan="5">
			<input type="text" id="excl-ds-input" size="20" placeholder="<?php print __esc('e.g. uptime', 'cereus_insights'); ?>">
			<input type="button" id="excl-ds-add" class="ui-button ui-corner-all ui-widget" value="<?php print __esc('Exclude', 'cereus_insights'); ?>" style="margin-left:4px;">
			<span id="excl-ds-list" style="margin-left:12px;">
			<?php
			$excl_list = db_fetch_assoc("SELECT datasource FROM plugin_cereus_insights_ds_exclusions ORDER BY datasource");
			if (cacti_sizeof($excl_list)) {
				foreach ($excl_list as $excl) {
					$ds_e = html_escape($excl['datasource']);
					print '<span class="excl-ds-tag" style="display:inline-block;background:#fdecea;border:1px solid #f5c6c0;border-radius:3px;padding:1px 6px;margin:2px;font-size:12px;">'
					    . $ds_e
					    . ' <a href="#" class="excl-ds-remove" data-ds="' . $ds_e . '" style="color:#c0392b;text-decoration:none;margin-left:2px;" title="' . __esc('Remove exclusion', 'cereus_insights') . '">&times;</a>'
					    . '</span>';
				}
			} else {
				print '<span style="color:#888;font-size:12px;">' . __('None', 'cereus_insights') . '</span>';
			}
			?>
			</span>
		</td>
	</tr>
	<?php
	html_end_box();

	/* Table */
	print $nav;

	$columns = array(
		array('display' => __('Device', 'cereus_insights'),     'align' => 'left',   'sort' => 'ASC'),
		array('display' => __('Datasource', 'cereus_insights'), 'align' => 'left',   'sort' => 'ASC'),
		array('display' => __('Avg Mean', 'cereus_insights'),   'align' => 'right',  'sort' => 'ASC'),
		array('display' => __('Warning (2&sigma;)', 'cereus_insights'),  'align' => 'right', 'sort' => 'ASC'),
		array('display' => __('Alert (3&sigma;)', 'cereus_insights'),    'align' => 'right', 'sort' => 'ASC'),
		array('display' => __('Direction', 'cereus_insights'),  'align' => 'center', 'sort' => 'ASC'),
		array('display' => __('Confidence', 'cereus_insights'), 'align' => 'center', 'sort' => 'ASC'),
		array('display' => __('Buckets', 'cereus_insights'),    'align' => 'center', 'sort' => 'ASC'),
		array('display' => __('Actions', 'cereus_insights'),    'align' => 'center', 'sort' => 'ASC'),
	);

	html_start_box('', '100%', '', '3', 'center', '');
	html_header($columns);

	if (cacti_sizeof($suggestions)) {
		foreach ($suggestions as $row) {
			$conf_pct = (int) $row['conf_pct'];
			if ($conf_pct >= 75) {
				$conf_color = '#27ae60';
			} elseif ($conf_pct >= 50) {
				$conf_color = '#f0ad4e';
			} else {
				$conf_color = '#c0392b';
			}

			$ldi      = (int)    $row['local_data_id'];
			$ds       = (string) $row['datasource'];
			$nc       = html_escape($row['name_cache'] ?: $ds);
			$host     = html_escape($row['host_description']);
			$avg_mean = (float)  $row['avg_mean'];
			$inverted = cereus_insights_is_inverted_metric($ds, $row['name_cache'] ?? '');

			/* Pick the right threshold pair based on direction */
			if ($inverted) {
				$t_warn   = (string) $row['suggested_low_warn'];
				$t_hi     = (string) $row['suggested_low_alert'];
				$no_data  = ((float) $t_hi <= 0);
				$dir_html = '<span title="' . __('Low threshold — alert when value drops below', 'cereus_insights') . '" style="color:#2980b9;font-size:14px;">&#x2193;</span>';
				if ($no_data) {
					$dir_html .= '&nbsp;<span title="' . __('Average value is zero — no threshold computable', 'cereus_insights') . '" style="color:#bbb;font-size:11px;">?</span>';
				}
			} else {
				$t_warn   = (string) $row['suggested_warn'];
				$t_hi     = (string) $row['suggested_hi'];
				$no_data  = false;
				$dir_html = '<span title="' . __('High threshold — alert when value exceeds', 'cereus_insights') . '" style="color:#e67e22;font-size:14px;">&#x2191;</span>';
			}

			form_alternate_row('cts_' . $ldi . '_' . htmlspecialchars($ds, ENT_QUOTES), true);
			?>
			<td><?php print $host; ?></td>
			<td>
				<?php print $nc; ?>
				<br><span style="color:#888;font-size:11px;font-family:monospace;"><?php print html_escape($ds); ?></span>
			</td>
			<td class="right"><?php print number_format($avg_mean, 4); ?></td>
			<td class="right"><?php print $no_data ? '&mdash;' : number_format((float)$t_warn, 4); ?></td>
			<td class="right"><?php print $no_data ? '&mdash;' : number_format((float)$t_hi, 4); ?></td>
			<td class="center"><?php print $dir_html; ?></td>
			<td class="center" style="color:<?php print $conf_color; ?>;font-weight:bold;"><?php print $conf_pct; ?>%</td>
			<td class="center"><?php print (int)$row['buckets']; ?></td>
			<td class="center nowrap">
				<button type="button" class="cts-create-btn ui-button ui-corner-all ui-widget"
					data-ldi="<?php print $ldi; ?>"
					data-ds="<?php print html_escape($ds); ?>"
					data-hi="<?php print html_escape($t_hi); ?>"
					data-warn="<?php print html_escape($t_warn); ?>"
					data-inverted="<?php print $inverted ? '1' : '0'; ?>"
					<?php if ($no_data): ?>disabled title="<?php print __esc('Average value is zero — threshold cannot be computed', 'cereus_insights'); ?>"<?php endif; ?>
					style="margin-right:4px;">
					<?php print __('Create', 'cereus_insights'); ?>
				</button>
				<?php if ($is_enterprise): ?>
				<button type="button" class="cts-explain-btn ui-button ui-corner-all ui-widget"
					data-ldi="<?php print $ldi; ?>"
					data-ds="<?php print html_escape($ds); ?>"
					data-hi="<?php print html_escape($t_hi); ?>"
					data-warn="<?php print html_escape($t_warn); ?>"
					data-mean="<?php print html_escape(number_format($avg_mean, 4, '.', '')); ?>"
					data-nc="<?php print html_escape($row['name_cache'] ?: $ds); ?>"
					data-host="<?php print html_escape($row['host_description']); ?>"
					data-conf="<?php print $conf_pct; ?>"
					data-buckets="<?php print (int)$row['buckets']; ?>"
					style="margin-right:4px;">
					<?php print __('Explain', 'cereus_insights'); ?>
				</button>
				<?php endif; ?>
				<button type="button" class="cts-skip-btn ui-button ui-corner-all ui-widget"
					data-ldi="<?php print $ldi; ?>"
					data-ds="<?php print html_escape($ds); ?>">
					<?php print __('Skip', 'cereus_insights'); ?>
				</button>
			</td>
			<?php
			form_end_row();

			/* Explain detail row — hidden initially */
			print '<tr id="cts-explain-' . $ldi . '-' . htmlspecialchars($ds, ENT_QUOTES) . '" class="cts-explain-row" style="display:none;">';
			print '<td colspan="9">';
			print '<div class="cts-explain-content" style="padding:10px 16px;background:#f0f7ff;border:1px solid #bee3f8;border-radius:4px;font-style:italic;color:#2c5282;">';
			print '<span class="cts-explain-text"></span>';
			print '</div>';
			print '</td>';
			print '</tr>';
		}
	} else {
		/* Diagnose why there are no results */
		$cache_total = (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_cereus_insights_suggest_cache");

		if ($cache_total === 0) {
			$baselines_exist = (int) db_fetch_cell("SELECT 1 FROM plugin_cereus_insights_baselines LIMIT 1");
			if ($baselines_exist) {
				$empty_msg = __('The suggestion cache is still being built &mdash; check back after the next poller cycle.', 'cereus_insights');
			} else {
				$empty_msg = __('No baselines found. The poller must run at least once with a Professional+ license to build baselines before suggestions appear.', 'cereus_insights');
			}
		} elseif ($conf > 0) {
			/* Cache has data but the confidence filter is hiding everything */
			$unfiltered = (int) db_fetch_cell_prepared(
				"SELECT COUNT(*) FROM plugin_cereus_insights_suggest_cache sc
				 WHERE $not_in_thold AND $not_in_skip AND $not_in_excl",
				array()
			);
			if ($unfiltered > 0) {
				$empty_msg = __('%d suggestion(s) exist but are hidden by the Min Confidence filter (&ge;%d%%). Set Min Confidence to &ldquo;Any&rdquo; to see them.', $unfiltered, $conf, 'cereus_insights');
			} else {
				$empty_msg = __('All data sources with baselines already have thresholds configured.', 'cereus_insights');
			}
		} else {
			$empty_msg = __('All data sources with baselines already have thresholds configured.', 'cereus_insights');
		}
		print '<tr><td colspan="9" class="center">' . $empty_msg . '</td></tr>';
	}

	html_end_box();
	print $nav;

	?>
	<script type="text/javascript">
	(function() {
		var ajaxUrl = <?php print json_encode($ajax_url); ?>;

		function csrfData(extra) {
			var d = extra || {};
			if (typeof csrfMagicName !== 'undefined' && typeof csrfMagicToken !== 'undefined') {
				d[csrfMagicName] = csrfMagicToken;
			}
			return d;
		}

		function rowId(ldi, ds) {
			return 'cts_' + ldi + '_' + ds;
		}

		function explainRowId(ldi, ds) {
			return 'cts-explain-' + ldi + '-' + ds;
		}

		/* Create threshold */
		$(document).off('click.cts_create').on('click.cts_create', '.cts-create-btn', function() {
			var $btn  = $(this);
			var ldi   = $btn.data('ldi');
			var ds    = $btn.data('ds');
			var hi    = $btn.data('hi');
			var warn  = $btn.data('warn');

			$btn.prop('disabled', true).text(<?php print json_encode(__('Creating…', 'cereus_insights')); ?>);

			var inverted = $btn.data('inverted') == '1' ? 1 : 0;
			$.ajax({
				url:     ajaxUrl,
				type:    'POST',
				data:    csrfData({ action: 'create', local_data_id: ldi, datasource: ds, thold_hi: hi, thold_warn: warn, inverted: inverted }),
				dataType:'json',
				timeout: 15000
			}).done(function(d) {
				if (d.ok) {
					$('#' + rowId(ldi, ds)).fadeOut(300, function() { $(this).remove(); });
					$('#' + explainRowId(ldi, ds)).remove();
				} else {
					$btn.prop('disabled', false).text(<?php print json_encode(__('Create', 'cereus_insights')); ?>);
					alert(d.error || <?php print json_encode(__('Create failed', 'cereus_insights')); ?>);
				}
			}).fail(function() {
				$btn.prop('disabled', false).text(<?php print json_encode(__('Create', 'cereus_insights')); ?>);
				alert(<?php print json_encode(__('Request failed', 'cereus_insights')); ?>);
			});
		});

		/* Explain threshold (Enterprise) */
		$(document).off('click.cts_explain').on('click.cts_explain', '.cts-explain-btn', function() {
			var $btn    = $(this);
			var ldi     = $btn.data('ldi');
			var ds      = $btn.data('ds');
			var $erow   = $('#' + explainRowId(ldi, ds));
			var $etext  = $erow.find('.cts-explain-text');

			/* Toggle off if already visible */
			if ($erow.is(':visible')) {
				$erow.hide();
				return;
			}

			/* If already fetched, just show */
			if ($etext.text().length > 0) {
				$erow.show();
				return;
			}

			$btn.prop('disabled', true).text(<?php print json_encode(__('Explaining…', 'cereus_insights')); ?>);

			$.ajax({
				url:     ajaxUrl,
				type:    'POST',
				data:    csrfData({
					action:      'explain',
					local_data_id: ldi,
					datasource:  ds,
					suggested_hi:   $btn.data('hi'),
					suggested_warn: $btn.data('warn'),
					avg_mean:    $btn.data('mean'),
					name_cache:  $btn.data('nc'),
					host_description: $btn.data('host'),
					conf_pct:    $btn.data('conf'),
					buckets:     $btn.data('buckets')
				}),
				dataType:'json',
				timeout: 30000
			}).done(function(d) {
				if (d.ok) {
					$etext.text(d.explanation);
					$erow.show();
				} else {
					alert(d.error || <?php print json_encode(__('Explain failed', 'cereus_insights')); ?>);
				}
			}).fail(function() {
				alert(<?php print json_encode(__('Request failed', 'cereus_insights')); ?>);
			}).always(function() {
				$btn.prop('disabled', false).text(<?php print json_encode(__('Explain', 'cereus_insights')); ?>);
			});
		});

		/* Skip suggestion */
		$(document).off('click.cts_skip').on('click.cts_skip', '.cts-skip-btn', function() {
			var $btn = $(this);
			var ldi  = $btn.data('ldi');
			var ds   = $btn.data('ds');

			$btn.prop('disabled', true);

			$.ajax({
				url:     ajaxUrl,
				type:    'POST',
				data:    csrfData({ action: 'skip', local_data_id: ldi, datasource: ds }),
				dataType:'json',
				timeout: 10000
			}).done(function(d) {
				if (d.ok) {
					$('#' + rowId(ldi, ds)).fadeOut(300, function() { $(this).remove(); });
					$('#' + explainRowId(ldi, ds)).remove();
				} else {
					$btn.prop('disabled', false);
				}
			}).fail(function() {
				$btn.prop('disabled', false);
			});
		});

		/* Exclude datasource name globally */
		$(document).off('click.excl_add').on('click.excl_add', '#excl-ds-add', function() {
			var ds = $('#excl-ds-input').val().trim();
			if (!ds) return;
			$.ajax({
				url: ajaxUrl, type: 'POST',
				data: csrfData({ action: 'exclude_ds', datasource: ds }),
				dataType: 'json', timeout: 10000
			}).done(function(d) {
				if (d.ok) location.reload();
				else alert(d.error || <?php print json_encode(__('Exclude failed', 'cereus_insights')); ?>);
			});
		});

		/* Remove exclusion */
		$(document).off('click.excl_remove').on('click.excl_remove', '.excl-ds-remove', function(e) {
			e.preventDefault();
			var ds = $(this).data('ds');
			$.ajax({
				url: ajaxUrl, type: 'POST',
				data: csrfData({ action: 'include_ds', datasource: ds }),
				dataType: 'json', timeout: 10000
			}).done(function(d) {
				if (d.ok) location.reload();
				else alert(d.error || <?php print json_encode(__('Remove failed', 'cereus_insights')); ?>);
			});
		});
	})();

	function clearSuggestFilter() {
		$('#filter').val('');
		$('#conf').val('0');
		$('#rows').val('-1');
		$('#form_cts').append('<input type="hidden" name="clear" value="1">');
		$('#form_cts').submit();
	}
	</script>
	<?php
}
