# Contributing

## Development Setup

1. Clone repository.
2. Install dependencies:

```bash
composer install
```

## Local Checks

Run all checks before push:

```bash
composer lint
composer stan
composer test
```

## Coding Rules

- Keep module runtime code in lower-case directories/files style used by this module.
- Do not commit generated or local environment files.
- Keep public behavior backward compatible unless change is intentional and documented.

## Pull Requests

PR should include:
- short problem statement
- what changed
- how it was tested
- migration notes (if any)

If configuration defaults changed, update both:
- `readme.md`
- `README.en.md`
