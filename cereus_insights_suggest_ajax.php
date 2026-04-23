<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - Threshold Suggestion AJAX Handler                     |
 +-------------------------------------------------------------------------+
*/

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cereus_insights/includes/constants.php');
include_once('./plugins/cereus_insights/lib/license_check.php');
include_once('./plugins/cereus_insights/lib/llm.php');

header('Content-Type: application/json');

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

switch ($action) {
	case 'create':
		print json_encode(cereus_insights_suggest_create());
		break;
	case 'skip':
		print json_encode(cereus_insights_suggest_skip());
		break;
	case 'explain':
		print json_encode(cereus_insights_suggest_explain());
		break;
	case 'exclude_ds':
		print json_encode(cereus_insights_suggest_exclude_ds());
		break;
	case 'include_ds':
		print json_encode(cereus_insights_suggest_include_ds());
		break;
	case 'apply_sigma':
		print json_encode(cereus_insights_suggest_apply_sigma());
		break;
	case 'reset_sigma':
		print json_encode(cereus_insights_suggest_reset_sigma());
		break;
	default:
		print json_encode(array('ok' => false, 'error' => 'Invalid action'));
}

exit;

/* =========================================================================
 * Action: create threshold
 * ====================================================================== */

function cereus_insights_suggest_create(): array {
	if (!cereus_insights_has_anomaly_detection()) {
		return array('ok' => false, 'error' => 'Professional license required');
	}

	$local_data_id = isset($_POST['local_data_id']) ? (int)  $_POST['local_data_id'] : 0;
	$datasource    = isset($_POST['datasource'])    ? trim($_POST['datasource'])     : '';
	$thold_hi      = isset($_POST['thold_hi'])      ? trim($_POST['thold_hi'])       : '';
	$thold_warn    = isset($_POST['thold_warn'])     ? trim($_POST['thold_warn'])     : '';
	$inverted      = isset($_POST['inverted'])       ? (bool)(int)$_POST['inverted'] : false;

	if ($local_data_id <= 0 || $datasource === '') {
		return array('ok' => false, 'error' => 'Missing parameters');
	}

	/* Check it doesn't already exist */
	$exists = db_fetch_cell_prepared(
		"SELECT id FROM thold_data WHERE local_data_id = ? AND data_source_name = ? LIMIT 1",
		array($local_data_id, $datasource)
	);
	if ($exists) {
		return array('ok' => false, 'error' => 'Threshold already exists');
	}

	/* Look up required metadata from Cacti tables */
	$meta = db_fetch_row_prepared(
		"SELECT dl.host_id,
		        dl.data_template_id,
		        COALESCE(dt.hash, '') AS data_template_hash,
		        COALESCE(dtd.name_cache, ?) AS name_cache,
		        COALESCE(dtr.id, 0) AS data_template_rrd_id
		 FROM data_local dl
		 LEFT JOIN data_template dt ON dt.id = dl.data_template_id
		 LEFT JOIN data_template_data dtd ON dtd.local_data_id = dl.id
		 LEFT JOIN data_template_rrd dtr ON dtr.local_data_id = dl.id AND dtr.data_source_name = ?
		 WHERE dl.id = ?
		 LIMIT 1",
		array($datasource, $datasource, $local_data_id)
	);

	if (!cacti_sizeof($meta)) {
		return array('ok' => false, 'error' => 'Data source not found');
	}

	/* Resolve associated graph (optional — 0 is valid) */
	$graph = db_fetch_row_prepared(
		"SELECT gl.id AS local_graph_id, gl.graph_template_id
		 FROM data_template_rrd dtr
		 JOIN graph_templates_item gti ON gti.task_item_id = dtr.id
		 JOIN graph_local gl ON gl.id = gti.local_graph_id
		 WHERE dtr.local_data_id = ? AND dtr.data_source_name = ?
		 LIMIT 1",
		array($local_data_id, $datasource)
	);

	$local_graph_id    = cacti_sizeof($graph) ? (int) $graph['local_graph_id']    : 0;
	$graph_template_id = cacti_sizeof($graph) ? (int) $graph['graph_template_id'] : 0;

	if ($inverted) {
		/* Free/idle metrics: alert when value drops BELOW the threshold */
		db_execute_prepared(
			"INSERT INTO thold_data
				(local_data_id, data_source_name, host_id, local_graph_id, graph_template_id,
				 data_template_id, data_template_hash, name_cache, data_template_rrd_id,
				 thold_low, thold_warning_low, thold_type, thold_fail_trigger,
				 thold_warning_fail_trigger, thold_enabled)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, 1, 'on')",
			array(
				$local_data_id,
				$datasource,
				(int)    $meta['host_id'],
				$local_graph_id,
				$graph_template_id,
				(int)    $meta['data_template_id'],
				(string) $meta['data_template_hash'],
				(string) $meta['name_cache'],
				(int)    $meta['data_template_rrd_id'],
				$thold_hi,    /* thold_low  = alert level  */
				$thold_warn,  /* thold_warning_low = warning level */
			)
		);
	} else {
		/* Normal metrics: alert when value exceeds the threshold */
		db_execute_prepared(
			"INSERT INTO thold_data
				(local_data_id, data_source_name, host_id, local_graph_id, graph_template_id,
				 data_template_id, data_template_hash, name_cache, data_template_rrd_id,
				 thold_hi, thold_warning_hi, thold_type, thold_fail_trigger,
				 thold_warning_fail_trigger, thold_enabled)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, 1, 'on')",
			array(
				$local_data_id,
				$datasource,
				(int)    $meta['host_id'],
				$local_graph_id,
				$graph_template_id,
				(int)    $meta['data_template_id'],
				(string) $meta['data_template_hash'],
				(string) $meta['name_cache'],
				(int)    $meta['data_template_rrd_id'],
				$thold_hi,
				$thold_warn,
			)
		);
	}

	$new_id = db_fetch_insert_id();

	if (!$new_id) {
		return array('ok' => false, 'error' => 'Insert failed');
	}

	return array('ok' => true, 'thold_id' => (int) $new_id);
}

/* =========================================================================
 * Action: skip suggestion
 * ====================================================================== */

function cereus_insights_suggest_skip(): array {
	$local_data_id = isset($_POST['local_data_id']) ? (int) $_POST['local_data_id'] : 0;
	$datasource    = isset($_POST['datasource'])    ? trim($_POST['datasource'])     : '';

	if ($local_data_id <= 0 || $datasource === '') {
		return array('ok' => false, 'error' => 'Missing parameters');
	}

	db_execute_prepared(
		"INSERT IGNORE INTO plugin_cereus_insights_suggest_skip
			(local_data_id, datasource, skipped_at)
		 VALUES (?, ?, NOW())",
		array($local_data_id, $datasource)
	);

	return array('ok' => true);
}

/* =========================================================================
 * Action: exclude datasource name globally
 * ====================================================================== */

function cereus_insights_suggest_exclude_ds(): array {
	if (!cereus_insights_has_anomaly_detection()) {
		return array('ok' => false, 'error' => 'Professional license required');
	}
	$datasource = isset($_POST['datasource']) ? trim($_POST['datasource']) : '';
	if ($datasource === '') {
		return array('ok' => false, 'error' => 'Missing datasource');
	}
	db_execute_prepared(
		"INSERT IGNORE INTO plugin_cereus_insights_ds_exclusions (datasource, created_at) VALUES (?, NOW())",
		array($datasource)
	);
	return array('ok' => true);
}

/* =========================================================================
 * Action: remove datasource exclusion
 * ====================================================================== */

function cereus_insights_suggest_include_ds(): array {
	if (!cereus_insights_has_anomaly_detection()) {
		return array('ok' => false, 'error' => 'Professional license required');
	}
	$datasource = isset($_POST['datasource']) ? trim($_POST['datasource']) : '';
	if ($datasource === '') {
		return array('ok' => false, 'error' => 'Missing datasource');
	}
	db_execute_prepared(
		"DELETE FROM plugin_cereus_insights_ds_exclusions WHERE datasource = ?",
		array($datasource)
	);
	/* Also clear per-LDI skip entries for this datasource so it reappears in suggestions */
	db_execute_prepared(
		"DELETE FROM plugin_cereus_insights_suggest_skip WHERE datasource = ?",
		array($datasource)
	);
	return array('ok' => true);
}

/* =========================================================================
 * Action: apply per-datasource sigma override
 * ====================================================================== */

function cereus_insights_suggest_apply_sigma(): array {
	if (!cereus_insights_has_anomaly_detection()) {
		return array('ok' => false, 'error' => 'Professional license required');
	}
	$ldi   = isset($_POST['local_data_id']) ? (int)$_POST['local_data_id'] : 0;
	$ds    = isset($_POST['datasource'])    ? trim($_POST['datasource'])    : '';
	$sigma = isset($_POST['sigma'])         ? (float)$_POST['sigma']       : 0;
	if ($ldi <= 0 || $ds === '' || $sigma < 1 || $sigma > 10) {
		return array('ok' => false, 'error' => 'Invalid parameters');
	}
	db_execute_prepared(
		"INSERT INTO plugin_cereus_insights_sigma_overrides (local_data_id, datasource, sigma)
		 VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE sigma = VALUES(sigma)",
		array($ldi, $ds, $sigma)
	);
	return array('ok' => true);
}

/* =========================================================================
 * Action: reset per-datasource sigma override
 * ====================================================================== */

function cereus_insights_suggest_reset_sigma(): array {
	if (!cereus_insights_has_anomaly_detection()) {
		return array('ok' => false, 'error' => 'Professional license required');
	}
	$ldi = isset($_POST['local_data_id']) ? (int)$_POST['local_data_id'] : 0;
	$ds  = isset($_POST['datasource'])    ? trim($_POST['datasource'])    : '';
	if ($ldi <= 0 || $ds === '') {
		return array('ok' => false, 'error' => 'Invalid parameters');
	}
	db_execute_prepared(
		"DELETE FROM plugin_cereus_insights_sigma_overrides WHERE local_data_id = ? AND datasource = ?",
		array($ldi, $ds)
	);
	return array('ok' => true);
}

/* =========================================================================
 * Action: LLM explain suggestion (Enterprise only)
 * ====================================================================== */

function cereus_insights_suggest_explain(): array {
	if (!cereus_insights_has_llm()) {
		return array('ok' => false, 'error' => 'Enterprise license required');
	}

	$suggestion = array(
		'name_cache'       => isset($_POST['name_cache'])       ? trim($_POST['name_cache'])       : '',
		'host_description' => isset($_POST['host_description']) ? trim($_POST['host_description']) : '',
		'avg_mean'         => isset($_POST['avg_mean'])         ? (float) $_POST['avg_mean']       : 0.0,
		'suggested_hi'     => isset($_POST['suggested_hi'])     ? (float) $_POST['suggested_hi']   : 0.0,
		'suggested_warn'   => isset($_POST['suggested_warn'])   ? (float) $_POST['suggested_warn'] : 0.0,
		'conf_pct'         => isset($_POST['conf_pct'])         ? (int)   $_POST['conf_pct']       : 0,
		'buckets'          => isset($_POST['buckets'])          ? (int)   $_POST['buckets']        : 0,
	);

	if ($suggestion['name_cache'] === '') {
		$local_data_id = isset($_POST['local_data_id']) ? (int) $_POST['local_data_id'] : 0;
		$datasource    = isset($_POST['datasource'])    ? trim($_POST['datasource'])     : '';
		$suggestion['name_cache'] = $datasource;
	}

	return cereus_insights_llm_explain_suggestion($suggestion);
}
