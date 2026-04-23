<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - LLM API Key Test (AJAX endpoint)                      |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cereus_insights/includes/constants.php');
include_once('./plugins/cereus_insights/lib/llm.php');

header('Content-Type: application/json');

$api_key  = isset($_POST['api_key'])  ? trim($_POST['api_key'])  : '';
$model    = isset($_POST['model'])    ? trim($_POST['model'])    : '';
$provider = isset($_POST['provider']) ? trim($_POST['provider']) : '';

/* Fall back to saved settings for anything not supplied */
if ($api_key === '') {
	$api_key = (string) read_config_option('cereus_insights_llm_api_key');
}
if ($provider === '') {
	$provider = (string) read_config_option('cereus_insights_llm_provider');
}
if ($provider === '') {
	$provider = CEREUS_INSIGHTS_DEFAULT_LLM_PROVIDER;
}
if ($model === '') {
	$model = (string) read_config_option('cereus_insights_llm_model');
}
if ($model === '') {
	$model = cereus_insights_default_model($provider);
}

if ($api_key === '') {
	print json_encode(array('ok' => false, 'error' => 'No API key provided.'));
	exit;
}

/* Minimal test call — 1 token, as cheap as possible */
$raw = cereus_insights_llm_dispatch($provider, $api_key, $model, 'Reply with the single word: ok', 'ok', 1);

if (!$raw['ok']) {
	print json_encode(array('ok' => false, 'error' => $raw['error']));
	exit;
}

print json_encode(array('ok' => true, 'model' => $raw['model']));
