# k4t.docs

[![Packagist](https://img.shields.io/packagist/v/kiri4tanker/k4t.docs?logo=packagist&label=Packagist)](https://packagist.org/packages/kiri4tanker/k4t.docs)
[![CI](https://github.com/kiri4tanker/bitrix-swagger-docs/actions/workflows/ci.yml/badge.svg)](https://github.com/kiri4tanker/bitrix-swagger-docs/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/kiri4tanker/k4t.docs)](https://packagist.org/packages/kiri4tanker/k4t.docs)
[![Security Policy](https://img.shields.io/badge/security-policy-brightgreen)](SECURITY.md)

English version: [README.en.md](README.en.md)

Модуль Composer для Bitrix D7, который собирает OpenAPI-спецификацию (`swagger-php`) из ваших модулей и отдает:

- интерактивную документацию (`Scalar UI`)
- JSON-спецификацию (`docs.json`)

Пакет на Packagist: [kiri4tanker/k4t.docs](https://packagist.org/packages/kiri4tanker/k4t.docs)

Процессы проекта:
- [SECURITY.md](SECURITY.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)
- [CHANGELOG.md](CHANGELOG.md)

## Что умеет модуль

- Сканирует указанные папки во всех или выбранных модулях Bitrix.
- Генерирует OpenAPI через `zircote/swagger-php`.
- Позволяет задать список `servers` или использовать автоопределение хоста.
- Поддерживает включение/отключение документации (`enabled`).
- Поддерживает whitelist групп (`allowed_groups`).
- Поддерживает whitelist IP/CIDR (`allowed_ips`).
- Использует локальный (self-hosted) Scalar bundle из модуля, без внешнего CDN.
- В UI грузит спецификацию по URL (`docs.json`), без inline JSON в HTML.
- Кэширует результат генерации в Managed Cache Bitrix.
- Возвращает понятные ошибки конфигурации/генерации.

## Требования

- PHP `>= 8.2`
- Bitrix D7
- Composer
- `zircote/swagger-php` (подтягивается автоматически)

## Быстрый старт

1. Добавьте в `composer.json` проекта installer path для модулей Bitrix:

```json
{
	"extra": {
		"installer-paths": {
			"local/modules/{$name}/": [
				"type:bitrix-d7-module"
			]
		}
	}
}
```

2. Установите пакет:

```bash
composer require kiri4tanker/k4t.docs
```

3. Установите модуль в админке Bitrix:

`Настройки -> Настройки продукта -> Модули -> k4t.docs`

4. Добавьте маршруты `docs` и `docs.json` (пример ниже).
5. Добавьте `swagger_settings` в корневой `.settings.php`.

## Подключение маршрутов

Пример файла `/local/routes/api.php`:

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

После этого:

- `/docs/` - UI документации
- `/docs.json` - OpenAPI JSON

Примечание по фронту docs:

- UI загружает схему через `data-url` (`/docs.json`), а не вставляет спецификацию inline.
- JS Scalar берется из локального файла:
- `/local/modules/k4t.docs/install/assets/scalar/standalone.js`
- если модуль установлен в `bitrix/modules`, используется:
- `/bitrix/modules/k4t.docs/install/assets/scalar/standalone.js`

## Конфигурация `.settings.php`

Пример полного блока:

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
			'cache_revision' => '1',
			'debug_headers_enabled' => false,
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
			'include_modules' => [],
		],
		'readonly' => false,
	],
];
```

## Справочник параметров

| Ключ              | Тип        | По умолчанию       | Описание                                                                     |
|-------------------|------------|--------------------|------------------------------------------------------------------------------|
| `enabled`         | `bool`     | `true`             | Глобальный флаг доступности docs. Если `false` - ответ `404`.                |
| `allowed_groups`  | `int[]`    | `[]`               | Разрешенные группы пользователей Bitrix.                                     |
| `allowed_ips`     | `string[]` | `[]`               | Разрешенные IP/CIDR, например `127.0.0.1`, `10.0.0.0/8`.                     |
| `cache_enabled`   | `bool`     | `true`             | Включает Managed Cache для готовой OpenAPI схемы.                            |
| `cache_ttl`       | `int`      | `3600`             | TTL кэша в секундах. `0` = без TTL-ограничения (по механике Bitrix cache).   |
| `cache_revision`  | `string`   | `'1'`              | Ревизия кэша. Измените значение для принудительного сброса ключа кэша.        |
| `debug_headers_enabled` | `bool` | `false`          | Включает диагностические заголовки `X-K4T-Docs-*` в ответах docs/json.        |
| `servers`         | `array[]`  | `[]`               | Список серверов OpenAPI. Если пусто, сервер вычисляется из текущего запроса. |
| `include_dirs`    | `string[]` | `[]`               | Каталоги внутри каждого модуля для сканирования. Пусто = все каталоги.       |
| `exclude_dirs`    | `string[]` | `[]`               | Каталоги, которые исключаются из `include_dirs`.                             |
| `include_modules` | `string[]` | `[]`               | Whitelist модулей. Если пусто - сканируются все установленные.               |

## Логика доступа

Проверка доступа выполняется так:

1. Если `enabled=false` -> `404`.
2. Если `allowed_groups` и `allowed_ips` пустые -> доступ разрешен всем.
3. Если есть ограничения -> доступ разрешен при совпадении хотя бы одного правила:

- пользователь входит в `allowed_groups`
- IP клиента попал в `allowed_ips`

Иначе возвращается `403`.

## Кэширование (Managed Cache Bitrix)

Модуль сохраняет сгенерированный OpenAPI JSON в Managed Cache и переиспользует его до истечения `cache_ttl`.

Ключ кэша строится из:

- найденных путей для сканирования
- конфигурации `servers`
- `include_dirs`/`exclude_dirs`/`include_modules`
- `cache_revision`
- версии модуля (`install/version.php`)

Практически это означает:

- меняете конфиг сканирования -> формируется новый кэш-ключ
- меняете `cache_revision` -> формируется новый кэш-ключ (принудительный сброс)
- неизменный конфиг + активный TTL -> документация отдается из кэша

Обычно файлы кэша Bitrix лежат в `bitrix/cache` и/или `bitrix/managed_cache` (зависит от конфигурации проекта).

## Статусы и ошибки

`/docs/`:

- `200` - документация отдана
- `403` - доступ запрещен
- `404` - docs отключены
- `500` - ошибка конфигурации или генерации

`/docs.json`:

- `200` - OpenAPI JSON
- `403`, `404`, `500` - JSON с полем `error`

Типовые причины `500`:

- невалидный `swagger_settings` (например, неправильный тип поля)
- пустой `servers[].url`
- после фильтрации не найдено ни одной директории для сканирования

## Security (CSP)

Для страницы `/docs/` модуль выставляет security headers:

- `Content-Security-Policy`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: no-referrer`
- `X-Frame-Options: SAMEORIGIN`

При `debug_headers_enabled=true` дополнительно отдаются диагностические заголовки:

- `X-K4T-Docs-Cache: HIT|MISS|OFF`
- `X-K4T-Docs-Source: data-url|inline|json|...`
- `X-K4T-Docs-Gen-Time: <ms>`

CSP настроен с акцентом на self-hosted сценарий:

- `script-src 'self'`
- `connect-src 'self'`
- `default-src 'none'`

Это снижает риски:

- supply-chain через внешние CDN скрипты
- XSS через inline вставку OpenAPI JSON

## Рекомендации по конфигу

- Не ставьте `include_dirs = ['lib']` без нужды на крупных проектах. Лучше сужать до API-каталогов.
- Для production обычно лучше ограничить docs через `allowed_groups` (например, администраторы).
- Для production обычно лучше ограничить docs через `allowed_ips` (офис/VPN).
- Если много кода и редкие изменения API, увеличьте `cache_ttl`.
- Если активно разрабатываете API, уменьшите `cache_ttl` или временно выключите кэш.

## Примеры для окружений

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

## Разработка модуля

Установка dev-зависимостей:

```bash
composer install
```

Локальные проверки:

```bash
composer lint
composer stan
composer test
```

CI:

- workflow: `.github/workflows/ci.yml`
- запускается на `push` и `pull_request`
- шаги: `composer validate`, `lint`, `phpstan`, `phpunit`

## Troubleshooting

### `/docs.json` возвращает `500` и сообщение про директории

Проверьте:

- корректность `include_dirs`
- корректность `include_modules`
- физическое наличие папок внутри `local/modules/<module_id>/...`

### Всегда `403`

Проверьте:

- `allowed_groups` (ID групп)
- `allowed_ips` (формат IP/CIDR)
- фактический IP клиента за прокси/балансировщиком

### Не видны изменения в документации

Варианты:

- дождаться истечения `cache_ttl`
- временно выключить `cache_enabled`
- очистить кэш Bitrix

### `/docs/` не грузит JS (404)

Проверьте наличие файла:

- `local/modules/k4t.docs/install/assets/scalar/standalone.js`

Если модуль в `bitrix/modules`, проверьте:

- `bitrix/modules/k4t.docs/install/assets/scalar/standalone.js`

## Лицензия

MIT
