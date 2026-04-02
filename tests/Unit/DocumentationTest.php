<?php

namespace K4T\Docs\Tests\Unit;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\HttpRequest;
use K4T\Docs\Http\Documentation;
use PHPUnit\Framework\TestCase;

class DocumentationTest extends TestCase
{
	protected function setUp(): void
	{
		Configuration::resetAll();
		Debug::reset();
		$_SERVER['REQUEST_URI'] = '/docs/';
		global $USER;
		$USER = new FakeUser([1]);
	}

	public function testBuildJsonSpecUrlNormalizesRoute(): void
	{
		$method = new \ReflectionMethod(Documentation::class, 'buildJsonSpecUrl');

		$cases = [
			'/api/docs/'     => '/api/docs.json',
			'/api/docs'      => '/api/docs.json',
			'/api/docs.json' => '/api/docs.json',
			'/'              => '/docs.json',
		];

		foreach ($cases as $uri => $expected) {
			$request = new HttpRequest(false, 'example.com', '127.0.0.1', $uri);
			$this->assertSame($expected, $method->invoke(null, $request));
		}
	}

	public function testJsonReturns404WhenDocsDisabled(): void
	{
		Configuration::setValue('k4t.docs', 'swagger_settings', [
			'enabled' => false,
		]);

		$response = Documentation::json(new HttpRequest());
		$this->assertSame(404, $response->statusCode);
		$this->assertSame('API documentation is disabled', $response->data['error']);
	}

	public function testJsonReturns403WhenGroupDenied(): void
	{
		global $USER;
		$USER = new FakeUser([2]);

		Configuration::setValue('k4t.docs', 'swagger_settings', [
			'enabled'        => true,
			'allowed_groups' => [1],
			'allowed_ips'    => [],
		]);

		$response = Documentation::json(new HttpRequest());
		$this->assertSame(403, $response->statusCode);
		$this->assertSame('Access denied', $response->data['error']);
	}

	public function testJsonReturns500AndLogsWhenSettingsInvalid(): void
	{
		Configuration::setValue('k4t.docs', 'swagger_settings', [
			'cache_ttl' => 'invalid',
		]);

		$response = Documentation::json(new HttpRequest());
		$this->assertSame(500, $response->statusCode);
		$this->assertSame('Invalid swagger_settings configuration', $response->data['error']);
		$this->assertNotEmpty(Debug::$logs);
	}

	public function testDebugHeadersAreAddedWhenEnabled(): void
	{
		Configuration::setValue('k4t.docs', 'swagger_settings', [
			'enabled'               => false,
			'debug_headers_enabled' => true,
		]);

		$response = Documentation::json(new HttpRequest());
		$this->assertSame('OFF', $response->headers['X-K4T-Docs-Cache'] ?? null);
		$this->assertSame('json-error', $response->headers['X-K4T-Docs-Source'] ?? null);
		$this->assertArrayHasKey('X-K4T-Docs-Gen-Time', $response->headers);
		$this->assertSame('OFF', $response->headers['X-K4T-Docs-Cache-Reset'] ?? null);
	}

	public function testJsonReturns403WhenCacheResetDenied(): void
	{
		Configuration::setValue('k4t.docs', 'swagger_settings', [
			'enabled'               => true,
			'cache_reset_enabled'   => true,
			'cache_reset_token'     => 'secret',
			'debug_headers_enabled' => true,
		]);

		$response = Documentation::json(new HttpRequest(false, 'example.com', '127.0.0.1', '/docs.json?cache_reset=1'));
		$this->assertSame(403, $response->statusCode);
		$this->assertSame('Cache reset is not allowed', $response->data['error']);
		$this->assertSame('DENIED', $response->headers['X-K4T-Docs-Cache-Reset'] ?? null);
	}
}

class FakeUser
{
	/** @param list<int> $groups */
	public function __construct(private readonly array $groups)
	{
	}

	/** @return list<int> */
	public function GetUserGroupArray(): array
	{
		return $this->groups;
	}
}
