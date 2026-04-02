<?php

namespace K4T\Docs\Services;

final class AccessPolicy
{
	/**
	 * @param array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     cache_revision:string,
	 *     debug_headers_enabled:bool,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * } $settings
	 * @param list<int> $userGroupIds
	 * @param string $clientIp
	 *
	 * @return bool
	 */
	public static function isAllowed(array $settings, array $userGroupIds, string $clientIp): bool
	{
		if ($settings['enabled'] === false) {
			return false;
		}

		$hasGroupRules = $settings['allowed_groups'] !== [];
		$hasIpRules    = $settings['allowed_ips'] !== [];
		if (!$hasGroupRules && !$hasIpRules) {
			return true;
		}

		if ($hasGroupRules && self::isGroupAllowed($settings['allowed_groups'], $userGroupIds)) {
			return true;
		}

		return $hasIpRules && self::isIpAllowed($settings['allowed_ips'], $clientIp);
	}

	/**
	 * @param string $rule
	 *
	 * @return bool
	 */
	public static function isValidIpRule(string $rule): bool
	{
		if (str_contains($rule, '/') === false) {
			return filter_var($rule, FILTER_VALIDATE_IP) !== false;
		}

		[
			$network,
			$prefix
		] = explode('/', $rule, 2);
		if (!ctype_digit($prefix)) {
			return false;
		}

		$networkBin = inet_pton($network);
		if ($networkBin === false) {
			return false;
		}

		$maxPrefix    = strlen($networkBin) * 8;
		$prefixLength = (int)$prefix;

		return $prefixLength >= 0 && $prefixLength <= $maxPrefix;
	}

	/**
	 * @param list<int> $allowedGroups
	 * @param list<int> $userGroups
	 *
	 * @return bool
	 */
	private static function isGroupAllowed(array $allowedGroups, array $userGroups): bool
	{
		$userGroups = array_map('intval', $userGroups);

		return array_intersect($allowedGroups, $userGroups) !== [];
	}

	/**
	 * @param list<string> $allowedIpRules
	 * @param string $clientIp
	 *
	 * @return bool
	 */
	private static function isIpAllowed(array $allowedIpRules, string $clientIp): bool
	{
		if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
			return false;
		}

		foreach ($allowedIpRules as $rule) {
			if (str_contains($rule, '/') === false) {
				if ($clientIp === $rule) {
					return true;
				}

				continue;
			}

			if (self::isIpInCidr($clientIp, $rule)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $ip
	 * @param string $cidr
	 *
	 * @return bool
	 */
	private static function isIpInCidr(string $ip, string $cidr): bool
	{
		[
			$network,
			$prefix
		] = explode('/', $cidr, 2);
		$ipBin      = inet_pton($ip);
		$networkBin = inet_pton($network);
		if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
			return false;
		}

		$prefixLength = (int)$prefix;
		$bytes        = intdiv($prefixLength, 8);
		$bits         = $prefixLength % 8;

		if ($bytes > 0 && substr_compare($ipBin, $networkBin, 0, $bytes) !== 0) {
			return false;
		}

		if ($bits === 0) {
			return true;
		}

		$mask        = (0xFF << (8 - $bits)) & 0xFF;
		$ipByte      = ord($ipBin[$bytes]);
		$networkByte = ord($networkBin[$bytes]);

		return ($ipByte & $mask) === ($networkByte & $mask);
	}
}
