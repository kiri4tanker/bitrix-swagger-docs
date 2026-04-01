<?php

namespace K4T\Docs\Tests\Unit;

use K4T\Docs\Services\AccessPolicy;
use PHPUnit\Framework\TestCase;

class AccessPolicyTest extends
	TestCase
{
	public function testDisabledDocsAlwaysDenied(): void
	{
		$settings = [
			'enabled'        => false,
			'allowed_groups' => [],
			'allowed_ips'    => [],
		];

		self::assertFalse(AccessPolicy::isAllowed($settings, [1], '127.0.0.1'));
	}

	public function testNoRestrictionsAllowAccess(): void
	{
		$settings = [
			'enabled'        => true,
			'allowed_groups' => [],
			'allowed_ips'    => [],
		];

		self::assertTrue(AccessPolicy::isAllowed($settings, [], '203.0.113.9'));
	}

	public function testGroupRuleAllowsAccess(): void
	{
		$settings = [
			'enabled'        => true,
			'allowed_groups' => [
				1,
				5
			],
			'allowed_ips'    => [],
		];

		self::assertTrue(AccessPolicy::isAllowed($settings, [
			2,
			5
		], '203.0.113.9'));
	}

	public function testIpRuleAllowsAccess(): void
	{
		$settings = [
			'enabled'        => true,
			'allowed_groups' => [],
			'allowed_ips'    => ['10.0.0.0/8'],
		];

		self::assertTrue(AccessPolicy::isAllowed($settings, [], '10.1.2.3'));
	}

	public function testDeniedWhenNoRuleMatches(): void
	{
		$settings = [
			'enabled'        => true,
			'allowed_groups' => [1],
			'allowed_ips'    => ['192.168.0.0/16'],
		];

		self::assertFalse(AccessPolicy::isAllowed($settings, [2], '203.0.113.9'));
	}

	public function testValidIpRuleValidator(): void
	{
		self::assertTrue(AccessPolicy::isValidIpRule('127.0.0.1'));
		self::assertTrue(AccessPolicy::isValidIpRule('10.0.0.0/8'));
		self::assertFalse(AccessPolicy::isValidIpRule('10.0.0.0/66'));
		self::assertFalse(AccessPolicy::isValidIpRule('bad-value'));
	}
}
