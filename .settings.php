<?php

return [
	'swagger_settings' => [
		'value'    => [
			'enabled'         => true,
			'allowed_groups'  => [],
			'allowed_ips'     => [],
			'cache_enabled'   => true,
			'cache_ttl'       => 3600,
			'servers'         => [
				[
					'url'         => '/api/v1',
					'description' => 'Default'
				],
			],
			'include_dirs'    => [],
			'exclude_dirs'    => [
				'tests'
			],
			'include_modules' => [],
		],
		'readonly' => false,
	],
];
