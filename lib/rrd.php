<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - RRD Data Fetcher                                      |
 +-------------------------------------------------------------------------+
*/

/**
 * Fetch AVERAGE data from an RRD file for the given number of past days.
 *
 * @param  int $local_data_id  Cacti local_data_id
 * @param  int $days           How many days of history to fetch
 * @return array Array of ['timestamp' => int, 'sources' => ['dsname' => float|null, ...]]
 *               Returns empty array on any error.
 */
function cereus_insights_rrd_fetch(int $local_data_id, int $days): array {
	$rrd_path = get_data_source_path($local_data_id, true);

	if (empty($rrd_path) || !file_exists($rrd_path)) {
		return array();
	}

	$rrdtool = read_config_option('path_rrdtool');
	if (empty($rrdtool) || !is_executable($rrdtool)) {
		return array();
	}

	$days    = max(1, (int) $days);
	$cmd     = escapeshellarg($rrdtool) . ' fetch '
	         . escapeshellarg($rrd_path)
	         . ' AVERAGE --start -' . $days . 'd --end now 2>&1';

	$output = array();
	exec($cmd, $output, $exit_code);

	if ($exit_code !== 0 || empty($output)) {
		return array();
	}

	/* First non-blank line is the DS header */
	$ds_names = array();
	$rows     = array();
	$header_found = false;

	foreach ($output as $line) {
		$line = trim($line);
		if ($line === '') {
			continue;
		}

		if (!$header_found) {
			/* Header line: "ds0 ds1 ds2 ..." */
			$ds_names    = preg_split('/\s+/', $line);
			$header_found = true;
			continue;
		}

		/* Data lines: "timestamp: val0 val1 val2" */
		if (!preg_match('/^(\d+):\s*(.+)$/', $line, $m)) {
			continue;
		}

		$ts     = (int) $m[1];
		$parts  = preg_split('/\s+/', trim($m[2]));

		$sources = array();
		$all_nan = true;

		foreach ($ds_names as $i => $ds) {
			$raw = $parts[$i] ?? 'nan';
			if (strtolower($raw) === 'nan' || $raw === '-') {
				$sources[$ds] = null;
			} else {
				$val          = (float) $raw;
				$sources[$ds] = $val;
				$all_nan      = false;
			}
		}

		/* Skip rows where every datasource is NaN */
		if ($all_nan) {
			continue;
		}

		$rows[] = array(
			'timestamp' => $ts,
			'sources'   => $sources,
		);
	}

	return $rows;
}
