<?php

namespace K4T\Docs\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use OpenApi\Annotations\OpenApi;
use OpenApi\Attributes as OA;
use OpenApi\Generator;
use OpenApi\Serializer;
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
		$payload = self::generatePayload($request);

		return $payload['openapi'];
	}

	/**
	 * @param HttpRequest $request
	 *
	 * @return array{json:string, cache_status:string, generation_time_ms:float}
	 */
	public static function generateJson(HttpRequest $request): array
	{
		$payload = self::generatePayload($request);

		return [
			'json'               => $payload['json'],
			'cache_status'       => $payload['cache_status'],
			'generation_time_ms' => $payload['generation_time_ms'],
		];
	}

	/**
	 * @param HttpRequest $request
	 *
	 * @return array{openapi:OpenApi, json:string, cache_status:string, generation_time_ms:float}
	 */
	private static function generatePayload(HttpRequest $request): array
	{
		$startedAt = microtime(true);
		$settings  = self::getSettings();

		$finder = self::prepareFinder($settings);
		if ($finder === []) {
			throw new RuntimeException(
				'No directories found for OpenAPI scan. Check swagger_settings.include_dirs/include_modules in .settings.php.'
			);
		}

		$cache = self::resolveManagedCache($settings);
		if ($cache !== null) {
			$cacheId     = self::buildCacheId($request, $settings, $finder);
			$cachedJson  = self::loadFromCache($cache, $cacheId, $settings);
			$cachedModel = self::deserializeOpenApi($cachedJson);

			if ($cachedModel !== null && is_string($cachedJson)) {
				return [
					'openapi'            => $cachedModel,
					'json'               => $cachedJson,
					'cache_status'       => 'HIT',
					'generation_time_ms' => self::toMilliseconds($startedAt),
				];
			}
		}

		$openApi          = Generator::scan($finder);
		$openApi->servers = self::getServers($request, $settings['servers']);
		$json             = $openApi->toJson();

		if ($cache !== null) {
			$cacheId = self::buildCacheId($request, $settings, $finder);
			self::saveToCache($cache, $cacheId, $json);
		}

		return [
			'openapi'            => $openApi,
			'json'               => $json,
			'cache_status'       => $cache === null ? 'OFF' : 'MISS',
			'generation_time_ms' => self::toMilliseconds($startedAt),
		];
	}

	/**
	 * @return array{
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
	 *     cache_revision:string,
	 *     debug_headers_enabled:bool,
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

		if ($config === []) {
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
				description: $server['description']
			);
		}

		return $servers;
	}

	/**
	 * @param HttpRequest $request
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
			'cache_revision'  => $settings['cache_revision'],
			'module_version'  => self::getModuleVersion(),
		];

		$encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($encodedPayload)) {
			$encodedPayload = serialize($payload);
		}

		return 'openapi_' . sha1($encodedPayload);
	}

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
	 *
	 * @return object|null
	 */
	private static function resolveManagedCache(array $settings): ?object
	{
		if ($settings['cache_enabled'] === false) {
			return null;
		}

		return self::getManagedCache();
	}

	/**
	 * @param object $cache
	 * @param string $cacheId
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
	 *
	 * @return string|null
	 */
	private static function loadFromCache(object $cache, string $cacheId, array $settings): ?string
	{
		$cacheTtl = $settings['cache_ttl'];
		if ($cache->read($cacheTtl, $cacheId, self::MODULE_ID) !== true) {
			return null;
		}

		$cached = $cache->get($cacheId);

		return is_string($cached) ? $cached : null;
	}

	/**
	 * @param object $cache
	 * @param string $cacheId
	 * @param string $json
	 *
	 * @return void
	 */
	private static function saveToCache(object $cache, string $cacheId, string $json): void
	{
		$cache->set($cacheId, $json);
	}

	/**
	 * @param string|null $json
	 *
	 * @return OpenApi|null
	 */
	private static function deserializeOpenApi(?string $json): ?OpenApi
	{
		if ($json === null || $json === '') {
			return null;
		}

		try {
			$annotation = (new Serializer())->deserialize($json, OpenApi::class);
		} catch (Throwable) {
			return null;
		}

		return $annotation instanceof OpenApi ? $annotation : null;
	}

	/**
	 * @return string
	 */
	private static function getModuleVersion(): string
	{
		static $version = null;

		if (is_string($version) && $version !== '') {
			return $version;
		}

		$version = '0';
		$file    = dirname(__DIR__, 2) . '/install/version.php';
		if (!is_file($file)) {
			return $version;
		}

		$content = file_get_contents($file);
		if (!is_string($content)) {
			return $version;
		}

		if (preg_match("/['\\\"]VERSION['\\\"]\\s*=>\\s*['\\\"]([^'\\\"]+)['\\\"]/", $content, $matches) === 1) {
			$normalized = trim((string)$matches[1]);
			if ($normalized !== '') {
				$version = $normalized;
			}
		}

		return $version;
	}

	/**
	 * @param float $startedAt
	 *
	 * @return float
	 */
	private static function toMilliseconds(float $startedAt): float
	{
		return round((microtime(true) - $startedAt) * 1000, 2);
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
