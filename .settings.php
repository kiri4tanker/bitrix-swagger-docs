<?php

return [
	'swagger_settings' => [
		'value'    => [
			'servers'      => [
				[
					'url'         => '/api/v1',
					'description' => 'Default'
				],
			],
			'include_dirs' => [
				'routes',
				'lib'
			],
		],
		'readonly' => false,
	],
];