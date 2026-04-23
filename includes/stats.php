<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Processing Status Box                                 |
 +-------------------------------------------------------------------------+
*/

/**
 * Render a three-column processing status panel.
 * Safe to call regardless of license tier; gated sections show "N/A".
 */
function cereus_insights_stats_box(): void {

	/* Ensure tables exist — creates them on-the-fly if install was skipped or failed. */
	if (!cereus_insights_tables_installed()) {
		return;
	}

	/* ---- gather data in as few queries as possible ---- */

	$seen = db_fetch_row("SELECT * FROM plugin_cereus_insights_seen WHERE id = 1");
	$seen = $seen ?: array(
		'last_baseline_run'  => 0,
		'last_forecast_run'  => 0,
		'last_purge_run'     => 0,
		'baseline_cursor'    => 0,
		'forecast_cursor'    => 0,
	);

	$now = time();

	/* Shared counts */
	$ldi_agg   = db_fetch_row("SELECT COUNT(*) AS total_ldi, COALESCE(MAX(id), 0) AS max_ldi FROM data_local");
	$total_ldi = (int) ($ldi_agg['total_ldi'] ?? 0);
	$max_ldi   = (int) ($ldi_agg['max_ldi']   ?? 0);

	/* Baselines — combined aggregation */
	$min_samples  = (int) (read_config_option('cereus_insights_min_samples') ?: CEREUS_INSIGHTS_DEFAULT_MIN_SAMPLES);
	$baseline_agg = db_fetch_row(
		"SELECT COUNT(*)                          AS total_buckets,
		        COUNT(DISTINCT local_data_id)     AS distinct_ldi,
		        ROUND(AVG(sample_count))          AS avg_samples,
		        SUM(sample_count >= $min_samples) AS ready_buckets
		 FROM plugin_cereus_insights_baselines"
	);
	$baseline_buckets = (int)   ($baseline_agg['total_buckets']  ?? 0);
	$baseline_ldi     = (int)   ($baseline_agg['distinct_ldi']   ?? 0);
	$avg_samples      = (int)   ($baseline_agg['avg_samples']    ?? 0);
	$buckets_ready    = (int)   ($baseline_agg['ready_buckets']  ?? 0);
	$ready_pct        = $baseline_buckets > 0 ? round($buckets_ready / $baseline_buckets * 100) : 0;

	$breach_agg = db_fetch_row(
		"SELECT SUM(breached_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))   AS b7d,
		        SUM(breached_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))  AS b24h
		 FROM plugin_cereus_insights_breaches
		 WHERE breached_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
	);
	$breaches_7d  = (int) ($breach_agg['b7d']  ?? 0);
	$breaches_24h = (int) ($breach_agg['b24h'] ?? 0);

	/* Forecasts — combined aggregation */
	$forecast_agg = db_fetch_row(
		"SELECT COUNT(*)                      AS total,
		        COUNT(DISTINCT local_data_id) AS distinct_ldi,
		        SUM(r_squared >= 0.3)         AS quality_count,
		        ROUND(AVG(r_squared), 2)      AS avg_r2
		 FROM plugin_cereus_insights_forecasts"
	);
	$forecast_count = (int)   ($forecast_agg['total']         ?? 0);
	$forecast_ldi   = (int)   ($forecast_agg['distinct_ldi']  ?? 0);
	$quality_count  = (int)   ($forecast_agg['quality_count'] ?? 0);
	$avg_r2         = (float) ($forecast_agg['avg_r2']        ?? 0.0);
	$quality_pct    = $forecast_count > 0 ? round($quality_count / $forecast_count * 100) : 0;

	$warn_days     = (int) (read_config_option('cereus_insights_warn_days') ?: CEREUS_INSIGHTS_DEFAULT_WARN_DAYS);
	$forecast_soon = (int) db_fetch_cell(
		"SELECT COUNT(*) FROM plugin_cereus_insights_forecasts
		 WHERE forecast_days IS NOT NULL AND forecast_days > 0 AND forecast_days <= " . $warn_days
	);

	/* Threshold Suggestions */
	$suggest_cache_agg = db_fetch_row(
		"SELECT COUNT(*) AS total,
		        SUM(conf_pct >= 75) AS high_conf,
		        ROUND(AVG(conf_pct)) AS avg_conf
		 FROM plugin_cereus_insights_suggest_cache"
	);
	$suggest_cache_total = (int)   ($suggest_cache_agg['total']     ?? 0);
	$suggest_high_conf   = (int)   ($suggest_cache_agg['high_conf'] ?? 0);
	$suggest_avg_conf    = (int)   ($suggest_cache_agg['avg_conf']  ?? 0);
	$suggest_skipped     = (int)   db_fetch_cell("SELECT COUNT(*) FROM plugin_cereus_insights_suggest_skip");
	$suggest_eligible    = (int)   db_fetch_cell(
		"SELECT COUNT(*) FROM plugin_cereus_insights_suggest_cache sc
		 WHERE NOT EXISTS (SELECT 1 FROM thold_data td WHERE td.local_data_id = sc.local_data_id AND td.data_source_name = sc.datasource)
		 AND NOT EXISTS (SELECT 1 FROM plugin_cereus_insights_suggest_skip sk WHERE sk.local_data_id = sc.local_data_id AND sk.datasource = sc.datasource)
		 AND NOT EXISTS (SELECT 1 FROM plugin_cereus_insights_ds_exclusions ex WHERE ex.datasource = sc.datasource)"
	);
	$suggest_eligible_high = (int) db_fetch_cell(
		"SELECT COUNT(*) FROM plugin_cereus_insights_suggest_cache sc
		 WHERE conf_pct >= 75
		 AND NOT EXISTS (SELECT 1 FROM thold_data td WHERE td.local_data_id = sc.local_data_id AND td.data_source_name = sc.datasource)
		 AND NOT EXISTS (SELECT 1 FROM plugin_cereus_insights_suggest_skip sk WHERE sk.local_data_id = sc.local_data_id AND sk.datasource = sc.datasource)
		 AND NOT EXISTS (SELECT 1 FROM plugin_cereus_insights_ds_exclusions ex WHERE ex.datasource = sc.datasource)"
	);

	/* AI / LLM */
	$queue_depth   = (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_cereus_insights_alert_queue");
	$summary_count = (int) db_fetch_cell("SELECT COUNT(*) FROM plugin_cereus_insights_summaries");
	$summary_agg   = db_fetch_row(
		"SELECT COALESCE(SUM(tokens_used), 0) AS total_tokens,
		        MAX(created_at)               AS last_summary,
		        COALESCE(AVG(alert_count), 0) AS avg_alerts
		 FROM plugin_cereus_insights_summaries"
	);
	$total_tokens = (int)   ($summary_agg['total_tokens'] ?? 0);
	$last_summary = $summary_agg['last_summary'] ?? null;
	$avg_alerts   = (float) ($summary_agg['avg_alerts']   ?? 0.0);

	/* ---- license flags ---- */
	$is_professional = cereus_insights_license_at_least('professional');
	$is_enterprise   = cereus_insights_license_at_least('enterprise');

	/* ---- derived values ---- */
	$last_baseline_ts = (int) $seen['last_baseline_run'];
	$last_forecast_ts = (int) $seen['last_forecast_run'];

	$baseline_cursor  = (int) $seen['baseline_cursor'];
	$forecast_cursor  = (int) $seen['forecast_cursor'];

	$baseline_pct = ($max_ldi > 0) ? min(100, round($baseline_cursor / $max_ldi * 100)) : 0;
	$forecast_pct = ($max_ldi > 0) ? min(100, round($forecast_cursor / $max_ldi * 100)) : 0;

	$coverage_b_pct = ($total_ldi > 0) ? round($baseline_ldi / $total_ldi * 100) : 0;
	$coverage_f_pct = ($total_ldi > 0) ? round($forecast_ldi  / $total_ldi * 100) : 0;

	$baseline_interval = (int) (read_config_option('cereus_insights_baseline_interval') ?: CEREUS_INSIGHTS_DEFAULT_BASELINE_INT);
	$forecast_interval = (int) (read_config_option('cereus_insights_forecast_interval') ?: CEREUS_INSIGHTS_DEFAULT_FORECAST_INT);
	$next_baseline = $last_baseline_ts + $baseline_interval;
	$next_forecast = $last_forecast_ts + $forecast_interval;

	/* ---- helpers ---- */
	function _cis_ago(int $ts): string {
		if ($ts <= 0) return __('Never', 'cereus_insights');
		$diff = time() - $ts;
		if ($diff < 60)    return __('Just now', 'cereus_insights');
		if ($diff < 3600)  return sprintf(__('%d min ago', 'cereus_insights'), (int)($diff / 60));
		if ($diff < 86400) return sprintf(__('%d hr ago',  'cereus_insights'), (int)($diff / 3600));
		return sprintf(__('%d days ago', 'cereus_insights'), (int)($diff / 86400));
	}
	function _cis_in(int $ts): string {
		$diff = $ts - time();
		if ($diff <= 0)   return '<span style="color:#27ae60;">' . __('Due now', 'cereus_insights') . '</span>';
		if ($diff < 60)   return sprintf(__('in %d sec', 'cereus_insights'), $diff);
		if ($diff < 3600) return sprintf(__('in %d min', 'cereus_insights'), (int)($diff / 60));
		return sprintf(__('in %d hr', 'cereus_insights'), (int)($diff / 3600));
	}
	function _cis_bar(int $pct, string $color = '#3498db'): string {
		$pct = max(0, min(100, $pct));
		return '<div style="background:#e0e0e0;border-radius:3px;height:8px;width:100%;margin-top:3px;">'
		     . '<div style="background:' . $color . ';border-radius:3px;height:8px;width:' . $pct . '%;"></div>'
		     . '</div>';
	}
	function _cis_readiness_color(int $pct): string {
		if ($pct >= 80) return '#27ae60';
		if ($pct >= 40) return '#e67e22';
		return '#e74c3c';
	}

	?>
	<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:4px;">

	<!-- ===================== BASELINES / ANOMALY ===================== -->
	<div style="flex:1;min-width:220px;background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px;">
		<div style="font-weight:bold;font-size:13px;border-bottom:1px solid #eee;padding-bottom:6px;margin-bottom:8px;">
			&#128200; <?php print __('Anomaly Baselines', 'cereus_insights'); ?>
		</div>
	<?php if (!$is_professional): ?>
		<span style="color:#888;font-size:12px;"><?php print __('Requires Professional license', 'cereus_insights'); ?></span>
	<?php else: ?>
		<table style="width:100%;font-size:12px;border-collapse:collapse;">
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Buckets computed', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;"><?php print number_format($baseline_buckets); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Data sources covered', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;"><?php print $baseline_ldi . ' / ' . $total_ldi . ' (' . $coverage_b_pct . '%)'; ?></td>
			</tr>
			<tr><td colspan="2"><?php print _cis_bar($coverage_b_pct, '#2ecc71'); ?></td></tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Batch cursor', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;"><?php print 'ID ' . $baseline_cursor . ' (' . $baseline_pct . '%)'; ?></td>
			</tr>
			<tr><td colspan="2"><?php print _cis_bar($baseline_pct); ?></td></tr>
			<tr>
				<td style="color:#666;padding:6px 0 2px;"><?php print __('Last run', 'cereus_insights'); ?></td>
				<td style="text-align:right;"><?php print _cis_ago($last_baseline_ts); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Next run', 'cereus_insights'); ?></td>
				<td style="text-align:right;"><?php
					if ($baseline_cursor > 0) {
						print '<span style="color:#3498db;">' . __('In progress', 'cereus_insights') . '</span>';
					} else {
						print _cis_in($next_baseline);
					}
				?></td>
			</tr>
			<!-- Detection readiness -->
			<tr style="border-top:1px solid #eee;">
				<td style="color:#666;padding:6px 0 2px;font-weight:bold;"><?php print __('Detection ready', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;color:<?php print _cis_readiness_color($ready_pct); ?>;"><?php
					print $baseline_buckets > 0 ? $ready_pct . '%' : __('No data', 'cereus_insights');
				?></td>
			</tr>
			<?php if ($baseline_buckets > 0): ?>
			<tr><td colspan="2"><?php print _cis_bar($ready_pct, _cis_readiness_color($ready_pct)); ?></td></tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Avg samples/bucket', 'cereus_insights'); ?></td>
				<td style="text-align:right;color:<?php print _cis_readiness_color(min(100, round($avg_samples / $min_samples * 100))); ?>;"><?php
					print __('%d of %d needed', $avg_samples, $min_samples, 'cereus_insights');
				?></td>
			</tr>
			<?php endif; ?>
			<!-- Breaches -->
			<tr style="border-top:1px solid #eee;">
				<td style="color:#666;padding:6px 0 2px;"><?php print __('Breaches (24h)', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;color:<?php print $breaches_24h > 0 ? '#e74c3c' : '#27ae60'; ?>;"><?php print $breaches_24h; ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Breaches (7d)', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;color:<?php print $breaches_7d > 0 ? '#e67e22' : '#27ae60'; ?>;"><?php print $breaches_7d; ?></td>
			</tr>
		</table>
	<?php endif; ?>
	</div>

	<!-- ===================== FORECASTS ===================== -->
	<div style="flex:1;min-width:220px;background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px;">
		<div style="font-weight:bold;font-size:13px;border-bottom:1px solid #eee;padding-bottom:6px;margin-bottom:8px;">
			&#128202; <?php print __('Capacity Forecasts', 'cereus_insights'); ?>
		</div>
		<table style="width:100%;font-size:12px;border-collapse:collapse;">
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Forecasts computed', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;"><?php print number_format($forecast_count); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Data sources covered', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;"><?php print $forecast_ldi . ' / ' . $total_ldi . ' (' . $coverage_f_pct . '%)'; ?></td>
			</tr>
			<tr><td colspan="2"><?php print _cis_bar($coverage_f_pct, '#2ecc71'); ?></td></tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Batch cursor', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;"><?php print 'ID ' . $forecast_cursor . ' (' . $forecast_pct . '%)'; ?></td>
			</tr>
			<tr><td colspan="2"><?php print _cis_bar($forecast_pct); ?></td></tr>
			<tr>
				<td style="color:#666;padding:6px 0 2px;"><?php print __('Last run', 'cereus_insights'); ?></td>
				<td style="text-align:right;"><?php print _cis_ago($last_forecast_ts); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Next run', 'cereus_insights'); ?></td>
				<td style="text-align:right;"><?php
					if ($forecast_cursor > 0) {
						print '<span style="color:#3498db;">' . __('In progress', 'cereus_insights') . '</span>';
					} else {
						print _cis_in($next_forecast);
					}
				?></td>
			</tr>
			<!-- Forecast quality -->
			<tr style="border-top:1px solid #eee;">
				<td style="color:#666;padding:6px 0 2px;font-weight:bold;"><?php print __('Forecast quality', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;color:<?php print _cis_readiness_color($quality_pct); ?>;"><?php
					print $forecast_count > 0 ? $quality_pct . '%' : __('No data', 'cereus_insights');
				?></td>
			</tr>
			<?php if ($forecast_count > 0): ?>
			<tr><td colspan="2"><?php print _cis_bar($quality_pct, _cis_readiness_color($quality_pct)); ?></td></tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Avg r² score', 'cereus_insights'); ?></td>
				<td style="text-align:right;color:<?php print _cis_readiness_color(min(100, (int)round($avg_r2 / 0.6 * 100))); ?>;"><?php
					print number_format($avg_r2, 2) . ' ' . __('(need &ge; 0.3)', 'cereus_insights');
				?></td>
			</tr>
			<?php endif; ?>
			<!-- Capacity alerts -->
			<tr style="border-top:1px solid #eee;">
				<td style="color:#666;padding:6px 0 2px;"><?php
					print __('Breaching within %d days', $warn_days, 'cereus_insights');
				?></td>
				<td style="text-align:right;font-weight:bold;color:<?php print $forecast_soon > 0 ? '#e74c3c' : '#27ae60'; ?>;"><?php print $forecast_soon; ?></td>
			</tr>
		</table>
	</div>

	<!-- ===================== THRESHOLD SUGGESTIONS ===================== -->
	<div style="flex:1;min-width:220px;background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px;">
		<div style="font-weight:bold;font-size:13px;border-bottom:1px solid #eee;padding-bottom:6px;margin-bottom:8px;">
			&#127919; <?php print __('Threshold Suggestions', 'cereus_insights'); ?>
		</div>
	<?php if (!$is_professional): ?>
		<span style="color:#888;font-size:12px;"><?php print __('Requires Professional license', 'cereus_insights'); ?></span>
	<?php else: ?>
		<table style="width:100%;font-size:12px;border-collapse:collapse;">
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Baselines with suggestions', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;"><?php print number_format($suggest_cache_total); ?></td>
			</tr>
			<tr style="border-top:1px solid #eee;">
				<td style="color:#666;padding:6px 0 2px;font-weight:bold;"><?php print __('Eligible (no threshold yet)', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;color:<?php print $suggest_eligible > 0 ? '#e67e22' : '#27ae60'; ?>;"><?php print number_format($suggest_eligible); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('High confidence (&ge;75%)', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;color:<?php print $suggest_eligible_high > 0 ? '#27ae60' : '#888'; ?>;"><?php print number_format($suggest_eligible_high); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Avg confidence', 'cereus_insights'); ?></td>
				<td style="text-align:right;"><?php print $suggest_avg_conf . '%'; ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Skipped', 'cereus_insights'); ?></td>
				<td style="text-align:right;"><?php print number_format($suggest_skipped); ?></td>
			</tr>
		</table>
	<?php endif; ?>
	</div>

	<!-- ===================== AI SUMMARIES ===================== -->
	<div style="flex:1;min-width:220px;background:#fff;border:1px solid #ddd;border-radius:4px;padding:12px;">
		<div style="font-weight:bold;font-size:13px;border-bottom:1px solid #eee;padding-bottom:6px;margin-bottom:8px;">
			&#129302; <?php print __('AI Alert Summaries', 'cereus_insights'); ?>
		</div>
	<?php if (!$is_enterprise): ?>
		<span style="color:#888;font-size:12px;"><?php print __('Requires Enterprise license', 'cereus_insights'); ?></span>
	<?php else: ?>
		<table style="width:100%;font-size:12px;border-collapse:collapse;">
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Alert queue depth', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;color:<?php print $queue_depth > 0 ? '#e67e22' : '#27ae60'; ?>;"><?php print $queue_depth; ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Summaries generated', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;"><?php print number_format($summary_count); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Total tokens used', 'cereus_insights'); ?></td>
				<td style="text-align:right;font-weight:bold;"><?php print number_format($total_tokens); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Avg alerts/summary', 'cereus_insights'); ?></td>
				<td style="text-align:right;"><?php print number_format($avg_alerts, 1); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:6px 0 2px;"><?php print __('Last summary', 'cereus_insights'); ?></td>
				<td style="text-align:right;"><?php print $last_summary ? html_escape($last_summary) : __('Never', 'cereus_insights'); ?></td>
			</tr>
			<tr>
				<td style="color:#666;padding:2px 0;"><?php print __('Batch window', 'cereus_insights'); ?></td>
				<td style="text-align:right;"><?php
					$win = (int)(read_config_option('cereus_insights_llm_batch_window') ?: CEREUS_INSIGHTS_DEFAULT_LLM_BATCH_WIN);
					print $win >= 60 ? sprintf(__('%d min', 'cereus_insights'), (int)($win/60)) : sprintf(__('%d sec', 'cereus_insights'), $win);
				?></td>
			</tr>
		</table>
	<?php endif; ?>
	</div>

	</div><!-- end flex row -->
	<?php
}
