<?php

namespace K4T\Docs\Http;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Engine\Response\Json;
use K4T\Docs\Services\AccessPolicy;
use K4T\Docs\Services\SwaggerService;

class Documentation
{
	private const MODULE_ID = 'k4t.docs';

	/**
	 * @param \Bitrix\Main\HttpRequest $request
	 *
	 * @return \Bitrix\Main\HttpResponse
	 */
	public static function scalar(HttpRequest $request): HttpResponse
	{
		$settings = null;
		try {
			$settings = SwaggerService::getSettings();
		} catch (\Throwable) {
		}

		$access = self::checkAccess($request, $settings);
		if ($access !== null) {
			$error = htmlspecialchars($access['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$html  = <<<HTML
        <!doctype html>
        <html>
          <head>
            <title>API Documentation</title>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
          </head>
          <body>
            <h1>{$error}</h1>
          </body>
        </html>
HTML;

			$response = (new HttpResponse())
				->setStatus($access['status_text'])
				->setContent($html);

			return self::applySecurityHeaders($response);
		}

		try {
			$swaggerJson = SwaggerService::generate($request)->toJson();
		} catch (\Throwable $e) {
			$error = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$html  = <<<HTML
        <!doctype html>
        <html>
          <head>
            <title>API Documentation Error</title>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
          </head>
          <body>
            <h1>OpenAPI generation failed</h1>
            <p>{$error}</p>
          </body>
        </html>
HTML;

			$response = (new HttpResponse())
				->setStatus('500 Internal Server Error')
				->setContent($html);

			return self::applySecurityHeaders($response);
		}

		$scalarScriptUrl  = htmlspecialchars(self::getScalarScriptUrl(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$shouldUseDataUrl = self::shouldUseDataUrl($settings);

		if ($shouldUseDataUrl) {
			$jsonSpecUrl = htmlspecialchars(self::buildJsonSpecUrl($request), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$html        = <<<HTML
        <!doctype html>
        <html>
          <head>
            <title>API Documentation</title>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
          </head>
          <body>
            <script id="api-reference" data-url="{$jsonSpecUrl}" data-configuration='{"theme":"purple"}'></script>
            <script src="{$scalarScriptUrl}" defer></script>
          </body>
        </html>
HTML;

			$response = (new HttpResponse())->setContent($html);

			return self::applySecurityHeaders($response);
		}

		$safeSwaggerJson = self::escapeJsonForInlineScript($swaggerJson);

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
{$safeSwaggerJson}
            </script>
            <script src="{$scalarScriptUrl}" defer></script>
          </body>
        </html>
HTML;

		$response = (new HttpResponse())->setContent($html);

		return self::applySecurityHeaders($response);
	}

	/**
	 * @param HttpRequest $request
	 *
	 * @return Json
	 */
	public static function json(HttpRequest $request): Json
	{
		$access = self::checkAccess($request);
		if ($access !== null) {
			return new Json(['error' => $access['message']], $access['status_code']);
		}

		try {
			return new Json(SwaggerService::generate($request));
		} catch (\Throwable $e) {
			return new Json([
				'error'   => 'OpenAPI generation failed',
				'message' => $e->getMessage(),
			], 500);
		}
	}

	/**
	 * @param HttpRequest $request
	 * @param array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * }|null $settings
	 *
	 * @return array{status_code:int, status_text:string, message:string}|null
	 */
	private static function checkAccess(HttpRequest $request, ?array $settings = null): ?array
	{
		if ($settings === null) {
			try {
				$settings = SwaggerService::getSettings();
			} catch (\Throwable $e) {
				return [
					'status_code' => 500,
					'status_text' => '500 Internal Server Error',
					'message'     => 'Invalid swagger_settings configuration',
				];
			}
		}

		if ($settings['enabled'] === false) {
			return [
				'status_code' => 404,
				'status_text' => '404 Not Found',
				'message'     => 'API documentation is disabled',
			];
		}

		$clientIp   = (string)$request->getRemoteAddress();
		$userGroups = self::getCurrentUserGroups();
		if (!AccessPolicy::isAllowed($settings, $userGroups, $clientIp)) {
			return [
				'status_code' => 403,
				'status_text' => '403 Forbidden',
				'message'     => 'Access denied',
			];
		}

		return null;
	}

	/**
	 * @return list<int>
	 */
	private static function getCurrentUserGroups(): array
	{
		global $USER;

		if (!is_object($USER) || !method_exists($USER, 'GetUserGroupArray')) {
			return [];
		}

		$groupIds = $USER->GetUserGroupArray();
		if (!is_array($groupIds)) {
			return [];
		}

		return array_map('intval', $groupIds);
	}

	/**
	 * @param array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * }|null $settings
	 *
	 * @return bool
	 */
	private static function shouldUseDataUrl(?array $settings): bool
	{
		if ($settings === null) {
			return true;
		}

		$hasGroupRestrictions = !empty($settings['allowed_groups']);
		$hasIpRestrictions    = !empty($settings['allowed_ips']);

		return !$hasGroupRestrictions && !$hasIpRestrictions;
	}

	/**
	 * @param string $json
	 *
	 * @return string
	 */
	private static function escapeJsonForInlineScript(string $json): string
	{
		return str_replace('</script', '<\/script', $json);
	}

	/**
	 * @param HttpRequest $request
	 *
	 * @return string
	 */
	private static function buildJsonSpecUrl(HttpRequest $request): string
	{
		$requestUri = '';
		if (method_exists($request, 'getRequestUri')) {
			$requestUri = (string)$request->getRequestUri();
		}

		if ($requestUri === '') {
			$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/docs/');
		}

		$path = parse_url($requestUri, PHP_URL_PATH);
		if (!is_string($path) || $path === '') {
			return '/docs.json';
		}

		$path = rtrim($path, '/');
		if ($path === '') {
			return '/docs.json';
		}

		if (str_ends_with($path, '.json')) {
			return $path;
		}

		return $path . '.json';
	}

	/**
	 * @return string
	 */
	private static function getScalarScriptUrl(): string
	{
		$localPath  = '/local/modules/' . self::MODULE_ID . '/install/assets/scalar/standalone.js';
		$bitrixPath = '/bitrix/modules/' . self::MODULE_ID . '/install/assets/scalar/standalone.js';
		$docRoot    = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

		if ($docRoot !== '' && is_file($docRoot . $localPath)) {
			return $localPath;
		}

		if ($docRoot !== '' && is_file($docRoot . $bitrixPath)) {
			return $bitrixPath;
		}

		return $localPath;
	}

	/**
	 * @param \Bitrix\Main\HttpResponse $response
	 *
	 * @return \Bitrix\Main\HttpResponse
	 */
	private static function applySecurityHeaders(HttpResponse $response): HttpResponse
	{
		$csp = implode('; ', [
			"default-src 'none'",
			"script-src 'self'",
			"connect-src 'self'",
			"img-src 'self' data: https:",
			"style-src 'self' 'unsafe-inline'",
			"font-src 'self' data:",
			"base-uri 'none'",
			"frame-ancestors 'self'",
			"form-action 'none'",
			"object-src 'none'",
		]);

		self::setHeader($response, 'Content-Security-Policy', $csp);
		self::setHeader($response, 'X-Content-Type-Options', 'nosniff');
		self::setHeader($response, 'Referrer-Policy', 'no-referrer');
		self::setHeader($response, 'X-Frame-Options', 'SAMEORIGIN');

		return $response;
	}

	/**
	 * @param \Bitrix\Main\HttpResponse $response
	 * @param string                    $name
	 * @param string                    $value
	 *
	 * @return void
	 */
	private static function setHeader(HttpResponse $response, string $name, string $value): void
	{
		if (method_exists($response, 'addHeader')) {
			$response->addHeader($name, $value);

			return;
		}

		if (method_exists($response, 'setHeader')) {
			$response->setHeader($name, $value);

			return;
		}

		header($name . ': ' . $value, true);
	}
}
