<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Help & Documentation                                  |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cereus_insights/includes/constants.php');
include_once('./plugins/cereus_insights/lib/license_check.php');
include_once('./plugins/cereus_insights/includes/tab_bar.php');

top_header();
cereus_insights_help_page();
bottom_footer();

/* =========================================================================
 * Help Page
 * ====================================================================== */

function cereus_insights_help_page() {
	cereus_insights_tab_bar('help');

	/* ---- Overview ---- */
	html_start_box(__('Cereus Insights — Overview', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr><td style="padding:12px 16px;">
	<p><?php print __('Cereus Insights adds four intelligence layers on top of Cacti\'s existing data:', 'cereus_insights'); ?></p>
	<table class="cactiTable" style="width:100%;max-width:900px;">
		<thead><tr class="tableHeader">
			<th><?php print __('Feature', 'cereus_insights'); ?></th>
			<th><?php print __('License Tier', 'cereus_insights'); ?></th>
			<th><?php print __('What it produces', 'cereus_insights'); ?></th>
		</tr></thead>
		<tbody>
		<tr class="odd"><td><strong><?php print __('Capacity Forecasting', 'cereus_insights'); ?></strong></td>
			<td><?php print __('Community+', 'cereus_insights'); ?></td>
			<td><?php print __('Predicts when each datasource will saturate, based on linear trend', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('Anomaly Detection', 'cereus_insights'); ?></strong></td>
			<td><?php print __('Professional+', 'cereus_insights'); ?></td>
			<td><?php print __('Flags values that deviate from the normal pattern for that time of day/week', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><strong><?php print __('Threshold Suggestions', 'cereus_insights'); ?></strong></td>
			<td><?php print __('Professional+', 'cereus_insights'); ?></td>
			<td><?php print __('Analyses baselines to suggest thold_hi / thold_low values with confidence scores; one-click creation', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('LLM Alert Summarization', 'cereus_insights'); ?></strong></td>
			<td><?php print __('Enterprise', 'cereus_insights'); ?></td>
			<td><?php print __('Batches Thold breach events and generates a plain-English summary via a configured LLM provider (Anthropic, OpenAI, or Google)', 'cereus_insights'); ?></td></tr>
		</tbody>
	</table>
	</td></tr>
	<?php
	html_end_box();

	/* ---- Data Sources ---- */
	html_start_box(__('Data Sources', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr><td style="padding:12px 16px;">
	<p><?php print __('Cereus Insights works with every active datasource in Cacti — no per-datasource configuration required. It reads directly from RRD files using the same paths Cacti uses internally.', 'cereus_insights'); ?></p>

	<p><strong><?php print __('For forecasting:', 'cereus_insights'); ?></strong>
	<?php print __('Any datasource with at least 3 days of history will produce a forecast. Datasources with a Thold <code>thold_hi</code> value configured get the most useful results — the saturation forecast tells you how many days until that threshold is hit. For inverted metrics (datasource names containing "free", "avail", or "idle") the forecast targets <code>thold_low</code> instead, and a saturation date is only projected when the value is declining. Growing free space is not treated as a capacity problem. Datasources without any Thold threshold still get trend/slope data but show no saturation date.', 'cereus_insights'); ?></p>

	<p><strong><?php print __('For anomaly detection:', 'cereus_insights'); ?></strong>
	<?php print __('Any datasource with at least 50 data points in a given hour+day-of-week bucket (default minimum) will produce a baseline. At 5-minute polling intervals, 50 samples per bucket requires roughly 25 weeks of history for a specific hour on a specific weekday. You can lower the Minimum Samples setting to activate detection sooner.', 'cereus_insights'); ?></p>

	<p><strong><?php print __('Datasource exclusion list:', 'cereus_insights'); ?></strong>
	<?php print __('Individual datasource names can be excluded globally from the Threshold Suggestions page using the "Exclude DS Name" control. Excluded names are hidden from threshold suggestions and are also skipped during baseline computation and forecast processing. Use this to suppress noisy, constant-value, or irrelevant datasources across the entire plugin.', 'cereus_insights'); ?></p>

	<table class="cactiTable" style="width:100%;max-width:700px;margin-top:10px;">
		<thead><tr class="tableHeader">
			<th><?php print __('Produces good results', 'cereus_insights'); ?></th>
			<th><?php print __('Produces noisy / unhelpful results', 'cereus_insights'); ?></th>
		</tr></thead>
		<tbody><tr class="odd"><td style="vertical-align:top;">
			<ul style="margin:4px 0 4px 18px;">
				<li><?php print __('Interface traffic (traffic_in, traffic_out)', 'cereus_insights'); ?></li>
				<li><?php print __('CPU utilization', 'cereus_insights'); ?></li>
				<li><?php print __('Disk usage / disk I/O', 'cereus_insights'); ?></li>
				<li><?php print __('Memory utilization', 'cereus_insights'); ?></li>
				<li><?php print __('Response time / latency', 'cereus_insights'); ?></li>
				<li><?php print __('Temperature sensors', 'cereus_insights'); ?></li>
			</ul>
		</td><td style="vertical-align:top;">
			<ul style="margin:4px 0 4px 18px;">
				<li><?php print __('Counters that reset frequently', 'cereus_insights'); ?></li>
				<li><?php print __('Very sparse data (polling intervals &gt;15 min)', 'cereus_insights'); ?></li>
				<li><?php print __('Datasources with constant or near-zero values', 'cereus_insights'); ?></li>
			</ul>
		</td></tr></tbody>
	</table>
	</td></tr>
	<?php
	html_end_box();

	/* ---- Poller ---- */
	html_start_box(__('How the Poller Works', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr><td style="padding:12px 16px;">
	<p><?php print __('The plugin hooks into <code>poller_bottom</code> and runs at the end of every Cacti poll cycle (default every 5 minutes). Datasources are processed in batches using a round-robin cursor so the poller is never blocked.', 'cereus_insights'); ?></p>
	<table class="cactiTable" style="width:100%;max-width:900px;">
		<thead><tr class="tableHeader">
			<th><?php print __('Task', 'cereus_insights'); ?></th>
			<th><?php print __('Frequency', 'cereus_insights'); ?></th>
			<th><?php print __('Tier', 'cereus_insights'); ?></th>
		</tr></thead>
		<tbody>
		<tr class="odd"><td><?php print __('Anomaly detection (check current values)', 'cereus_insights'); ?></td>
			<td><?php print __('Every poll cycle (5 min)', 'cereus_insights'); ?></td>
			<td><?php print __('Professional+', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><?php print __('Baseline computation (rebuild seasonality profiles)', 'cereus_insights'); ?></td>
			<td><?php print __('Every poll cycle, N datasources per cycle', 'cereus_insights'); ?></td>
			<td><?php print __('Professional+', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><?php print __('Forecast computation (recalculate trends)', 'cereus_insights'); ?></td>
			<td><?php print __('Every 1 hour, N datasources per cycle', 'cereus_insights'); ?></td>
			<td><?php print __('Community+', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><?php print __('LLM queue flush', 'cereus_insights'); ?></td>
			<td><?php print __('Every poll cycle (when queue age ≥ batch window)', 'cereus_insights'); ?></td>
			<td><?php print __('Enterprise', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><?php print __('Data purge', 'cereus_insights'); ?></td>
			<td><?php print __('Daily', 'cereus_insights'); ?></td>
			<td><?php print __('All', 'cereus_insights'); ?></td></tr>
		</tbody>
	</table>
	<p style="margin-top:8px;"><em><?php print __('On a Cacti instance with 1,000 datasources at batch size 500, a full baseline pass takes approximately 2 poll cycles (~10 minutes). Batch size is configurable — reduce it if the poller shows elevated runtimes.', 'cereus_insights'); ?></em></p>
	</td></tr>
	<?php
	html_end_box();

	/* ---- Anomaly Detection Settings ---- */
	html_start_box(__('Anomaly Detection Settings (Professional+)', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr><td style="padding:12px 16px;">
	<p><?php print __('Go to <strong>Console → Configuration → Settings → Cereus Insights</strong> to configure these options.', 'cereus_insights'); ?></p>
	<table class="cactiTable" style="width:100%;max-width:900px;">
		<thead><tr class="tableHeader">
			<th><?php print __('Setting', 'cereus_insights'); ?></th>
			<th><?php print __('Default', 'cereus_insights'); ?></th>
			<th><?php print __('Notes', 'cereus_insights'); ?></th>
		</tr></thead>
		<tbody>
		<tr class="odd"><td><strong><?php print __('Sigma Threshold', 'cereus_insights'); ?></strong></td>
			<td>3</td>
			<td><?php print __('A value must be 3× the standard deviation from its hourly baseline to trigger an anomaly. Lower = more sensitive (more noise). Typical range: 2–4.', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('Minimum Samples per Bucket', 'cereus_insights'); ?></strong></td>
			<td>50</td>
			<td><?php print __('Minimum data points required in an hour+day-of-week bucket before anomalies are reported. Lower this for sparse data or faster startup (try 20).', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><strong><?php print __('Baseline History', 'cereus_insights'); ?></strong></td>
			<td><?php print __('30 days', 'cereus_insights'); ?></td>
			<td><?php print __('Days of RRD history used to compute baselines. 30 days = ~4 weeks of hourly patterns. Increase for more stable baselines on variable data.', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('Anomaly Breach Retention', 'cereus_insights'); ?></strong></td>
			<td><?php print __('30 days', 'cereus_insights'); ?></td>
			<td><?php print __('How long anomaly records are kept in the database.', 'cereus_insights'); ?></td></tr>
		</tbody>
	</table>

	<h3 style="margin-top:14px;"><?php print __('How the sigma threshold works', 'cereus_insights'); ?></h3>
	<p><?php print __('For each hour of the day × day of the week (168 buckets total), Cereus Insights stores the mean and standard deviation of all historical values at that time. At each poll cycle it computes:', 'cereus_insights'); ?></p>
	<pre style="background:#f4f4f4;border:1px solid #ddd;padding:8px 12px;margin:8px 0;font-family:monospace;">z-score = (current_value &minus; mean) / stddev</pre>
	<p><?php print __('If <code>abs(z-score) &gt; sigma</code>, an anomaly is recorded. A sigma of 3 means the value is more than 3 standard deviations from what is normal for that specific time — statistically, this occurs by chance less than 0.3% of the time in a normal distribution.', 'cereus_insights'); ?></p>
	<p><?php print __('<strong>Deduplication:</strong> The same datasource will not produce more than one anomaly record per 15-minute window, regardless of consecutive breaches.', 'cereus_insights'); ?></p>
	</td></tr>
	<?php
	html_end_box();

	/* ---- Capacity Forecasting Settings ---- */
	html_start_box(__('Capacity Forecasting Settings (Community+)', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr><td style="padding:12px 16px;">
	<table class="cactiTable" style="width:100%;max-width:900px;">
		<thead><tr class="tableHeader">
			<th><?php print __('Setting', 'cereus_insights'); ?></th>
			<th><?php print __('Default', 'cereus_insights'); ?></th>
			<th><?php print __('Notes', 'cereus_insights'); ?></th>
		</tr></thead>
		<tbody>
		<tr class="odd"><td><strong><?php print __('Forecast History', 'cereus_insights'); ?></strong></td>
			<td><?php print __('90 days', 'cereus_insights'); ?></td>
			<td><?php print __('Days of RRD history for linear regression. More history = smoother trend but slower to respond to recent changes.', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('Saturation Threshold (%)', 'cereus_insights'); ?></strong></td>
			<td>90%</td>
			<td><?php print __('The forecast targets this percentage of the Thold threshold value. E.g. if thold_hi = 100 Mbps and this is 90%, the forecast date is when the trend reaches 90 Mbps.', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><strong><?php print __('Forecast Warning Threshold', 'cereus_insights'); ?></strong></td>
			<td><?php print __('30 days', 'cereus_insights'); ?></td>
			<td><?php print __('Rows are highlighted orange when saturation is within this many days, red when within 7 days.', 'cereus_insights'); ?></td></tr>
		</tbody>
	</table>

	<h3 style="margin-top:14px;"><?php print __('How the forecast works', 'cereus_insights'); ?></h3>
	<p><?php print __('For each datasource, daily maximum values are extracted from the RRD file and a least-squares linear regression is computed over the configured history window. The slope (change per second) is extrapolated to determine when the trend line will cross the saturation threshold.', 'cereus_insights'); ?></p>
	<p><?php print __('<strong>Direction awareness:</strong> Datasource names containing "free", "avail", or "idle" are treated as inverted metrics. For these, the forecast targets <code>thold_low</code> and only produces a saturation date when the slope is negative (the value is declining toward the threshold). If free space is growing, the forecast date shows "Never / Stable". For all other datasources, the forecast targets <code>thold_hi</code> as normal.', 'cereus_insights'); ?></p>
	<p><?php print __('Forecasts are recalculated every hour. R² values below 0.5 indicate a weak trend — treat the saturation date as approximate in those cases.', 'cereus_insights'); ?></p>
	<p><?php print __('<strong>"Never / Stable"</strong> in the forecast date column means the slope does not trend toward the threshold, or no Thold threshold is configured.', 'cereus_insights'); ?></p>
	</td></tr>
	<?php
	html_end_box();

	/* ---- Threshold Suggestions ---- */
	html_start_box(__('Threshold Suggestions (Professional+)', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr><td style="padding:12px 16px;">
	<p><?php print __('The Threshold Suggestions page analyses computed baselines to recommend <code>thold_hi</code> or <code>thold_low</code> values for datasources that do not yet have Thold thresholds configured. Suggestions are ranked by confidence score.', 'cereus_insights'); ?></p>

	<h3><?php print __('Direction awareness', 'cereus_insights'); ?></h3>
	<p><?php print __('Cereus Insights automatically selects the correct threshold direction based on the datasource name:', 'cereus_insights'); ?></p>
	<table class="cactiTable" style="width:100%;max-width:800px;">
		<thead><tr class="tableHeader">
			<th><?php print __('Datasource name contains', 'cereus_insights'); ?></th>
			<th><?php print __('Suggested threshold', 'cereus_insights'); ?></th>
			<th><?php print __('Alert fires when', 'cereus_insights'); ?></th>
		</tr></thead>
		<tbody>
		<tr class="odd"><td><code>free</code>, <code>avail</code>, <code>idle</code></td>
			<td><code>thold_low</code></td>
			<td><?php print __('Value drops below threshold (resource running out)', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><?php print __('anything else', 'cereus_insights'); ?></td>
			<td><code>thold_hi</code></td>
			<td><?php print __('Value rises above threshold (utilization too high)', 'cereus_insights'); ?></td></tr>
		</tbody>
	</table>

	<h3 style="margin-top:14px;"><?php print __('Confidence scoring', 'cereus_insights'); ?></h3>
	<p><?php print __('Each suggestion includes a confidence percentage: the proportion of the 168 hour×day-of-week buckets that contain at least the configured minimum number of samples. A score of 100% means every time bucket has sufficient data; 50% means half the hourly slots are still accumulating history.', 'cereus_insights'); ?></p>
	<p><?php print __('The <strong>Confidence Min Samples</strong> setting (default: 3) controls how many samples a bucket must contain to be counted as populated. The default of 3 is appropriate when baselines are built from 2-hour RRD averages over a 30-day lookback.', 'cereus_insights'); ?></p>

	<h3 style="margin-top:14px;"><?php print __('Page controls', 'cereus_insights'); ?></h3>
	<table class="cactiTable" style="width:100%;max-width:800px;">
		<thead><tr class="tableHeader">
			<th><?php print __('Control', 'cereus_insights'); ?></th>
			<th><?php print __('Description', 'cereus_insights'); ?></th>
		</tr></thead>
		<tbody>
		<tr class="odd"><td><strong><?php print __('Create (per row)', 'cereus_insights'); ?></strong></td>
			<td><?php print __('Creates the suggested Thold threshold for that datasource in one click. Does not overwrite existing thresholds.', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('Skip (per row)', 'cereus_insights'); ?></strong></td>
			<td><?php print __('Permanently hides that datasource from the suggestions list. Skipped datasources are not excluded from anomaly detection or forecasting.', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><strong><?php print __('Exclude DS Name', 'cereus_insights'); ?></strong></td>
			<td><?php print __('Adds the datasource name to the global exclusion list. All datasources with that name are removed from suggestions, anomaly detection, and forecast processing across the entire plugin.', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('Explain (Enterprise)', 'cereus_insights'); ?></strong></td>
			<td><?php print __('Sends the baseline data for that datasource to the configured LLM provider and returns a plain-English explanation of the suggested value.', 'cereus_insights'); ?></td></tr>
		</tbody>
	</table>
	</td></tr>
	<?php
	html_end_box();

	/* ---- LLM Settings ---- */
	html_start_box(__('LLM Alert Summarization Settings (Enterprise)', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr><td style="padding:12px 16px;">
	<table class="cactiTable" style="width:100%;max-width:900px;">
		<thead><tr class="tableHeader">
			<th><?php print __('Setting', 'cereus_insights'); ?></th>
			<th><?php print __('Default', 'cereus_insights'); ?></th>
			<th><?php print __('Notes', 'cereus_insights'); ?></th>
		</tr></thead>
		<tbody>
		<tr class="odd"><td><strong><?php print __('LLM Provider', 'cereus_insights'); ?></strong></td>
			<td>Anthropic</td>
			<td><?php print __('Select the LLM provider: Anthropic (Claude), OpenAI (GPT), or Google (Gemini). Each provider requires its own API key and uses its own model list.', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('LLM API Key', 'cereus_insights'); ?></strong></td>
			<td>—</td>
			<td><?php print __('API key for the selected provider. Use the "Test API Key" button to validate the key against the selected provider before saving. Required for this feature to activate.', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><strong><?php print __('LLM Model', 'cereus_insights'); ?></strong></td>
			<td>claude-haiku-4-5-20251001</td>
			<td><?php print __('Model identifier for the selected provider. Haiku / GPT-4o-mini / Gemini Flash are fast and inexpensive. Use a larger model for higher-quality summaries.', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('LLM Batch Window', 'cereus_insights'); ?></strong></td>
			<td><?php print __('300 seconds', 'cereus_insights'); ?></td>
			<td><?php print __('Cereus Insights accumulates Thold breach events for this many seconds before sending them to the LLM as one batch. This allows correlated events (e.g. a switch going down causing 40 simultaneous alerts) to be summarised together.', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><strong><?php print __('Summary Notify Email', 'cereus_insights'); ?></strong></td>
			<td>—</td>
			<td><?php print __('Email address to receive summaries. Leave blank to use the Cacti admin email from Settings → Mail/DNS.', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><strong><?php print __('LLM Summary Retention', 'cereus_insights'); ?></strong></td>
			<td><?php print __('90 days', 'cereus_insights'); ?></td>
			<td><?php print __('How long LLM summary records are kept in the database.', 'cereus_insights'); ?></td></tr>
		</tbody>
	</table>
	<p style="margin-top:8px;"><em><?php print __('Approximate cost (Anthropic): At current pricing, claude-haiku-4-5 costs roughly $0.001–0.003 per batch. A busy Cacti instance firing 10 batches per day costs under $0.03/day.', 'cereus_insights'); ?></em></p>
	</td></tr>
	<?php
	html_end_box();

	/* ---- First-run Timeline ---- */
	html_start_box(__('First-Run Timeline', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr><td style="padding:12px 16px;">
	<p><?php print __('After enabling the plugin, this is roughly what to expect:', 'cereus_insights'); ?></p>
	<table class="cactiTable" style="width:100%;max-width:900px;">
		<thead><tr class="tableHeader">
			<th><?php print __('Time after install', 'cereus_insights'); ?></th>
			<th><?php print __('What happens', 'cereus_insights'); ?></th>
		</tr></thead>
		<tbody>
		<tr class="odd"><td><?php print __('First poll cycle', 'cereus_insights'); ?></td>
			<td><?php print __('Baseline cursor starts; first batch of datasources get 30-day history bucketed into 168 hour/day profiles', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><?php print __('~1 hour', 'cereus_insights'); ?></td>
			<td><?php print __('Forecast cursor starts; first batch of datasources get 90-day RRD data fetched and regression computed', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><?php print __('~10 minutes (1,000 datasources at batch 500)', 'cereus_insights'); ?></td>
			<td><?php print __('First full pass of baselines complete; Threshold Suggestions page begins populating', 'cereus_insights'); ?></td></tr>
		<tr class="even"><td><?php print __('~2 days', 'cereus_insights'); ?></td>
			<td><?php print __('Anomaly detection begins firing for datasources whose buckets have accumulated enough samples; datasources with 30+ days history activate immediately', 'cereus_insights'); ?></td></tr>
		<tr class="odd"><td><?php print __('~4 weeks', 'cereus_insights'); ?></td>
			<td><?php print __('All 168 buckets populated for most datasources; baseline quality and threshold suggestion confidence scores are at their highest', 'cereus_insights'); ?></td></tr>
		</tbody>
	</table>
	<p style="margin-top:8px;"><?php print __('<strong>Tip:</strong> Batch Size defaults to 500, which is appropriate for most instances. Reduce it if the poller shows elevated runtimes.', 'cereus_insights'); ?></p>
	</td></tr>
	<?php
	html_end_box();

	/* ---- Troubleshooting ---- */
	html_start_box(__('Troubleshooting', 'cereus_insights'), '100%', '', '3', 'center', '');
	?>
	<tr><td style="padding:12px 16px;">
	<h3><?php print __('Forecasts page is empty', 'cereus_insights'); ?></h3>
	<ul style="margin:4px 0 12px 18px;">
		<li><?php print __('Wait for at least one 1-hour forecast cycle to complete after install', 'cereus_insights'); ?></li>
		<li><?php print __('Check that datasources have at least 3 days of RRD history', 'cereus_insights'); ?></li>
		<li><?php print __('Confirm the poller is running: check Cacti logs for <code>CEREUS INSIGHTS: cycle complete</code>', 'cereus_insights'); ?></li>
	</ul>

	<h3><?php print __('Anomaly Breaches page is empty', 'cereus_insights'); ?></h3>
	<ul style="margin:4px 0 12px 18px;">
		<li><?php print __('Anomaly detection requires a Professional license', 'cereus_insights'); ?></li>
		<li><?php print __('Baselines need time to build — expect 1–2 days before breaches appear on new datasources', 'cereus_insights'); ?></li>
		<li><?php print __('Lower Minimum Samples per Bucket if data is sparse (try 20)', 'cereus_insights'); ?></li>
	</ul>

	<h3><?php print __('Threshold Suggestions page is empty', 'cereus_insights'); ?></h3>
	<ul style="margin:4px 0 12px 18px;">
		<li><?php print __('Threshold suggestions require a Professional license', 'cereus_insights'); ?></li>
		<li><?php print __('Baselines must complete at least one full pass before suggestions appear — check the Status box on the Anomaly Breaches page', 'cereus_insights'); ?></li>
		<li><?php print __('Datasources already configured with a Thold threshold are excluded from suggestions', 'cereus_insights'); ?></li>
		<li><?php print __('Check whether datasource names have been added to the global exclusion list on the Threshold Suggestions page', 'cereus_insights'); ?></li>
	</ul>

	<h3><?php print __('LLM Summaries not appearing', 'cereus_insights'); ?></h3>
	<ul style="margin:4px 0 12px 18px;">
		<li><?php print __('Confirm Enterprise license is active', 'cereus_insights'); ?></li>
		<li><?php print __('Check that the LLM API key is saved in Settings and the correct Provider is selected', 'cereus_insights'); ?></li>
		<li><?php print __('Use the "Test API Key" button in Settings to verify the key is valid', 'cereus_insights'); ?></li>
		<li><?php print __('Confirm Thold is installed and firing alerts — no Thold alerts means nothing to summarise', 'cereus_insights'); ?></li>
		<li><?php print __('Check Cacti logs for <code>CEREUS INSIGHTS: LLM flush</code> entries', 'cereus_insights'); ?></li>
	</ul>

	<h3><?php print __('Poller log shows SQL errors', 'cereus_insights'); ?></h3>
	<ul style="margin:4px 0 12px 18px;">
		<li><?php print __('Confirm plugin tables exist: <code>SHOW TABLES LIKE \'plugin_cereus_insights%\'</code> — should return 9 tables', 'cereus_insights'); ?></li>
		<li><?php print __('If missing, reinstall via Console → Configuration → Plugins', 'cereus_insights'); ?></li>
	</ul>

	<h3><?php print __('High poller runtime', 'cereus_insights'); ?></h3>
	<ul style="margin:4px 0 12px 18px;">
		<li><?php print __('Reduce Batch Size (default 500 — try 100–200 on large instances with limited I/O)', 'cereus_insights'); ?></li>
		<li><?php print __('RRD fetch is the expensive operation — each datasource requires one <code>rrdtool fetch</code> call', 'cereus_insights'); ?></li>
		<li><?php print __('Add noisy or irrelevant datasource names to the global exclusion list to reduce per-cycle work', 'cereus_insights'); ?></li>
	</ul>
	</td></tr>
	<?php
	html_end_box();
}
