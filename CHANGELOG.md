# Changelog

All notable changes to this project will be documented in this file.

## [1.2.0] - 2026-04-02

### Added
- Optional cache reset flow via request parameters:
  - `cache_reset=1`
  - `cache_reset_token=<token>` (when token is configured)
- Extended diagnostic headers:
  - `X-K4T-Docs-Cache-Reset`
- Additional tests for cache reset scenarios and settings validation errors.

### Changed
- Error logs now include request context (`request_uri`, `http_host`, `client_ip`).

## [1.1.1] - 2026-04-02

### Changed
- Removed failing `CodeQL` workflow to keep CI checks stable.
- Tuned Dependabot rules to ignore disruptive major updates for selected dependencies.
- Added package archive exclusions (`.gitattributes` and `composer.json archive.exclude`) to keep distribution focused on runtime module files.

## [1.1.0] - 2026-04-02

### Added
- Strict settings normalization and validation (`SwaggerSettings`).
- Access policy service for groups and IP/CIDR rules.
- Managed cache support with explicit cache revision key.
- Diagnostic response headers toggle (`debug_headers_enabled`).
- Unit tests for settings, access policy, docs handler, and cache behavior.
- CI workflow with lint/phpstan/phpunit.
- Self-hosted Scalar bundle loading (no CDN dependency).
- `LICENSE` (MIT).

### Changed
- OpenAPI cache now stores JSON string payload for better portability.
- Cache key includes module version and cache revision for safer invalidation.
- Error handling in HTTP docs endpoint now logs runtime failures.
- README files expanded with setup, config defaults, and troubleshooting notes.

### Fixed
- GitHub repository links and CI badge paths corrected for `bitrix-swagger-docs`.
- `include_dirs` documented default aligned with runtime default (`[]`).
