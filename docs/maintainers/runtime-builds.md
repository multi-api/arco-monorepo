# Publishing a runtime build

Runtime builds are immutable mirrors of the official FrankenPHP `linux-aarch64` binary, published as releases of `multi-api/arco-runtime` and listed in `packages/arco/resources/runtime-builds.php`.

A build also publishes a static PHP CLI (`php-linux-aarch64`) with the same PHP version and extensions as the FrankenPHP binary. The console function carries it as `bin/php`, so it does not download FrankenPHP at cold start. Builds without it (such as `1.13.0-r1`) still work: the console falls back to the download.

## Steps

1. **Dry run.** In `arco-runtime`, run the `Publish runtime build` workflow with `frankenphp_version=<x.y.z>`, `revision=<n>`, `spc_tag=<static-php-cli release tag>`, `extensions=<list>`, `publish=false`. The `build-php` and `verify` jobs run; `publish` does not.
   - `spc_tag`: a static-php-cli release (2.8.5 for `1.13.0-r2`). Its release binary is checked against the digest GitHub reports before it runs.
   - `extensions`: copy `defaultExtensions` from FrankenPHP's `build-static.sh` at the tag `v<x.y.z>`. The parity check below fails if the list drifts from the FrankenPHP binary.
2. **Inspect the artifact.** Download the `frankenphp-<id>` workflow artifact. Check `build.json` (id, PHP version, extensions, sha256, size, `origin.asset_digest`, and `php_cli` with `sha256`, `size`, `spc` and `extensions`) and `SHA256SUMS`.
3. **Publish.** Re-run with `publish=true`. The `publish` job re-verifies the upstream attestation and cross-checks `build.json` before attesting both binaries and creating the release.
4. **Verify the release.** Confirm the release `frankenphp-<id>` is immutable (`gh api repos/multi-api/arco-runtime/releases/tags/frankenphp-<id> --jq .immutable`) and that both attestations verify:
   `gh attestation verify frankenphp-linux-aarch64 --repo multi-api/arco-runtime --signer-workflow multi-api/arco-runtime/.github/workflows/publish.yml`
   `gh attestation verify php-linux-aarch64 --repo multi-api/arco-runtime --signer-workflow multi-api/arco-runtime/.github/workflows/publish.yml`
5. **Add it to the manifest.** In this repo: `bin/runtime-manifest-add <id> [--default]`. It fetches `build.json` and the release metadata and refuses unless the release is immutable and the asset size and digest match `build.json`. `--from-file=build.json` skips the release cross-check; use it only for offline work.
6. **Test.** Run `composer test` and `vendor/bin/phpstan`, plus the integration test (`vendor/bin/phpunit tests/Integration`).
7. **Release Arco** with the updated manifest.

## The PHP CLI build

Three jobs: `build-php` compiles the CLI (static-php-cli, musl, ZTS, `--disable-opcache-jit`, `spc build --build-cli`), `verify` checks it and the FrankenPHP asset, `publish` attests and releases both.

- **Parity check** (before anything is published): `php -v` reports the same PHP version as the FrankenPHP binary; the CLI's loaded extensions equal FrankenPHP's `get_loaded_extensions()` minus `frankenphp-cli`; and the binary runs in `public.ecr.aws/lambda/provided:al2023`. The 1.13.0-r2 build has PHP 8.5.11 and 77 extensions.
- **Size gate:** the `verify` job's "PHP CLI size gate" step fails the run when `php-linux-aarch64` is above 100 MiB (104,857,600 bytes; 1.13.0-r2: 95,099,864 bytes, about 91 MiB), so the dry run fails, no artifact is produced and `publish` cannot run. Raising the gate means editing the workflow, and only after the owner approves. The CLI takes that space out of the console function's 250 MB.
- **Not reproducible:** builds are not bit-reproducible, so the PHP CLI hash differs between runs of the same inputs. Never compare hashes across runs; the manifest pins the hash of the published asset.
- **Upstream re-uploads:** FrankenPHP has re-uploaded release assets under the same tag (2026-10-14 and 2026-10-16). Each Arco build pins its own sha256 and `origin.asset_digest`, so a re-upload never changes a published build; it only means a new revision must copy the new digest.

Never delete or replace a published build; publish a new revision instead.

## Release notes items

- `arco info --json`: the key describing the runtime build is now `runtime.build` (renamed).
- The old cache directory `~/.arco/cache/frankenphp` is orphaned and can be deleted.
- `arco deploy` and `arco deploy --package` fetch the PHP CLI from GitHub releases once per runtime build and machine, and cache it in `~/.arco/cache/runtime/<build>/php`. If GitHub cannot be reached, the deploy goes on and that console downloads FrankenPHP at cold start (warning `php_cli_unavailable`); a size or checksum mismatch stops the deploy before any AWS change.
- Stale `*.part` files in `~/.arco/cache/runtime/` can be deleted.

## Future hardening (not done)

Run the `publish` job in a protected GitHub environment with required reviewers, so a release needs explicit approval.
