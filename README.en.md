# k4t.docs

[![Packagist](https://img.shields.io/packagist/v/kiri4tanker/k4t.docs?logo=packagist&label=Packagist)](https://packagist.org/packages/kiri4tanker/k4t.docs)
[![CI](https://github.com/kiri4tanker/bitrix-swagger-docs/actions/workflows/ci.yml/badge.svg)](https://github.com/kiri4tanker/bitrix-swagger-docs/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/kiri4tanker/k4t.docs)](https://packagist.org/packages/kiri4tanker/k4t.docs)

Русская версия: [readme.md](readme.md)

Composer module for Bitrix D7 that builds an OpenAPI specification (`swagger-php`) from your modules and serves:
- interactive API docs (`Scalar UI`)
- OpenAPI JSON (`docs.json`)

Packagist: [kiri4tanker/k4t.docs](https://packagist.org/packages/kiri4tanker/k4t.docs)

## Features

- Scans selected directories across all or selected Bitrix modules.
- Generates OpenAPI using `zircote/swagger-php`.
- Supports explicit `servers` configuration or host auto-detection.
- Supports docs enable/disable (`enabled`).
- Supports group whitelist (`allowed_groups`).
- Supports IP/CIDR whitelist (`allowed_ips`).
- Uses self-hosted Scalar bundle (no external CDN dependency).
- Loads OpenAPI in UI by URL (`docs.json`) instead of inline JSON.
- Caches generated OpenAPI in Bitrix Managed Cache.
- Returns clear config/runtime error responses.

## Requirements

- PHP `>= 8.2`
- Bitrix D7
- Composer
- `zircote/swagger-php` (installed automatically)

## Quick Start

1. Add installer path to your project `composer.json`:

```json
{
  "extra": {
    "installer-paths": {
      "local/modules/{$name}/": ["type:bitrix-d7-module"]
    }
  }
}
```

2. Install package:

```bash
composer require kiri4tanker/k4t.docs
```

3. Install module in Bitrix admin:

`Settings -> Product Settings -> Modules -> k4t.docs`

4. Add routes for `docs` and `docs.json`.
5. Add `swagger_settings` to project root `.settings.php`.

## Routing

Example `/local/routes/api.php`:

```php
<?php

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Routing\RoutingConfigurator;
use K4T\Docs\Http\Documentation;

\Bitrix\Main\Loader::includeModule('k4t.docs');

return static function (RoutingConfigurator $routes) {
	$routes->get('docs/', fn(HttpRequest $request) => Documentation::scalar($request));
	$routes->get('docs.json', fn(HttpRequest $request) => Documentation::json($request));
};
```

Then:
- `/docs/` - docs UI
- `/docs.json` - OpenAPI JSON

Frontend notes:
- UI loads schema via `data-url` (`/docs.json`), not inline JSON.
- Scalar JS is loaded from local module file:
- `/local/modules/k4t.docs/install/assets/scalar/standalone.js`
- if module is installed in `bitrix/modules`:
- `/bitrix/modules/k4t.docs/install/assets/scalar/standalone.js`

## `.settings.php` Configuration

Full example:

```php
<?php

return [
	'swagger_settings' => [
		'value' => [
			'enabled' => true,
			'allowed_groups' => [1],
			'allowed_ips' => [],
			'cache_enabled' => true,
			'cache_ttl' => 3600,
			'servers' => [
				[
					'url' => '/api/v1',
					'description' => 'Default',
				],
			],
			'include_dirs' => [
				'routes',
				'lib',
			],
			'exclude_dirs' => [
				'tests',
			],
			'include_modules' => [
				'mycompany.api',
				'k4t.docs',
			],
		],
		'readonly' => false,
	],
];
```

## Settings Reference

| Key | Type | Default | Description |
|---|---|---|---|
| `enabled` | `bool` | `true` | Global docs switch. `false` returns `404`. |
| `allowed_groups` | `int[]` | `[]` | Allowed Bitrix user groups. |
| `allowed_ips` | `string[]` | `[]` | Allowed IP/CIDR values like `127.0.0.1`, `10.0.0.0/8`. |
| `cache_enabled` | `bool` | `true` | Enables Managed Cache for generated OpenAPI. |
| `cache_ttl` | `int` | `3600` | Cache TTL in seconds. `0` means no TTL limit (Bitrix cache behavior). |
| `servers` | `array[]` | `[]` | OpenAPI servers list. If empty, server is auto-detected from request. |
| `include_dirs` | `string[]` | `['lib','routes']` | Per-module directories to scan. |
| `exclude_dirs` | `string[]` | `[]` | Directories excluded from `include_dirs`. |
| `include_modules` | `string[]` | `[]` | Module whitelist. Empty means all installed modules. |

## Access Rules

Access logic:

1. `enabled=false` -> `404`.
2. If both `allowed_groups` and `allowed_ips` are empty -> public access.
3. If restrictions are set -> access granted when at least one rule matches:
- user is in `allowed_groups`
- client IP matches `allowed_ips`

Otherwise `403`.

## Caching (Bitrix Managed Cache)

OpenAPI result is cached and reused until `cache_ttl` expires.

Cache key includes:
- found scan paths
- `servers` config
- `include_dirs`/`exclude_dirs`/`include_modules`

In practice:
- config changes -> new cache key
- same config + valid TTL -> served from cache

Bitrix cache files are typically in `bitrix/cache` and/or `bitrix/managed_cache` (depends on your setup).

## Statuses and Errors

`/docs/`:
- `200` - docs rendered
- `403` - access denied
- `404` - docs disabled
- `500` - config/generation error

`/docs.json`:
- `200` - OpenAPI JSON
- `403`, `404`, `500` - JSON response with `error`

Common `500` reasons:
- invalid `swagger_settings` types
- empty `servers[].url`
- no directories found after include/exclude/module filters

## Security (CSP)

For `/docs/`, module sets:
- `Content-Security-Policy`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: no-referrer`
- `X-Frame-Options: SAMEORIGIN`

CSP is self-hosted oriented:
- `script-src 'self'`
- `connect-src 'self'`
- `default-src 'none'`

This reduces:
- CDN supply-chain risks
- XSS risk from inline OpenAPI injection

## Environment Examples

### Local / Dev

```php
'enabled' => true,
'allowed_groups' => [],
'allowed_ips' => [],
'cache_enabled' => false,
'cache_ttl' => 0,
```

### Stage

```php
'enabled' => true,
'allowed_groups' => [1],
'allowed_ips' => ['10.0.0.0/8', '127.0.0.1'],
'cache_enabled' => true,
'cache_ttl' => 300,
```

### Production

```php
'enabled' => true,
'allowed_groups' => [1],
'allowed_ips' => ['10.10.0.0/16'],
'cache_enabled' => true,
'cache_ttl' => 3600,
```

## Development

Install dev dependencies:

```bash
composer install
```

Local checks:

```bash
composer lint
composer stan
composer test
```

CI:
- workflow: `.github/workflows/ci.yml`
- runs on `push` and `pull_request`
- steps: `composer validate`, `lint`, `phpstan`, `phpunit`

## Troubleshooting

### `/docs.json` returns `500` with directories error

Check:
- `include_dirs`
- `include_modules`
- actual directories under `local/modules/<module_id>/...`

### Always `403`

Check:
- `allowed_groups` values
- `allowed_ips` format
- real client IP behind reverse proxy/load balancer

### Docs do not update

Try:
- wait for `cache_ttl`
- set `cache_enabled=false` temporarily
- clear Bitrix cache

### `/docs/` JS returns `404`

Check:
- `local/modules/k4t.docs/install/assets/scalar/standalone.js`

If module is in `bitrix/modules`:
- `bitrix/modules/k4t.docs/install/assets/scalar/standalone.js`

## License

MIT
