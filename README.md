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
[configuration](packages/arco/docs/configuration.md), [try it locally with Floci](packages/arco/docs/local-floci.md),
[changelog](packages/arco/CHANGELOG.md).

## Development

```bash
composer install
composer test:docker                   # unit tests, runtime tests and PHPStan, in a container
composer test:docker:unit -- --filter BootstrapTest   # one test, in the container
composer test:integration              # Docker simulation of Lambda, in a container (needs Docker)
```

Run the unit tests, the runtime tests and PHPStan with `composer test:docker`, not on your
machine. Many tests create and delete temporary files, and a test bug once deleted files from a
developer's real temporary directory. The container ([`docker/test`](docker/test/Dockerfile))
sees only the repository, at `/app`: no home directory, no host `/tmp`, no network. A bad
test can only damage the container. The image is built on first use and rebuilt when its
Dockerfile changes.

`composer test:integration` runs the integration suite in the same image. Those tests start
Lambda containers, so this container also gets a network and the host's Docker socket, and it
sees the repository at its host path (the temp files the Lambda containers mount live in the
git-ignored `build/tmp/integration`). Still no home directory and no host `/tmp`. Note that
access to the Docker socket is root-equivalent on the host: a test could start a container that
mounts any host path. Pass PHPUnit arguments after `--`, e.g.
`composer test:integration -- --filter WorkerRuntimeTest`.

Releases: see [docs/maintainers/releasing.md](docs/maintainers/releasing.md) (pushing a tag
`vX.Y.Z` splits each package into its repository, `.github/workflows/split.yml`). Runtime binaries: see
[docs/maintainers/runtime-builds.md](docs/maintainers/runtime-builds.md).

## License

MIT
