# Contributing

Thank you for helping to improve Email Marketing Laravel Platform.

## Reporting bugs

Open a GitHub issue and include your PHP version, database, the steps to reproduce, what you
expected and what happened instead. For security problems, follow [SECURITY.md](SECURITY.md)
instead.

## Pull requests

1. Fork the repo and create a branch from `main`.
2. Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). Its six architecture rules (sending
   outside Filament, driver registries, tenant scoping, roles, queues, idempotent sends) are
   not negotiable.
3. Add or update Pest tests. Every feature needs at least one feature test.
4. Run `php artisan test` and `vendor/bin/pint` before you push.
5. Keep each pull request to one change, and explain the reason for it in the description.

## Code style

- PHP 8.3, `declare(strict_types=1)`, formatted with Laravel Pint.
- All user-facing strings go through `__()`.
- Money is stored as integer minor units, and timestamps as UTC.

By contributing, you agree that your contribution is licensed under the MIT License.
