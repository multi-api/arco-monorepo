# Arco monorepo

Development repository of [Arco](packages/arco/README.md), the bridge between PHP and AWS Lambda
Web Functions. Arco deploys Laravel and PHP to AWS
Lambda Web Functions with FrankenPHP.

| Package | Directory | Published as |
|---|---|---|
| CLI | [`packages/arco`](packages/arco) | [`multiapi/arco`](https://github.com/multi-api/arco) |
| Laravel integration | [`packages/arco-laravel`](packages/arco-laravel) | [`multiapi/arco-laravel`](https://github.com/multi-api/arco-laravel) |
| Secret references | [`packages/arco-secrets`](packages/arco-secrets) | [`multiapi/arco-secrets`](https://github.com/multi-api/arco-secrets) |

User documentation lives with the CLI: [README](packages/arco/README.md),
[getting started](packages/arco/docs/getting-started.md),
[configuration](packages/arco/docs/configuration.md), [changelog](packages/arco/CHANGELOG.md).

## Development

```bash
composer install
vendor/bin/phpunit tests/Unit          # unit tests
vendor/bin/phpstan analyse --memory-limit=4G
vendor/bin/phpunit tests/Integration   # Docker simulation of Lambda (needs Docker)
```

Releases: pushing a tag `vX.Y.Z` splits each package into its repository
(`.github/workflows/split.yml`). Runtime binaries: see
[docs/maintainers/runtime-builds.md](docs/maintainers/runtime-builds.md).

## License

MIT
