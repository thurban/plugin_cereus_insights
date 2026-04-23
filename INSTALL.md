# Installation

## Prerequisites

- Cacti 1.2.0 or later
- PHP 8.1 or later with `curl` and `json` extensions
- Plugin **thold** installed and active (required for anomaly detection and threshold suggestions)
- Plugin **cereus_license** installed (required for Professional / Enterprise tier activation)

## Install

1. Copy the `cereus_insights` directory into your Cacti plugins folder:
   ```
   /var/www/html/cacti/plugins/cereus_insights/
   ```

2. Set ownership to the web server user:
   ```bash
   chown -R apache:apache /var/www/html/cacti/plugins/cereus_insights/
   ```
   Replace `apache` with `www-data` on Debian/Ubuntu systems.

3. In Cacti, go to **Console → Configuration → Plugins**.

4. Find **cereus_insights** in the list and click **Install**, then **Enable**.

5. Go to **Console → Configuration → Settings → Cereus Insights** to configure the plugin.

## Post-Install

### Capacity Forecasting (all tiers)

Forecasting starts automatically on the next poller cycle. The first full pass across all datasources takes a few hours depending on instance size and batch size setting. No configuration required.

### Anomaly Detection (Professional+)

Requires a Professional or higher cereus_license key. Once licensed, baselines begin building on the next poller cycle. Anomaly detection becomes active per datasource once sufficient baseline history has been accumulated (see **Detection Ready %** in the status panel).

### Threshold Suggestions (Professional+)

Available after baselines are built. Go to **Console → Cereus Tools → Insights — Threshold Suggestions** to review and apply suggested threshold values.

### LLM Alert Summarization (Enterprise)

1. Enable the feature: **Settings → Cereus Insights → Enable LLM Alert Summarization**
2. Select your **LLM Provider** (Anthropic, OpenAI, or Google)
3. Enter your **LLM API Key** and click **Test API Key** to validate
4. If your server uses a proxy for outbound internet access, enter it in the **HTTP Proxy** field (e.g. `http://proxy.example.com:3128`)
5. Set a **Summary Notify Email** address (leave blank to use the Cacti admin email)

## Upgrade

1. Replace the plugin directory contents with the new version
2. Re-set ownership if needed (`chown -R apache:apache ...`)
3. Go to **Console → Configuration → Plugins** — if an upgrade notice appears, click **Upgrade**
4. New database columns and tables are created automatically on the next poller cycle via the migration block in `poller_cereus_insights.php`

## Uninstall

Go to **Console → Configuration → Plugins**, disable and then uninstall **cereus_insights**. All plugin tables (`plugin_cereus_insights_*`) are dropped on uninstall.
