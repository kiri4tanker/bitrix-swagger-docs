<?php

namespace K4T\Docs\Tests\Unit;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Configuration;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\ManagedCacheMock;
use Bitrix\Main\ModuleManager;
use K4T\Docs\Services\SwaggerService;
use PHPUnit\Framework\TestCase;

class SwaggerServiceCacheTest extends TestCase
{
	private string $tmpDir;

	protected function setUp(): void
	{
		Configuration::resetAll();
		Application::getInstance()->setManagedCache(new ManagedCacheMock());
		$this->tmpDir = rtrim(sys_get_temp_dir(), '/') . '/k4t_docs_' . uniqid('', true);
		mkdir($this->tmpDir, 0777, true);
		Loader::setModulesPath($this->tmpDir);
	}

	protected function tearDown(): void
	{
		$this->removeDir($this->tmpDir);
	}

	public function testGenerateJsonUsesMissThenHit(): void
	{
		$moduleId = 'test.api';
		$this->createFixtureModule($moduleId);
		$this->setSwaggerSettings([
			'include_modules' => [$moduleId],
			'include_dirs'    => ['routes'],
			'cache_enabled'   => true,
			'cache_ttl'       => 3600,
			'cache_revision'  => '1',
		]);

		$request = new HttpRequest(false, 'example.com', '127.0.0.1', '/api/docs.json');

		$first = SwaggerService::generateJson($request);
		$this->assertSame('MISS', $first['cache_status']);
		$this->assertStringContainsString('"openapi"', $first['json']);

		$second = SwaggerService::generateJson($request);
		$this->assertSame('HIT', $second['cache_status']);
		$this->assertSame($first['json'], $second['json']);
	}

	public function testCacheRevisionForcesMiss(): void
	{
		$moduleId = 'test.api';
		$this->createFixtureModule($moduleId);
		$this->setSwaggerSettings([
			'include_modules' => [$moduleId],
			'include_dirs'    => ['routes'],
			'cache_enabled'   => true,
			'cache_ttl'       => 3600,
			'cache_revision'  => '1',
		]);

		$request = new HttpRequest(false, 'example.com', '127.0.0.1', '/api/docs.json');

		$this->assertSame('MISS', SwaggerService::generateJson($request)['cache_status']);
		$this->assertSame('HIT', SwaggerService::generateJson($request)['cache_status']);

		$this->setSwaggerSettings([
			'include_modules' => [$moduleId],
			'include_dirs'    => ['routes'],
			'cache_enabled'   => true,
			'cache_ttl'       => 3600,
			'cache_revision'  => '2',
		]);

		$this->assertSame('MISS', SwaggerService::generateJson($request)['cache_status']);
	}

	public function testCacheStoresJsonString(): void
	{
		$moduleId = 'test.api';
		$this->createFixtureModule($moduleId);
		$this->setSwaggerSettings([
			'include_modules' => [$moduleId],
			'include_dirs'    => ['routes'],
			'cache_enabled'   => true,
			'cache_ttl'       => 3600,
			'cache_revision'  => '1',
		]);

		$request = new HttpRequest(false, 'example.com', '127.0.0.1', '/api/docs.json');
		SwaggerService::generateJson($request);

		$settings = SwaggerService::getSettings();
		$finder   = $this->invokePrivateStatic(SwaggerService::class, 'prepareFinder', [$settings]);
		$cacheId  = $this->invokePrivateStatic(SwaggerService::class, 'buildCacheId', [$request, $settings, $finder]);

		$cache = Application::getInstance()->getManagedCache();
		$this->assertIsString($cache->get($cacheId));
	}

	public function testEmptyIncludeAndExcludeScansAllDirsInModule(): void
	{
		$moduleId = 'test.api';
		$moduleRoot = $this->createFixtureModule($moduleId);
		mkdir($moduleRoot . '/lib', 0777, true);
		mkdir($moduleRoot . '/tests', 0777, true);

		$this->setSwaggerSettings([
			'include_modules' => [$moduleId],
			'include_dirs'    => [],
			'exclude_dirs'    => [],
			'cache_enabled'   => false,
		]);

		$settings = SwaggerService::getSettings();
		$finder   = $this->invokePrivateStatic(SwaggerService::class, 'prepareFinder', [$settings]);

		$this->assertContains($moduleRoot . '/routes', $finder);
		$this->assertContains($moduleRoot . '/lib', $finder);
		$this->assertContains($moduleRoot . '/tests', $finder);
	}

	public function testResetCacheInvalidatesCache(): void
	{
		$moduleId = 'test.api';
		$this->createFixtureModule($moduleId);
		$this->setSwaggerSettings([
			'include_modules'      => [$moduleId],
			'include_dirs'         => ['routes'],
			'cache_enabled'        => true,
			'cache_ttl'            => 3600,
			'cache_reset_enabled'  => true,
			'cache_reset_token'    => 'secret',
		]);

		$request = new HttpRequest(false, 'example.com', '127.0.0.1', '/api/docs.json');

		$this->assertSame('MISS', SwaggerService::generateJson($request)['cache_status']);
		$this->assertSame('HIT', SwaggerService::generateJson($request)['cache_status']);

		$reset = SwaggerService::resetCache($request);
		$this->assertTrue($reset['cleared']);
		$this->assertSame('RESET', $reset['cache_status']);

		$this->assertSame('MISS', SwaggerService::generateJson($request)['cache_status']);
	}

	public function testCacheResetTokenValidation(): void
	{
		$this->setSwaggerSettings([
			'cache_reset_enabled' => true,
			'cache_reset_token'   => 'secret',
		]);

		$this->assertFalse(SwaggerService::isCacheResetAllowed(null));
		$this->assertFalse(SwaggerService::isCacheResetAllowed('wrong'));
		$this->assertTrue(SwaggerService::isCacheResetAllowed('secret'));
	}

	private function setSwaggerSettings(array $settings): void
	{
		$base = [
			'enabled'               => true,
			'allowed_groups'        => [],
			'allowed_ips'           => [],
			'cache_enabled'         => true,
			'cache_ttl'             => 3600,
			'cache_revision'        => '1',
			'cache_reset_enabled'   => false,
			'cache_reset_token'     => '',
			'debug_headers_enabled' => false,
			'servers'               => [],
			'include_dirs'          => ['routes'],
			'exclude_dirs'          => [],
			'include_modules'       => [],
		];

		Configuration::setValue('k4t.docs', 'swagger_settings', array_replace($base, $settings));
	}

	private function createFixtureModule(string $moduleId): string
	{
		$moduleRoot = $this->tmpDir . '/' . $moduleId;
		$routesPath = $moduleRoot . '/routes';
		mkdir($routesPath, 0777, true);
		ModuleManager::setInstalledModules([$moduleId => []]);

		$className = 'K4TDocsFixtureRoot' . str_replace('.', '_', uniqid('', true));
		$fixture   = sprintf(
			<<<'PHP'
<?php

use OpenApi\Attributes as OA;

#[OA\OpenApi(
	info: new OA\Info(title: 'Test API', version: '1.0.0'),
	paths: [
		new OA\PathItem(
			path: '/ping',
			get: new OA\Get(
				responses: [
					new OA\Response(response: 200, description: 'ok')
				]
			)
		)
	]
)]
final class %s {}
PHP,
			$className
		);

		$filePath = $routesPath . '/docs.php';
		file_put_contents($filePath, $fixture);
		include_once $filePath;

		return $moduleRoot;
	}

	private function invokePrivateStatic(string $className, string $methodName, array $args): mixed
	{
		$reflection = new \ReflectionClass($className);
		$method     = $reflection->getMethod($methodName);

		return $method->invokeArgs(null, $args);
	}

	private function removeDir(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$items = scandir($path);
		if ($items === false) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$fullPath = $path . '/' . $item;
			if (is_dir($fullPath)) {
				$this->removeDir($fullPath);
			} else {
				unlink($fullPath);
			}
		}

		rmdir($path);
	}
}
