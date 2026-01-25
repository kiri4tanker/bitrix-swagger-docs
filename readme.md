# Bitrix Swagger Docs

Автоматическая документация Bitrix на Swagger. 
Пакет сканирует модули Bitrix и генерирует интерактивную документацию Scalar или JSON-схему.

## Требования
* PHP >= 8.2
* Composer
* zircote/swagger-php

## Установка

### Настройка composer.json проекта

Для корректной установки модуля в директорию `local/modules/` необходимо добавить следующие настройки в корневой файл composer.json:

```json
{
  "extra": {
    "installer-paths": {
      "local/modules/{$name}/": ["type:bitrix-d7-module"]
    }
  }
}
```

### Команда установки
```
composer require kiri4tanker/k4t.docs
```

## Регистрация модуля

После завершения загрузки необходимо перейти в административную часть сайта: 
`Настройки -> Настройки продукта -> Модули` и выполнить установку модуля `k4t.docs`.

## Настройка (.settings.php)

Для работы генератора необходимо указать параметры сканирования в файле `.settings` в корне модуля

`servers` - массив объектов, определяющих базовые адреса вашего API.
Эти данные подставляются в блок "Server" в интерфейсе документации.

>Если оставить массив пустым, модуль попытается определить текущий хост автоматически.

`include_dirs` - список относительных путей внутри каждого установленного модуля Bitrix.
Модуль проходит циклом по всем активным модулям в `local/modules`.

Если внутри модуля существует папка из вашего списка (например, `lib/Controllers`), она будет просканирована на наличие аннотаций `@OA`.

```
<?php

return [
    'swagger_settings' => [
       'value' => [
          'servers' => [
             [
                'url' => '/api/v1',
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
```

>Рекомендация: указывайте только те папки, где действительно лежат контроллеры (например, `lib/Api` или `routes`), чтобы ускорить процесс генерации документации.

## Подключение маршрутов (Routing)

Для отображения документации необходимо зарегистрировать маршруты в системе роутинга Bitrix (например, в файле `/local/routes/api.php`):

```
use Bitrix\Main\Routing\RoutingConfigurator;
use Bitrix\Main\HttpRequest;
use K4T\Docs\Http\Documentation;

\Bitrix\Main\Loader::includeModule('k4t.docs');

return static function (RoutingConfigurator $routes) {
    // Интерактивная документация Scalar
    $routes->get('docs/', fn(HttpRequest $request) => Documentation::scalar($request));
    
    // JSON-схема в формате OpenAPI
    $routes->get('docs.json', fn(HttpRequest $request) => Documentation::json($request));
};
```