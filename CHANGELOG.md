# Changelog

All notable changes to Cereus Insights are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.1.6] - 2026-04-23

### Fixed
- `last_value` is a **reserved word in MySQL 8.0** (window function). Renamed the column to `last_rrd_value` in `plugin_cereus_insights_forecasts`. Updated all SQL INSERT/UPDATE statements in `lib/forecast.php` and the display in `cereus_insights_forecasts.php`. This was the root cause of the forecasts table failing to create on MySQL 8.0 servers.

---

## [1.1.5] - 2026-04-23

### Fixed
- `plugin_cereus_insights_forecasts` table was silently failing to create on some MySQL/MariaDB configurations. Simplified the CREATE TABLE: `MEDIUMINT` → `INT`, `FLOAT` → `DOUBLE`, `SMALLINT NULL DEFAULT NULL` → `INT DEFAULT NULL`, `DATE NULL DEFAULT NULL` → `VARCHAR(10) DEFAULT NULL`, removed the indexes on nullable columns from CREATE TABLE (added via ALTER TABLE instead). This removes the combinations most likely to fail in strict or older MySQL environments.
- `cereus_insights_tables_installed()` now re-verifies after calling `cereus_insights_setup_tables()` instead of blindly setting `$installed = true`. If any table is still missing after setup, logs the exact table name(s) to Cacti's log so the administrator can see the root cause in `cacti.log`.

---

## [1.1.4] - 2026-04-23

### Fixed
- `cereus_insights_tables_installed()` now verifies all 13 plugin tables, not just the sentinel (`plugin_cereus_insights_seen`). On partially-installed systems where `seen` exists but other tables (e.g. `forecasts`) are missing, the self-healing now correctly detects and creates the missing tables.
- Added explicit `CREATE TABLE IF NOT EXISTS plugin_cereus_insights_forecasts` to the poller migration block so the next poller cycle will also create the table if it is missing — previously only an `ALTER TABLE ... ADD INDEX` was present, which would silently fail on a missing table.

---

## [1.1.3] - 2026-04-23

### Fixed
- `cereus_insights_tables_installed()` now **auto-creates all plugin tables** on first page visit if they are missing, by including `setup.php` and calling `cereus_insights_setup_tables()`. This means the plugin works immediately after copying files even if the Cacti Plugin Manager install step was skipped or failed — the first page visit self-heals the install. Subsequent calls use a static cache (one DB check per request).
- Removed the misleading "wait for next poller cycle" message — replaced with "Plugin initializing — please reload" which is only shown in the extreme case where `setup.php` itself is not accessible.

---

## [1.1.2] - 2026-04-23

### Fixed
- All UI pages now show a friendly message instead of DB errors when visited before tables exist.

---

## [1.1.1] - 2026-04-23

### Fixed
- Weekly Reports page: replaced bare-bones toggle with proper Cacti table (columns: Generated, Period, Model, Tokens, View/Hide button); JS uses event delegation; report body renders in a styled expandable row
- Weekly Reports page: improved empty state with configuration guidance

### Changed
- Weekly intelligence report now includes a fourth section — **Alert Summary Review** — covering key themes from LLM alert summaries generated during the week
- Report payload extended with: `alert_summaries_generated`, `alerts_summarized_7d`, `avg_alerts_per_summary`, `recent_alert_summaries`
- Email stats table extended with alert summary counts
- LLM max_tokens for weekly report raised from 600 to 800

---

## [1.1.0] - 2026-04-23

### Added

**Weekly Intelligence Report (Enterprise)**
- Configurable weekly schedule (day of week, hour) generates a plain-English infrastructure report via the configured LLM provider
- Report covers: top capacity concerns by days-to-saturation, top anomalous devices (7d), threshold coverage stats
- Delivered by email and stored for review on the new Weekly Reports page (`cereus_insights_reports.php`)
- Settings: enable/disable, day, hour, recipient email, items per section

**Anomaly Noise Scoring + Sigma Suggestions (Professional)**
- Daily purge cross-references anomaly records against `plugin_thold_log` (±30 min window) to classify each anomaly as signal (followed by a thold breach) or noise
- Per-datasource stats table (`plugin_cereus_insights_anomaly_stats`) tracks total, signal, noise counts and noise %
- Suggested sigma: +0.5 when noise ≥70%, +1.0 when noise ≥90%, capped at σ=5
- Per-datasource sigma overrides (`plugin_cereus_insights_sigma_overrides`) — overrides are applied in anomaly detection without affecting the global setting
- "Noise Analysis" panel on the Anomaly Breaches page with Apply σ and Reset buttons
- Apply σ writes an override; Reset removes it; both take effect at the next poller cycle

**Other**
- Enable/disable checkbox for LLM Alert Summarization (Settings → LLM Alert Summarization)
- HTTP Proxy setting for LLM API calls (`http://`, `socks5://` — all three providers)

---

## [1.0.0] - 2026-04-23

### Added

**Capacity Forecasting (Community+)**
- Linear-regression trend analysis over configurable RRD history (default 90 days)
- Days-to-saturation forecast per datasource against `thold_hi` (or `thold_low` for inverted metrics)
- Direction-aware forecasting: `*_free`, `*_avail`, `*_idle` datasources only project a saturation date when the value is actively declining (slope < 0), preventing false urgency on growing free-space metrics
- R² quality score per forecast — values below 0.3 indicate insufficient trend data
- Saturation warning threshold (default 30 days): colour-coded badges on the Forecasts page
- Batch cursor with configurable interval (default 1 hour) and batch size (default 500)

**Anomaly Detection (Professional+)**
- Seasonality baselines per `(hour_of_day × day_of_week)` bucket — 168 buckets per datasource
- Z-score detection with configurable sigma threshold (default 3)
- Configurable minimum samples per bucket before detection activates (default 50)
- Configurable baseline history window (default 30 days)
- 15-minute breach deduplication per datasource
- Batch cursor advancing every poller cycle

**Threshold Suggestions (Professional+)**
- Suggestion cache derived from baseline data: `mean + N×σ` for normal metrics, `mean − N×σ` (with percentage floor) for inverted metrics
- Direction-aware: datasource names containing `free`, `avail`, `remain`, `idle`, or `unused` receive `thold_low` suggestions; all others receive `thold_hi`
- Confidence score: percentage of time buckets with `sample_count ≥ N` (configurable, default 3)
- Per-row **Create** (applies threshold to Thold with one click), **Skip** (hides the row), and **Explain** (Enterprise: LLM rationale) actions
- **Exclude DS Name** field: adds a datasource name to the global exclusion list
- Global datasource exclusion list suppresses named datasources across suggestions, anomaly detection, and forecasting simultaneously
- Suggestion cache pre-aggregated by the poller; page reads a flat indexed table instead of aggregating baseline rows on demand

**LLM Alert Summarization (Enterprise)**
- Ingests Thold breach events into an alert queue with configurable cooldown deduplication
- Batches queued events and generates a plain-English summary via configurable LLM provider
- Supported providers: **Anthropic** (Claude), **OpenAI** (GPT), **Google** (Gemini)
- Provider and model configurable in Settings; "Test API Key" button validates before saving
- Configurable batch window (default 300 seconds) to correlate related alerts
- Summary delivered by email; stored with full alert detail for review on the AI Summaries page
- **HTTP Proxy** setting for environments where outbound internet requires a proxy (`http://`, `socks5://`)
- Enable/disable toggle in Settings without losing configuration

**Status Box**
- Four panels: Anomaly Baselines, Capacity Forecasts, Threshold Suggestions, AI Alert Summaries
- Baselines panel: detection readiness % (buckets at or above min-samples threshold), average samples/bucket
- Forecasts panel: quality % (R² ≥ 0.3), average R² score
- Threshold Suggestions panel: eligible count (no existing threshold), high-confidence count (≥75%), skipped count
- In-progress indicator replaces static countdown during active batch pass
- Batch cursor intervals read from configured settings (not hardcoded)

**Settings**
- Batch size (shared, default 500)
- Baseline run interval (default 300 s), baseline history (default 30 days)
- Forecast run interval (default 3600 s), forecast history (default 90 days), saturation % (default 90%)
- Sigma threshold, minimum samples per bucket
- Threshold suggestions: confidence min samples per bucket (default 3)
- LLM: enable/disable, provider, API key, model, batch window, alert cooldown, notify email, HTTP proxy
- Anomaly breach retention (default 30 days), LLM summary retention (default 90 days)

**General**
- Boost-aware: RRD work runs from `boost_poller_bottom` when Boost is active, skipped in `poller_bottom`
- In-progress pass logic: baseline and forecast cursors advance every eligible cycle without resetting the interval timer mid-pass
- Daily purge: removes stale baselines, forecasts, and cache entries for deleted datasources and excluded datasource names
- All DB migrations applied automatically on poller startup — safe for existing installs
- GPL-2.0-or-later license
