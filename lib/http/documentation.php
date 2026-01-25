<?php

namespace K4T\Docs\Http;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Engine\Response\Json;
use K4T\Docs\Services\SwaggerService;

class Documentation
{
	public static function scalar(HttpRequest $request): HttpResponse
	{
		$swagger = SwaggerService::generate($request);

		$html = <<<HTML
        <!doctype html>
        <html>
          <head>
            <title>API Documentation</title>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
          </head>
          <body>
            <script id="api-reference" data-configuration='{"theme":"purple"}' type="application/json">
                {$swagger->toJson()}
            </script>
            <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
          </body>
        </html>
HTML;

		return (new HttpResponse())->setContent($html);
	}

	public static function json(HttpRequest $request): Json
	{
		return new Json(SwaggerService::generate($request));
	}
}