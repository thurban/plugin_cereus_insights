<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2024-2026 Urban-Software.de / Thomas Urban               |
 +-------------------------------------------------------------------------+
 | Cereus Insights - License Integration                                   |
 |                                                                         |
 | Graceful wrapper around cereus_license. Falls back to community tier   |
 | if the license plugin is not installed or no valid license is active.   |
 +-------------------------------------------------------------------------+
*/

/**
 * Get the current Cereus Insights license tier.
 *
 * @return string 'enterprise', 'professional', or 'community'
 */
function cereus_insights_license_tier(): string {
	if (function_exists('cereus_license_get_tier')) {
		$tier = cereus_license_get_tier('cereus_insights');
		if (in_array($tier, array('enterprise', 'professional'), true)) {
			return $tier;
		}
	}
	return 'community';
}

/**
 * Check if the current tier meets the minimum required tier.
 *
 * @param  string $minimum 'community', 'professional', or 'enterprise'
 * @return bool
 */
function cereus_insights_license_at_least(string $minimum): bool {
	$levels = array('community' => 0, 'professional' => 1, 'enterprise' => 2);
	$tier   = cereus_insights_license_tier();
	return ($levels[$tier] ?? 0) >= ($levels[$minimum] ?? 0);
}

/**
 * Is anomaly detection available? (Professional+)
 *
 * @return bool
 */
function cereus_insights_has_anomaly_detection(): bool {
	return cereus_insights_license_at_least('professional');
}

/**
 * Is LLM alert summarization available? (Enterprise)
 *
 * @return bool
 */
function cereus_insights_has_llm(): bool {
	return cereus_insights_license_at_least('enterprise');
}

/**
 * Get the maximum forecast horizon in days for the current tier.
 *
 * Community:    30 days
 * Professional: 90 days
 * Enterprise:   unlimited (365 displayed max)
 *
 * @return int
 */
function cereus_insights_forecast_horizon_days(): int {
	$tier = cereus_insights_license_tier();
	if ($tier === 'enterprise') {
		return 365;
	}
	if ($tier === 'professional') {
		return 90;
	}
	return 30;
}
