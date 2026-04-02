<?php

namespace Bitrix\Main {
	if (!class_exists(HttpRequest::class)) {
		class HttpRequest
		{
			public function __construct(
				private readonly bool $https = false,
				private readonly string $host = 'localhost',
				private readonly ?string $remoteAddress = '127.0.0.1',
				private readonly string $requestUri = '/docs/'
			) {
			}

			public function getRequestUri(): string
			{
				return $this->requestUri;
			}

			public function isHttps(): bool
			{
				return $this->https;
			}

			public function getHttpHost(): string
			{
				return $this->host;
			}

			public function getRemoteAddress(): ?string
			{
				return $this->remoteAddress;
			}
		}
	}

	if (!class_exists(HttpResponse::class)) {
		class HttpResponse
		{
			public string $content = '';
			public string $status = '200 OK';
			/** @var array<string, string> */
			public array $headers = [];

			public function addHeader(string $name, string $value): self
			{
				$this->headers[$name] = $value;

				return $this;
			}

			public function setHeader(string $name, string $value): self
			{
				$this->headers[$name] = $value;

				return $this;
			}

			public function setContent(string $content): self
			{
				$this->content = $content;

				return $this;
			}

			public function setStatus(string $status): self
			{
				$this->status = $status;

				return $this;
			}
		}
	}

	if (!class_exists(Loader::class)) {
		class Loader
		{
			private static string $modulesPath = '/tmp/modules';

			public static function setModulesPath(string $path): void
			{
				self::$modulesPath = rtrim($path, '/');
			}

			public static function getLocal(string $path): string
			{
				if ($path === 'modules') {
					return self::$modulesPath;
				}

				return self::$modulesPath . '/' . ltrim($path, '/');
			}
		}
	}

	if (!class_exists(ModuleManager::class)) {
		class ModuleManager
		{
			/** @var array<string, mixed> */
			private static array $installedModules = [];

			/** @param array<string, mixed> $modules */
			public static function setInstalledModules(array $modules): void
			{
				self::$installedModules = $modules;
			}

			/** @return array<string, mixed> */
			public static function getInstalledModules(): array
			{
				return self::$installedModules;
			}
		}
	}

	if (!class_exists(Application::class)) {
		class Application
		{
			private static ?self $instance = null;
			private object $managedCache;

			private function __construct()
			{
				$this->managedCache = new ManagedCacheMock();
			}

			public static function getInstance(): self
			{
				self::$instance ??= new self();

				return self::$instance;
			}

			public function setManagedCache(object $cache): void
			{
				$this->managedCache = $cache;
			}

			public function getManagedCache(): object
			{
				return $this->managedCache;
			}
		}

		class ManagedCacheMock
		{
			/** @var array<string, array{created_at:int, value:mixed}> */
			private array $store = [];

			public function read(int $ttl, string $key, string $table = ''): bool
			{
				if (!isset($this->store[$key])) {
					return false;
				}

				if ($ttl > 0 && time() - $this->store[$key]['created_at'] > $ttl) {
					unset($this->store[$key]);

					return false;
				}

				return true;
			}

			public function get(string $key): mixed
			{
				return $this->store[$key]['value'] ?? null;
			}

			public function set(string $key, mixed $value): void
			{
				$this->store[$key] = [
					'created_at' => time(),
					'value'      => $value,
				];
			}

			public function clear(): void
			{
				$this->store = [];
			}
		}
	}
}

namespace Bitrix\Main\Config {
	if (!class_exists(Configuration::class)) {
		class Configuration
		{
			/** @var array<string, self> */
			private static array $instances = [];

			/** @var array<string, mixed> */
			private array $values = [];

			public static function getInstance(string $moduleId): self
			{
				self::$instances[$moduleId] ??= new self();

				return self::$instances[$moduleId];
			}

			public static function setValue(string $moduleId, string $key, mixed $value): void
			{
				self::getInstance($moduleId)->set($key, $value);
			}

			public static function resetAll(): void
			{
				self::$instances = [];
			}

			public function set(string $key, mixed $value): void
			{
				$this->values[$key] = $value;
			}

			public function get(string $key): mixed
			{
				return $this->values[$key] ?? null;
			}
		}
	}
}

namespace Bitrix\Main\Engine\Response {
	if (!class_exists(Json::class)) {
		class Json extends \Bitrix\Main\HttpResponse
		{
			public function __construct(public mixed $data = null, public int $statusCode = 200)
			{
				$this->status = (string)$statusCode;
			}
		}
	}
}

namespace Bitrix\Main\Diag {
	if (!class_exists(Debug::class)) {
		class Debug
		{
			/** @var list<array{payload:mixed, text:string, file:string}> */
			public static array $logs = [];

			public static function writeToFile(mixed $var, string $text = '', string $file = ''): void
			{
				self::$logs[] = [
					'payload' => $var,
					'text'    => $text,
					'file'    => $file,
				];
			}

			public static function reset(): void
			{
				self::$logs = [];
			}
		}
	}
}
