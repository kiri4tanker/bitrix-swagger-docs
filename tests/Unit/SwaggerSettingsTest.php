<?php

namespace K4T\Docs\Tests\Unit;

use K4T\Docs\Services\SwaggerSettings;
use PHPUnit\Framework\TestCase;

class SwaggerSettingsTest extends
	TestCase
{
	public function testDefaultsAreApplied(): void
	{
		$settings = SwaggerSettings::normalize([]);

		self::assertTrue($settings['enabled']);
		self::assertTrue($settings['cache_enabled']);
		self::assertSame(3600, $settings['cache_ttl']);
		self::assertSame([], $settings['include_dirs']);
		self::assertSame([], $settings['allowed_groups']);
	}

	public function testInvalidServersThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SwaggerSettings::normalize([
			'servers' => [
				['url' => ''],
			],
		]);
	}

	public function testInvalidAllowedGroupsThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SwaggerSettings::normalize([
			'allowed_groups' => ['admins'],
		]);
	}

	public function testInvalidAllowedIpsThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		SwaggerSettings::normalize([
			'allowed_ips' => ['999.1.1.1'],
		]);
	}

	public function testNormalizesAndDeduplicatesLists(): void
	{
		$settings = SwaggerSettings::normalize([
			'cache_ttl'      => '120',
			'include_dirs'   => [
				' lib ',
				'lib',
				'routes'
			],
			'allowed_groups' => [
				'1',
				2,
				2
			],
			'allowed_ips'    => [
				'127.0.0.1',
				'127.0.0.1'
			],
		]);

		self::assertSame(120, $settings['cache_ttl']);
		self::assertSame([
			'lib',
			'routes'
		], $settings['include_dirs']);
		self::assertSame([
			1,
			2
		], $settings['allowed_groups']);
		self::assertSame(['127.0.0.1'], $settings['allowed_ips']);
	}
}
