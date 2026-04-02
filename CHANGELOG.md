# Changelog

All notable changes to this project will be documented in this file.

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
