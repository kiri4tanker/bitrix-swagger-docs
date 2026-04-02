<?php

namespace Bitrix\Main {
	class HttpRequest
	{
		public function getRequestUri(): string {}

		public function isHttps(): bool {}

		public function getHttpHost(): string {}

		public function getRemoteAddress(): ?string {}
	}

	class HttpResponse
	{
		public function addHeader(string $name, string $value): self {}

		public function setHeader(string $name, string $value): self {}

		public function setContent(string $content): self {}

		public function setStatus(string $status): self {}
	}

	class Loader
	{
		public static function getLocal(string $path): string {}
	}

	class ModuleManager
	{
		public static function getInstalledModules(): array {}
	}

	class Application
	{
		public static function getInstance(): self {}

		public function getManagedCache(): object {}
	}

	class ManagedCacheMock
	{
		public function read(int $ttl, string $key, string $table = ''): bool {}

		public function get(string $key): mixed {}

		public function set(string $key, mixed $value): void {}

		public function clean(string $key, string $table = ''): bool {}

		public function clear(): void {}
	}
}

namespace Bitrix\Main\Diag {
	class Debug
	{
		public static function writeToFile(mixed $var, string $text = '', string $file = ''): void {}
	}
}

namespace Bitrix\Main\Config {
	class Configuration
	{
		public static function getInstance(string $moduleId): self {}

		public function get(string $key): mixed {}
	}
}

namespace Bitrix\Main\Engine\Response {
	class Json
	{
		public function __construct(mixed $data = null, int $status = 200) {}
	}
}
