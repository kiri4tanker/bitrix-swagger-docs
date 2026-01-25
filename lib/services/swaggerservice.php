<?php

namespace K4T\Docs\Services;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Configuration;
use OpenApi\Generator;
use OpenApi\Attributes as OA;

class SwaggerService
{
	private const MODULE_ID = 'k4t.docs';

	public static function generate(HttpRequest $request): \OpenApi\Annotations\OpenApi
	{
		$settings = Configuration::getInstance(self::MODULE_ID)->get('swagger_settings') ?? [];

		$finder = self::prepareFinder($settings);

		$swagger          = Generator::scan($finder);
		$swagger->servers = self::getServers($request, $settings['servers'] ?? []);

		return $swagger;
	}

	private static function prepareFinder(array $settings): array
	{
		$foundPaths = [];

		foreach (array_keys(ModuleManager::getInstalledModules()) as $moduleId) {
			foreach ($settings["include_dirs"] ?? [] as $includeDir) {
				$dir = sprintf("%s/%s/%s", Loader::getLocal('modules'), $moduleId, $includeDir);

				if (is_dir($dir)) {
					$foundPaths[] = $dir;
				}
			}
		}

		return $foundPaths;
	}

	private static function getServers(HttpRequest $request, array $config): array
	{
		if (empty($config)) {
			$protocol  = $request->isHttps() ? 'https' : 'http';
			$servers[] = new OA\Server(
				url: "{$protocol}://{$request->getHttpHost()}/api/v1",
				description: "Default"
			);
		}

		$servers = [];
		foreach ($config as $server) {
			$servers[] = new OA\Server(
				url: $server['url'],
				description: $server['description'] ?? null
			);
		}

		return $servers;
	}
}