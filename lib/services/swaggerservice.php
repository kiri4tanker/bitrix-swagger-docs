<?php

namespace K4T\Docs\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use OpenApi\Annotations\OpenApi;
use OpenApi\Generator;
use OpenApi\Attributes as OA;
use RuntimeException;
use Throwable;

class SwaggerService
{
	private const MODULE_ID = 'k4t.docs';

	/**
	 * @param HttpRequest $request
	 *
	 * @return OpenApi
	 */
	public static function generate(HttpRequest $request): OpenApi
	{
		$settings = self::getSettings();

		$finder = self::prepareFinder($settings);
		if ($finder === []) {
			throw new RuntimeException(
				'No directories found for OpenAPI scan. Check swagger_settings.include_dirs/include_modules in .settings.php.'
			);
		}

		$cacheId = self::buildCacheId($request, $settings, $finder);
		$cached  = self::loadFromCache($cacheId, $settings);
		if ($cached instanceof OpenApi) {
			return $cached;
		}

		$swagger          = Generator::scan($finder);
		$swagger->servers = self::getServers($request, $settings['servers']);
		self::saveToCache($cacheId, $swagger, $settings);

		return $swagger;
	}

	/**
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
	public static function getSettings(): array
	{
		$rawSettings = Configuration::getInstance(self::MODULE_ID)->get('swagger_settings') ?? [];

		return SwaggerSettings::normalize($rawSettings);
	}

	/**
	 * @param array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * } $settings
	 *
	 * @return list<string>
	 */
	private static function prepareFinder(array $settings): array
	{
		$foundPaths     = [];
		$includeDirs    = $settings['include_dirs'];
		$excludeDirs    = array_flip($settings['exclude_dirs']);
		$allowedModules = array_flip($settings['include_modules']);
		$hasWhitelist   = $allowedModules !== [];

		foreach (array_keys(ModuleManager::getInstalledModules()) as $moduleId) {
			if ($hasWhitelist && !isset($allowedModules[$moduleId])) {
				continue;
			}

			$moduleRoot = sprintf('%s/%s', Loader::getLocal('modules'), $moduleId);
			if (!is_dir($moduleRoot)) {
				continue;
			}

			if ($includeDirs === []) {
				foreach (scandir($moduleRoot) ?: [] as $entry) {
					if ($entry === '.' || $entry === '..') {
						continue;
					}

					if (isset($excludeDirs[$entry])) {
						continue;
					}

					$entryPath = $moduleRoot . '/' . $entry;
					if (is_dir($entryPath)) {
						$foundPaths[] = $entryPath;
					}
				}

				continue;
			}

			foreach ($includeDirs as $includeDir) {
				if (isset($excludeDirs[$includeDir])) {
					continue;
				}

				$dir = sprintf('%s/%s', $moduleRoot, $includeDir);

				if (is_dir($dir)) {
					$foundPaths[] = $dir;
				}
			}
		}

		return $foundPaths;
	}

	/**
	 * @param HttpRequest                                      $request
	 * @param list<array{url:string, description:string|null}> $config
	 *
	 * @return list<OA\Server>
	 */
	private static function getServers(HttpRequest $request, array $config): array
	{
		$servers = [];

		if (empty($config)) {
			$protocol  = $request->isHttps() ? 'https' : 'http';
			$servers[] = new OA\Server(
				url: $protocol . '://' . $request->getHttpHost() . '/api/v1',
				description: 'Default'
			);

			return $servers;
		}

		foreach ($config as $server) {
			$servers[] = new OA\Server(
				url: $server['url'],
				description: $server['description'] ?? null
			);
		}

		return $servers;
	}

	/**
	 * @param HttpRequest  $request
	 * @param array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * }                   $settings
	 * @param list<string> $finder
	 *
	 * @return string
	 */
	private static function buildCacheId(HttpRequest $request, array $settings, array $finder): string
	{
		$serverData = $settings['servers'];
		if ($serverData === []) {
			$protocol   = $request->isHttps() ? 'https' : 'http';
			$serverData = [
				[
					'url'         => $protocol . '://' . $request->getHttpHost() . '/api/v1',
					'description' => 'Default',
				]
			];
		}

		$payload = [
			'finder'          => array_values($finder),
			'servers'         => $serverData,
			'include_dirs'    => $settings['include_dirs'],
			'exclude_dirs'    => $settings['exclude_dirs'],
			'include_modules' => $settings['include_modules'],
		];

		$encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($encodedPayload)) {
			$encodedPayload = serialize($payload);
		}

		return 'openapi_' . sha1($encodedPayload);
	}

	/**
	 * @param string $cacheId
	 * @param array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * }             $settings
	 *
	 * @return OpenApi|null
	 */
	private static function loadFromCache(string $cacheId, array $settings): ?OpenApi
	{
		if ($settings['cache_enabled'] === false) {
			return null;
		}

		$cache = self::getManagedCache();
		if ($cache === null) {
			return null;
		}

		$cacheTtl = $settings['cache_ttl'];
		if ($cache->read($cacheTtl, $cacheId, self::MODULE_ID) !== true) {
			return null;
		}

		$cached = $cache->get($cacheId);

		return $cached instanceof OpenApi ? $cached : null;
	}

	/**
	 * @param string  $cacheId
	 * @param OpenApi $swagger
	 * @param array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * }              $settings
	 *
	 * @return void
	 */
	private static function saveToCache(string $cacheId, OpenApi $swagger, array $settings): void
	{
		if ($settings['cache_enabled'] === false) {
			return;
		}

		$cache = self::getManagedCache();
		if ($cache === null) {
			return;
		}

		$cache->set($cacheId, $swagger);
	}

	/**
	 * @return object|null
	 */
	private static function getManagedCache(): ?object
	{
		try {
			return Application::getInstance()->getManagedCache();
		} catch (Throwable) {
			return null;
		}
	}
}
