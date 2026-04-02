<?php

namespace K4T\Docs\Http;

use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Engine\Response\Json;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\HttpResponse;
use K4T\Docs\Services\AccessPolicy;
use K4T\Docs\Services\SwaggerService;
use Throwable;

class Documentation
{
	private const MODULE_ID = 'k4t.docs';
	private const LOG_FILE = '/k4t.docs.log';

	/**
	 * @param HttpRequest $request
	 *
	 * @return HttpResponse
	 */
	public static function scalar(HttpRequest $request): HttpResponse
	{
		$settings = self::safeLoadSettings();

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

			$response = self::applySecurityHeaders($response);
			self::applyDiagnosticHeaders($response, $settings, 'OFF', 'error', 0.0);

			return $response;
		}

		$cacheStatus      = 'OFF';
		$generationTimeMs = 0.0;
		try {
			$swaggerPayload   = SwaggerService::generateJson($request);
			$swaggerJson      = $swaggerPayload['json'];
			$cacheStatus      = $swaggerPayload['cache_status'];
			$generationTimeMs = $swaggerPayload['generation_time_ms'];
		} catch (Throwable $e) {
			self::logError('OpenAPI generation failed in scalar()', [
				'exception' => $e->getMessage(),
			]);

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

			$response = self::applySecurityHeaders($response);
			self::applyDiagnosticHeaders($response, $settings, $cacheStatus, 'error', $generationTimeMs);

			return $response;
		}

		$scalarScriptUrl  = htmlspecialchars(self::getScalarScriptUrl(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$shouldUseDataUrl = self::shouldUseDataUrl($settings);
		$source           = $shouldUseDataUrl ? 'data-url' : 'inline';

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
			$response = self::applySecurityHeaders($response);
			self::applyDiagnosticHeaders($response, $settings, $cacheStatus, $source, $generationTimeMs);

			return $response;
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
		$response = self::applySecurityHeaders($response);
		self::applyDiagnosticHeaders($response, $settings, $cacheStatus, $source, $generationTimeMs);

		return $response;
	}

	/**
	 * @param HttpRequest $request
	 *
	 * @return Json
	 */
	public static function json(HttpRequest $request): Json
	{
		$settings = self::safeLoadSettings();

		$access = self::checkAccess($request, $settings);
		if ($access !== null) {
			$response = new Json(['error' => $access['message']], $access['status_code']);
			self::applyDiagnosticHeaders($response, $settings, 'OFF', 'json-error', 0.0);

			return $response;
		}

		try {
			$payload = SwaggerService::generateJson($request);
			$data    = json_decode($payload['json'], true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($data)) {
				throw new \RuntimeException('OpenAPI JSON payload is not an object');
			}

			$response = new Json($data);
			self::applyDiagnosticHeaders($response, $settings, $payload['cache_status'], 'json', $payload['generation_time_ms']);

			return $response;
		} catch (Throwable $e) {
			self::logError('OpenAPI generation failed in json()', [
				'exception' => $e->getMessage(),
			]);

			$response = new Json([
				'error'   => 'OpenAPI generation failed',
				'message' => $e->getMessage(),
			], 500);
			self::applyDiagnosticHeaders($response, $settings, 'OFF', 'json-error', 0.0);

			return $response;
		}
	}

	/**
	 * @return array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     cache_revision:string,
	 *     debug_headers_enabled:bool,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * }|null
	 */
	private static function safeLoadSettings(): ?array
	{
		try {
			return SwaggerService::getSettings();
		} catch (Throwable $e) {
			self::logError('Failed to load swagger settings', [
				'exception' => $e->getMessage(),
			]);

			return null;
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
	 *     cache_revision:string,
	 *     debug_headers_enabled:bool,
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
			return [
				'status_code' => 500,
				'status_text' => '500 Internal Server Error',
				'message'     => 'Invalid swagger_settings configuration',
			];
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
	 *     cache_revision:string,
	 *     debug_headers_enabled:bool,
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

		$hasGroupRestrictions = $settings['allowed_groups'] !== [];
		$hasIpRestrictions    = $settings['allowed_ips'] !== [];

		return !$hasGroupRestrictions && !$hasIpRestrictions;
	}

	/**
	 * @param string $json
	 *
	 * @return string
	 */
	private static function escapeJsonForInlineScript(string $json): string
	{
		return str_replace('</script', '<\\/script', $json);
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
	 * @param HttpResponse $response
	 *
	 * @return HttpResponse
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
	 * @param object $response
	 * @param array{
	 *     enabled:bool,
	 *     allowed_groups:list<int>,
	 *     allowed_ips:list<string>,
	 *     cache_enabled:bool,
	 *     cache_ttl:int,
	 *     cache_revision:string,
	 *     debug_headers_enabled:bool,
	 *     servers:list<array{url:string, description:string|null}>,
	 *     include_dirs:list<string>,
	 *     exclude_dirs:list<string>,
	 *     include_modules:list<string>
	 * }|null $settings
	 * @param string $cacheStatus
	 * @param string $source
	 * @param float  $generationTimeMs
	 *
	 * @return void
	 */
	private static function applyDiagnosticHeaders(object $response, ?array $settings, string $cacheStatus, string $source, float $generationTimeMs): void
	{
		if ($settings === null || $settings['debug_headers_enabled'] === false) {
			return;
		}

		self::setHeader($response, 'X-K4T-Docs-Cache', $cacheStatus);
		self::setHeader($response, 'X-K4T-Docs-Source', $source);
		self::setHeader($response, 'X-K4T-Docs-Gen-Time', sprintf('%.2fms', $generationTimeMs));
	}

	/**
	 * @param object $response
	 * @param string $name
	 * @param string $value
	 *
	 * @return void
	 */
	private static function setHeader(object $response, string $name, string $value): void
	{
		if (method_exists($response, 'addHeader')) {
			$response->addHeader($name, $value);

			return;
		}

		if (method_exists($response, 'setHeader')) {
			$response->setHeader($name, $value);

			return;
		}

		if (!headers_sent()) {
			header($name . ': ' . $value, true);
		}
	}

	/**
	 * @param string               $message
	 * @param array<string, mixed> $context
	 *
	 * @return void
	 */
	private static function logError(string $message, array $context = []): void
	{
		$payload = [
			'module'  => self::MODULE_ID,
			'message' => $message,
			'context' => $context,
		];

		try {
			if (class_exists(Debug::class)) {
				Debug::writeToFile($payload, $message, self::LOG_FILE);

				return;
			}
		} catch (Throwable) {
		}

		error_log('[k4t.docs] ' . $message . ' ' . json_encode($context));
	}
}
