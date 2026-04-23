<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - LLM Alert Summarization                               |
 |                                                                         |
 | Supports: Anthropic (Claude), OpenAI (GPT), Google (Gemini)            |
 +-------------------------------------------------------------------------+
*/

/**
 * Return the default model name for a given provider.
 */
function cereus_insights_default_model(string $provider): string {
	switch ($provider) {
		case 'openai':  return CEREUS_INSIGHTS_DEFAULT_LLM_MODEL_OAI;
		case 'google':  return CEREUS_INSIGHTS_DEFAULT_LLM_MODEL_GOOGLE;
		default:        return CEREUS_INSIGHTS_DEFAULT_LLM_MODEL;
	}
}

/**
 * Low-level HTTP call dispatched to the configured provider.
 *
 * @param  string $provider      'anthropic' | 'openai' | 'google'
 * @param  string $api_key
 * @param  string $model
 * @param  string $system        System/instruction prompt
 * @param  string $user_content  User message content
 * @param  int    $max_tokens
 * @return array ['ok' => bool, 'text' => string, 'tokens_used' => int, 'model' => string]
 *               On error: ['ok' => false, 'error' => string]
 */
function cereus_insights_llm_dispatch(string $provider, string $api_key, string $model, string $system, string $user_content, int $max_tokens): array {
	switch ($provider) {

		/* ---------------------------------------------------------------- */
		case 'openai':
			$body = json_encode(array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'messages'   => array(
					array('role' => 'system', 'content' => $system),
					array('role' => 'user',   'content' => $user_content),
				),
			));
			$ch = curl_init('https://api.openai.com/v1/chat/completions');
			curl_setopt_array($ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'Authorization: Bearer ' . $api_key,
				),
			));
			$response   = curl_exec($ch);
			$curl_error = curl_error($ch);
			$http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($response === false || !empty($curl_error)) {
				return array('ok' => false, 'error' => 'cURL error: ' . $curl_error);
			}
			$data = json_decode($response, true);
			if ($http_code !== 200) {
				return array('ok' => false, 'error' => 'API error: ' . ($data['error']['message'] ?? 'HTTP ' . $http_code));
			}
			return array(
				'ok'          => true,
				'text'        => $data['choices'][0]['message']['content'] ?? '',
				'tokens_used' => (int) ($data['usage']['total_tokens'] ?? 0),
				'model'       => $data['model'] ?? $model,
			);

		/* ---------------------------------------------------------------- */
		case 'google':
			$body = json_encode(array(
				'systemInstruction' => array(
					'parts' => array(array('text' => $system)),
				),
				'contents' => array(
					array(
						'role'  => 'user',
						'parts' => array(array('text' => $user_content)),
					),
				),
				'generationConfig' => array('maxOutputTokens' => $max_tokens),
			));
			$url = 'https://generativelanguage.googleapis.com/v1beta/models/'
			     . urlencode($model) . ':generateContent?key=' . urlencode($api_key);
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
			));
			$response   = curl_exec($ch);
			$curl_error = curl_error($ch);
			$http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($response === false || !empty($curl_error)) {
				return array('ok' => false, 'error' => 'cURL error: ' . $curl_error);
			}
			$data = json_decode($response, true);
			if ($http_code !== 200) {
				return array('ok' => false, 'error' => 'API error: ' . ($data['error']['message'] ?? 'HTTP ' . $http_code));
			}
			return array(
				'ok'          => true,
				'text'        => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
				'tokens_used' => (int) ($data['usageMetadata']['totalTokenCount'] ?? 0),
				'model'       => $model,
			);

		/* ---------------------------------------------------------------- */
		default: /* anthropic */
			$body = json_encode(array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'system'     => $system,
				'messages'   => array(
					array('role' => 'user', 'content' => $user_content),
				),
			));
			$ch = curl_init('https://api.anthropic.com/v1/messages');
			curl_setopt_array($ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'x-api-key: ' . $api_key,
					'anthropic-version: 2023-06-01',
				),
			));
			$response   = curl_exec($ch);
			$curl_error = curl_error($ch);
			$http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($response === false || !empty($curl_error)) {
				return array('ok' => false, 'error' => 'cURL error: ' . $curl_error);
			}
			$data = json_decode($response, true);
			if ($http_code !== 200) {
				return array('ok' => false, 'error' => 'API error: ' . ($data['error']['message'] ?? 'HTTP ' . $http_code));
			}
			return array(
				'ok'          => true,
				'text'        => $data['content'][0]['text'] ?? '',
				'tokens_used' => (int) ($data['usage']['input_tokens'] ?? 0) + (int) ($data['usage']['output_tokens'] ?? 0),
				'model'       => $data['model'] ?? $model,
			);
	}
}

/**
 * Send a list of alert texts to the configured LLM provider for summarization.
 *
 * @param  array $alert_texts  Plain-text lines describing each alert
 * @return array ['ok' => bool, 'summary' => string, 'tokens_used' => int, 'model' => string]
 *               On error: ['ok' => false, 'error' => string]
 */
function cereus_insights_llm_call(array $alert_texts): array {
	$api_key  = read_config_option('cereus_insights_llm_api_key');
	$provider = read_config_option('cereus_insights_llm_provider') ?: CEREUS_INSIGHTS_DEFAULT_LLM_PROVIDER;
	$model    = read_config_option('cereus_insights_llm_model')    ?: cereus_insights_default_model($provider);

	if (empty($api_key)) {
		return array('ok' => false, 'error' => 'No API key configured');
	}

	$system = 'You are a network operations assistant. '
	        . 'You will receive a JSON array of Cacti threshold breach alert objects. '
	        . 'Summarize the alerts in 2-4 sentences for an operations team. '
	        . 'Focus on the most critical issues, patterns, and any common themes. '
	        . 'Be concise and actionable. Respond only with the summary — no labels or preamble.';

	$raw = cereus_insights_llm_dispatch($provider, $api_key, $model, $system,
		json_encode($alert_texts, JSON_UNESCAPED_UNICODE), 512);

	if (!$raw['ok']) {
		return $raw;
	}

	return array(
		'ok'          => true,
		'summary'     => $raw['text'],
		'tokens_used' => $raw['tokens_used'],
		'model'       => $raw['model'],
	);
}

/**
 * Ask the LLM to explain a threshold suggestion in one sentence.
 *
 * @param  array $suggestion  Keys: name_cache, host_description, avg_mean,
 *                            suggested_hi, suggested_warn, conf_pct, buckets
 * @return array ['ok' => bool, 'explanation' => string] or ['ok' => false, 'error' => string]
 */
function cereus_insights_llm_explain_suggestion(array $suggestion): array {
	$api_key  = read_config_option('cereus_insights_llm_api_key');
	$provider = read_config_option('cereus_insights_llm_provider') ?: CEREUS_INSIGHTS_DEFAULT_LLM_PROVIDER;
	$model    = read_config_option('cereus_insights_llm_model')    ?: cereus_insights_default_model($provider);

	if (empty($api_key)) {
		return array('ok' => false, 'error' => 'No API key configured');
	}

	$system = 'You are a network monitoring expert. Given Cacti data source baseline statistics, '
	        . 'explain in exactly one concise sentence why the proposed threshold values are a good starting point. '
	        . 'Be technical and specific. Respond with the explanation only — no labels or preamble.';

	$user_content = sprintf(
		"Data source: %s\nHost: %s\nBaseline mean: %.4g\nProposed alert threshold (mean+3σ): %.4g\nProposed warning threshold (mean+2σ): %.4g\nTime buckets: %d (%d%% with sufficient samples)",
		$suggestion['name_cache'],
		$suggestion['host_description'],
		(float) $suggestion['avg_mean'],
		(float) $suggestion['suggested_hi'],
		(float) $suggestion['suggested_warn'],
		(int)   $suggestion['buckets'],
		(int)   $suggestion['conf_pct']
	);

	$raw = cereus_insights_llm_dispatch($provider, $api_key, $model, $system, $user_content, 120);

	if (!$raw['ok']) {
		return $raw;
	}

	return array(
		'ok'          => true,
		'explanation' => $raw['text'],
	);
}

/**
 * Flush the alert queue: call LLM, store summary, email, truncate queue.
 *
 * On LLM failure: logs the error and does not retry.
 *
 * @return void
 */
function cereus_insights_flush_llm_queue(): void {
	global $cereus_insights_status_labels;

	$queue = db_fetch_assoc("SELECT * FROM plugin_cereus_insights_alert_queue ORDER BY queued_at ASC");

	if (!cacti_sizeof($queue)) {
		return;
	}

	$alert_texts = array();
	$raw_alerts  = array();

	foreach ($queue as $row) {
		$status_label = $cereus_insights_status_labels[$row['status']] ?? 'Unknown';
		$line         = sprintf(
			'- [%s] %s: value=%s threshold=%s (%s)',
			$row['hostname'] ?: $row['host_description'],
			$row['name_cache'],
			$row['current_value'],
			$row['threshold_value'],
			$status_label
		);
		$alert_texts[] = $line;
		$raw_alerts[]  = array(
			'thold_id'        => $row['thold_id'],
			'host'            => $row['hostname'],
			'name'            => $row['name_cache'],
			'current_value'   => $row['current_value'],
			'threshold_value' => $row['threshold_value'],
			'status'          => $status_label,
			'queued_at'       => $row['queued_at'],
		);
	}

	$result = cereus_insights_llm_call($alert_texts);

	/* Always truncate the queue whether or not LLM succeeded */
	db_execute("TRUNCATE TABLE plugin_cereus_insights_alert_queue");

	foreach ($queue as $row) {
		db_execute_prepared(
			"INSERT INTO plugin_cereus_insights_alert_seen (thold_id, queue_status, last_notified)
			 VALUES (?, ?, NOW())
			 ON DUPLICATE KEY UPDATE queue_status = VALUES(queue_status), last_notified = VALUES(last_notified)",
			array((int) $row['thold_id'], (int) $row['status'])
		);
	}

	if (!$result['ok']) {
		cacti_log('CEREUS INSIGHTS: LLM flush failed — ' . ($result['error'] ?? 'unknown'), false, 'SYSTEM');
		return;
	}

	$alert_count = count($alert_texts);
	$raw_json    = json_encode($raw_alerts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

	db_execute_prepared(
		"INSERT INTO plugin_cereus_insights_summaries
			(alert_count, summary, raw_alerts, model, tokens_used, created_at)
		 VALUES (?, ?, ?, ?, ?, NOW())",
		array(
			$alert_count,
			$result['summary'],
			$raw_json,
			$result['model'],
			$result['tokens_used'],
		)
	);

	cereus_insights_email_summary($result['summary'], $alert_count, $alert_texts);
}

/**
 * Send the LLM summary via Cacti's mailer.
 */
function cereus_insights_email_summary(string $summary, int $alert_count, array $alert_texts): void {
	if (!function_exists('mailer')) {
		return;
	}

	$notify_email = read_config_option('cereus_insights_llm_notify_email');

	if (empty($notify_email)) {
		$notify_email = read_config_option('settings_from_email');
	}

	if (empty($notify_email)) {
		return;
	}

	$from_email = read_config_option('settings_from_email');
	$from_name  = read_config_option('settings_from_name') ?: 'Cereus Insights';
	$subject    = 'Cereus Insights: AI Alert Summary (' . $alert_count . ' alerts)';

	$alert_html = '<ul style="margin:8px 0;padding-left:20px;">';
	foreach ($alert_texts as $line) {
		$alert_html .= '<li style="margin:3px 0;font-family:monospace;font-size:13px;">'
		             . html_escape(ltrim($line, '- ')) . '</li>';
	}
	$alert_html .= '</ul>';

	$body_html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;font-size:14px;color:#333;margin:0;padding:20px;">'
	           . '<div style="max-width:700px;margin:0 auto;">'
	           . '<div style="background:#2e86c1;color:#fff;padding:16px 20px;border-radius:4px 4px 0 0;">'
	           . '<strong>Cereus Insights — AI Alert Summary</strong></div>'
	           . '<div style="border:1px solid #ddd;border-top:none;padding:20px;border-radius:0 0 4px 4px;">'
	           . '<p style="margin:0 0 12px;"><strong>Summary</strong><br><span style="line-height:1.6;">'
	           . nl2br(html_escape($summary)) . '</span></p>'
	           . '<p style="margin:12px 0 6px;"><strong>Alerts included (' . $alert_count . '):</strong></p>'
	           . $alert_html
	           . '</div></div></body></html>';

	$body_text = "Cereus Insights AI Alert Summary\n\n" . $summary . "\n\nAlerts:\n" . implode("\n", $alert_texts);

	mailer(
		array($from_email, $from_name),
		$notify_email,
		'', '', '',
		$subject,
		$body_html,
		$body_text,
		'', '', true
	);
}
