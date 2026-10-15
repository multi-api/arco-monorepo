# Publishing a runtime build

Runtime builds are immutable mirrors of the official FrankenPHP `linux-aarch64` binary, published as releases of `multi-api/arco-runtime` and listed in `packages/arco/resources/runtime-builds.php`.

## Steps

1. **Dry run.** In `arco-runtime`, run the `Publish runtime build` workflow with `frankenphp_version=<x.y.z>`, `revision=<n>`, `publish=false`. Only the read-only `verify` job runs.
2. **Inspect the artifact.** Download the `frankenphp-<id>` workflow artifact. Check `build.json` (id, PHP version, extensions, sha256, size, `origin.asset_digest`) and `SHA256SUMS`.
3. **Publish.** Re-run with `publish=true`. The `publish` job re-verifies the upstream attestation and cross-checks `build.json` before attesting and creating the release.
4. **Verify the release.** Confirm the release `frankenphp-<id>` is immutable (`gh api repos/multi-api/arco-runtime/releases/tags/frankenphp-<id> --jq .immutable`) and that the attestation verifies:
   `gh attestation verify frankenphp-linux-aarch64 --repo multi-api/arco-runtime --signer-workflow multi-api/arco-runtime/.github/workflows/publish.yml`
5. **Add it to the manifest.** In this repo: `bin/runtime-manifest-add <id> [--default]`. It fetches `build.json` and the release metadata and refuses unless the release is immutable and the asset size and digest match `build.json`. `--from-file=build.json` skips the release cross-check; use it only for offline work.
6. **Test.** Run `composer test` and `vendor/bin/phpstan`, plus the integration test (`vendor/bin/phpunit tests/Integration`).
7. **Release Arco** with the updated manifest.

Never delete or replace a published build; publish a new revision instead.

## Release notes items

- `arco info --json`: the key describing the runtime build is now `runtime.build` (renamed).
- The old cache directory `~/.arco/cache/frankenphp` is orphaned and can be deleted.
- Stale `*.part` files in `~/.arco/cache/runtime/` can be deleted.

## Future hardening (not done)

Run the `publish` job in a protected GitHub environment with required reviewers, so a release needs explicit approval.
