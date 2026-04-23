# Cereus Insights

Capacity forecasting, seasonality-aware anomaly detection, AI-suggested threshold values, and LLM-powered alert summarization for [Cacti](https://www.cacti.net) 1.2.x.

## Features

| Feature | Tier | Description |
|---|---|---|
| **Capacity Forecasting** | Community+ | Linear-regression trend analysis with days-to-saturation per datasource. Direction-aware: free/available metrics forecast toward `thold_low` when declining. |
| **Anomaly Detection** | Professional+ | Seasonality baselines (hour-of-day × day-of-week). Z-score detection with configurable sigma threshold. |
| **Threshold Suggestions** | Professional+ | Analyses baselines and recommends `thold_hi` / `thold_low` values for unconfigured datasources. One-click Create. |
| **LLM Alert Summarization** | Enterprise | Batches Thold breach events and generates a plain-English summary via Anthropic, OpenAI, or Google. |

### Threshold Suggestions

- Confidence scoring: percentage of time buckets with sufficient history
- Direction-aware: `*_free`, `*_avail`, `*_idle` datasources get `thold_low` suggestions
- Global datasource exclusion list — suppresses named datasources across suggestions, anomaly detection, and forecasting
- Enterprise: LLM "Explain" button with plain-English rationale

### LLM Alert Summarization

- Supported providers: **Anthropic** (Claude), **OpenAI** (GPT), **Google** (Gemini)
- Configurable batch window to correlate related alerts into a single summary
- HTTP proxy support for restricted network environments
- "Test API Key" button validates the key and provider before saving

## Requirements

| Requirement | Value |
|---|---|
| Cacti | 1.2.0 or later |
| PHP | 8.1 or later |
| PHP extensions | `curl`, `json` |
| Plugin: thold | Required for anomaly detection and threshold suggestions |
| Plugin: cereus_license | Required for Professional / Enterprise tier activation |

## Installation

See [INSTALL.md](INSTALL.md).

## Configuration

Go to **Console → Configuration → Settings → Cereus Insights**.

Full configuration reference is available in the plugin's built-in help page (**Cereus Tools → Insights Help**).

## License Tiers

Licenses are available at [urban-software.com](https://www.urban-software.com).

- **Community** — Capacity Forecasting included, no license required
- **Professional** — Anomaly Detection + Threshold Suggestions
- **Enterprise** — LLM Alert Summarization + Threshold Explain

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for full text.

## Author

Thomas Urban — [urban-software.com](https://www.urban-software.com) — info@urban-software.com
