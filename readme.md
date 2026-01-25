# Bitrix Swagger Docs

Автоматическая документация Bitrix на Swagger.

## Требования
* PHP >= 8.2
* Composer
* zircote/swagger-php

## Установка
### Настройка composer.json проекта
Для корректной установки модуля в директорию local/modules/ необходимо добавить следующие настройки в корневой файл composer.json:

```json
{
  "extra": {
    "installer-paths": {
      "local/modules/{$name}/": ["type:bitrix-d7-module"]
    }
  }
}