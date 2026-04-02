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
		$settings = self::safeLoadSettings($request);

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
			self::applyDiagnosticHeaders($response, $settings, 'OFF', 'error', 0.0, 'OFF');

			return $response;
		}

		$cacheStatus      = 'OFF';
		$generationTimeMs = 0.0;
		$resetStatus      = 'OFF';

		if (self::isCacheResetRequested($request)) {
			$token = self::extractQueryParam($request, 'cache_reset_token');
			if (!SwaggerService::isCacheResetAllowed($token)) {
				$response = (new HttpResponse())
					->setStatus('403 Forbidden')
					->setContent(self::renderErrorPage('Access denied', 'Cache reset is not allowed'));
				$response = self::applySecurityHeaders($response);
				self::applyDiagnosticHeaders($response, $settings, 'OFF', 'error', 0.0, 'DENIED');

				return $response;
			}

			try {
				$resetMeta   = SwaggerService::resetCache($request);
				$resetStatus = $resetMeta['cache_status'];
			} catch (Throwable $e) {
				self::logError('OpenAPI cache reset failed in scalar()', [
					'exception' => $e->getMessage(),
				] + self::buildRequestContext($request));

				$response = (new HttpResponse())
					->setStatus('500 Internal Server Error')
					->setContent(self::renderErrorPage('OpenAPI cache reset failed', $e->getMessage()));
				$response = self::applySecurityHeaders($response);
				self::applyDiagnosticHeaders($response, $settings, 'OFF', 'error', 0.0, 'ERROR');

				return $response;
			}
		}

		try {
			$swaggerPayload   = SwaggerService::generateJson($request);
			$swaggerJson      = $swaggerPayload['json'];
			$cacheStatus      = $swaggerPayload['cache_status'];
			$generationTimeMs = $swaggerPayload['generation_time_ms'];
		} catch (Throwable $e) {
			self::logError('OpenAPI generation failed in scalar()', [
				'exception' => $e->getMessage(),
			] + self::buildRequestContext($request));

			$response = (new HttpResponse())
				->setStatus('500 Internal Server Error')
				->setContent(self::renderErrorPage('OpenAPI generation failed', $e->getMessage()));

			$response = self::applySecurityHeaders($response);
			self::applyDiagnosticHeaders($response, $settings, $cacheStatus, 'error', $generationTimeMs, $resetStatus);

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
			self::applyDiagnosticHeaders($response, $settings, $cacheStatus, $source, $generationTimeMs, $resetStatus);

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
		self::applyDiagnosticHeaders($response, $settings, $cacheStatus, $source, $generationTimeMs, $resetStatus);

		return $response;
	}

	/**
	 * @param HttpRequest $request
	 *
	 * @return Json
	 */
	public static function json(HttpRequest $request): Json
	{
		$settings = self::safeLoadSettings($request);

		$access = self::checkAccess($request, $settings);
		if ($access !== null) {
			$response = self::buildJsonResponse(['error' => $access['message']], $access['status_code']);
			self::applyDiagnosticHeaders($response, $settings, 'OFF', 'json-error', 0.0, 'OFF');

			return $response;
		}

		$resetStatus = 'OFF';
		if (self::isCacheResetRequested($request)) {
			$token = self::extractQueryParam($request, 'cache_reset_token');
			if (!SwaggerService::isCacheResetAllowed($token)) {
				$response = self::buildJsonResponse(['error' => 'Cache reset is not allowed'], 403);
				self::applyDiagnosticHeaders($response, $settings, 'OFF', 'json-error', 0.0, 'DENIED');

				return $response;
			}

			try {
				$resetMeta   = SwaggerService::resetCache($request);
				$resetStatus = $resetMeta['cache_status'];
			} catch (Throwable $e) {
				self::logError('OpenAPI cache reset failed in json()', [
					'exception' => $e->getMessage(),
				] + self::buildRequestContext($request));

				$response = self::buildJsonResponse([
					'error'   => 'OpenAPI cache reset failed',
					'message' => $e->getMessage(),
				], 500);
				self::applyDiagnosticHeaders($response, $settings, 'OFF', 'json-error', 0.0, 'ERROR');

				return $response;
			}
		}

		try {
			$payload = SwaggerService::generateJson($request);
			$data    = json_decode($payload['json'], true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($data)) {
				throw new \RuntimeException('OpenAPI JSON payload is not an object');
			}

			$response = self::buildJsonResponse($data, 200);
			self::applyDiagnosticHeaders($response, $settings, $payload['cache_status'], 'json', $payload['generation_time_ms'], $resetStatus);

			return $response;
		} catch (Throwable $e) {
			self::logError('OpenAPI generation failed in json()', [
				'exception' => $e->getMessage(),
			] + self::buildRequestContext($request));

			$response = self::buildJsonResponse([
				'error'   => 'OpenAPI generation failed',
				'message' => $e->getMessage(),
			], 500);
			self::applyDiagnosticHeaders($response, $settings, 'OFF', 'json-error', 0.0, $resetStatus);

			return $response;
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @param int                  $statusCode
	 *
	 * @return Json
	 */
	private static function buildJsonResponse(array $data, int $statusCode): Json
	{
		$response = new Json($data, $statusCode);
		self::setResponseStatus($response, $statusCode);

		return $response;
	}

	/**
	 * @param HttpRequest $request
	 *
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
	private static function safeLoadSettings(HttpRequest $request): ?array
	{
		try {
			return SwaggerService::getSettings();
		} catch (Throwable $e) {
			self::logError('Failed to load swagger settings', [
				'exception' => $e->getMessage(),
			] + self::buildRequestContext($request));

			return null;
		}
	}

	/**
	 * @param string $title
	 * @param string $message
	 *
	 * @return string
	 */
	private static function renderErrorPage(string $title, string $message): string
	{
		$safeTitle   = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

		return <<<HTML
        <!doctype html>
        <html>
          <head>
            <title>{$safeTitle}</title>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
          </head>
          <body>
            <h1>{$safeTitle}</h1>
            <p>{$safeMessage}</p>
          </body>
        </html>
HTML;
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
	 * @param string $cacheResetStatus
	 *
	 * @return void
	 */
	private static function applyDiagnosticHeaders(
		object $response,
		?array $settings,
		string $cacheStatus,
		string $source,
		float $generationTimeMs,
		string $cacheResetStatus
	): void {
		if ($settings === null || $settings['debug_headers_enabled'] === false) {
			return;
		}

		self::setHeader($response, 'X-K4T-Docs-Cache', $cacheStatus);
		self::setHeader($response, 'X-K4T-Docs-Source', $source);
		self::setHeader($response, 'X-K4T-Docs-Gen-Time', sprintf('%.2fms', $generationTimeMs));
		self::setHeader($response, 'X-K4T-Docs-Cache-Reset', $cacheResetStatus);
	}

	/**
	 * @param HttpRequest $request
	 *
	 * @return bool
	 */
	private static function isCacheResetRequested(HttpRequest $request): bool
	{
		$value = self::extractQueryParam($request, 'cache_reset');
		if ($value === null) {
			return false;
		}

		$value = strtolower(trim($value));

		return in_array($value, ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * @param HttpRequest $request
	 * @param string      $name
	 *
	 * @return string|null
	 */
	private static function extractQueryParam(HttpRequest $request, string $name): ?string
	{
		$query = parse_url(self::extractRequestUri($request), PHP_URL_QUERY);
		if (!is_string($query) || $query === '') {
			return null;
		}

		$params = [];
		parse_str($query, $params);
		if (!array_key_exists($name, $params)) {
			return null;
		}

		$value = $params[$name];
		if (is_scalar($value)) {
			$normalized = trim((string)$value);

			return $normalized === '' ? null : $normalized;
		}

		return null;
	}

	/**
	 * @param HttpRequest $request
	 *
	 * @return string
	 */
	private static function extractRequestUri(HttpRequest $request): string
	{
		$requestUri = '';
		if (method_exists($request, 'getRequestUri')) {
			$requestUri = (string)$request->getRequestUri();
		}

		if ($requestUri === '') {
			$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/docs/');
		}

		return $requestUri;
	}

	/**
	 * @param HttpRequest $request
	 *
	 * @return array<string, mixed>
	 */
	private static function buildRequestContext(HttpRequest $request): array
	{
		return [
			'request_uri' => self::extractRequestUri($request),
			'http_host'   => (string)$request->getHttpHost(),
			'client_ip'   => (string)$request->getRemoteAddress(),
		];
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
	 * @param object $response
	 * @param int    $statusCode
	 *
	 * @return void
	 */
	private static function setResponseStatus(object $response, int $statusCode): void
	{
		if (method_exists($response, 'setStatusCode')) {
			$response->setStatusCode($statusCode);

			return;
		}

		if (method_exists($response, 'setStatus')) {
			$response->setStatus(self::mapStatusCodeToStatusText($statusCode));

			return;
		}

		http_response_code($statusCode);
	}

	/**
	 * @param int $statusCode
	 *
	 * @return string
	 */
	private static function mapStatusCodeToStatusText(int $statusCode): string
	{
		return match ($statusCode) {
			200 => '200 OK',
			403 => '403 Forbidden',
			404 => '404 Not Found',
			500 => '500 Internal Server Error',
			default => (string)$statusCode,
		};
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
