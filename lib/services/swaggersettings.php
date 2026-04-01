<?php

namespace K4T\Docs\Services;

final class SwaggerSettings
{
	private const DEFAULT_SETTINGS = [
		'enabled'         => true,
		'allowed_groups'  => [],
		'allowed_ips'     => [],
		'cache_enabled'   => true,
		'cache_ttl'       => 3600,
		'servers'         => [],
		'include_dirs'    => [],
		'exclude_dirs'    => [],
		'include_modules' => [],
	];

	/**
	 * @param mixed $settings
	 *
	 * @return array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * }
	 */
	public static function normalize(mixed $settings): array
	{
		if (!is_array($settings)) {
			$settings = [];
		}

		$settings = array_replace(self::DEFAULT_SETTINGS, $settings);

		if (!is_bool($settings['enabled'])) {
			throw new \InvalidArgumentException('swagger_settings.enabled must be a boolean');
		}

		if (!is_bool($settings['cache_enabled'])) {
			throw new \InvalidArgumentException('swagger_settings.cache_enabled must be a boolean');
		}

		$cacheTtl = filter_var($settings['cache_ttl'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
		if ($cacheTtl === false) {
			throw new \InvalidArgumentException('swagger_settings.cache_ttl must be an integer >= 0');
		}
		$settings['cache_ttl'] = $cacheTtl;

		$settings['include_dirs']    = self::normalizeStringList($settings['include_dirs'], 'include_dirs');
		$settings['exclude_dirs']    = self::normalizeStringList($settings['exclude_dirs'], 'exclude_dirs');
		$settings['include_modules'] = self::normalizeStringList($settings['include_modules'], 'include_modules');
		$settings['allowed_ips']     = self::normalizeIpList($settings['allowed_ips'], 'allowed_ips');
		$settings['allowed_groups']  = self::normalizeGroupList($settings['allowed_groups'], 'allowed_groups');
		$settings['servers']         = self::normalizeServers($settings['servers']);

		return $settings;
	}

	/**
	 * @param mixed  $value
	 * @param string $key
	 *
	 * @return list<string>
	 */
	private static function normalizeStringList(mixed $value, string $key): array
	{
		if (!is_array($value)) {
			throw new \InvalidArgumentException(sprintf('swagger_settings.%s must be an array', $key));
		}

		$result = [];
		foreach ($value as $item) {
			if (!is_string($item) || trim($item) === '') {
				throw new \InvalidArgumentException(sprintf('swagger_settings.%s must contain non-empty strings', $key));
			}

			$result[] = trim($item);
		}

		return array_values(array_unique($result));
	}

	/**
	 * @param mixed $servers
	 *
	 * @return list<array{url:string, description:string|null}>
	 */
	private static function normalizeServers(mixed $servers): array
	{
		if (!is_array($servers)) {
			throw new \InvalidArgumentException('swagger_settings.servers must be an array');
		}

		$result = [];
		foreach ($servers as $index => $server) {
			if (!is_array($server) || !isset($server['url']) || !is_string($server['url']) || trim($server['url']) === '') {
				throw new \InvalidArgumentException(
					sprintf('swagger_settings.servers[%d].url must be a non-empty string', $index)
				);
			}

			if (isset($server['description']) && !is_string($server['description'])) {
				throw new \InvalidArgumentException(
					sprintf('swagger_settings.servers[%d].description must be a string', $index)
				);
			}

			$result[] = [
				'url'         => trim($server['url']),
				'description' => isset($server['description']) ? trim($server['description']) : null,
			];
		}

		return $result;
	}

	/**
	 * @param mixed  $groups
	 * @param string $key
	 *
	 * @return list<int>
	 */
	private static function normalizeGroupList(mixed $groups, string $key): array
	{
		if (!is_array($groups)) {
			throw new \InvalidArgumentException(sprintf('swagger_settings.%s must be an array', $key));
		}

		$result = [];
		foreach ($groups as $groupId) {
			$parsed = filter_var($groupId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if ($parsed === false) {
				throw new \InvalidArgumentException(sprintf('swagger_settings.%s must contain positive integers', $key));
			}

			$result[] = $parsed;
		}

		return array_values(array_unique($result));
	}

	/**
	 * @param mixed  $ips
	 * @param string $key
	 *
	 * @return list<string>
	 */
	private static function normalizeIpList(mixed $ips, string $key): array
	{
		if (!is_array($ips)) {
			throw new \InvalidArgumentException(sprintf('swagger_settings.%s must be an array', $key));
		}

		$result = [];
		foreach ($ips as $ipRule) {
			if (!is_string($ipRule) || trim($ipRule) === '') {
				throw new \InvalidArgumentException(sprintf('swagger_settings.%s must contain non-empty strings', $key));
			}

			$ipRule = trim($ipRule);
			if (!AccessPolicy::isValidIpRule($ipRule)) {
				throw new \InvalidArgumentException(sprintf('swagger_settings.%s contains invalid IP/CIDR rule: %s', $key, $ipRule));
			}

			$result[] = $ipRule;
		}

		return array_values(array_unique($result));
	}
}
