# Releasing Arco

1. `composer test:docker` (unit, runtime, PHPStan) and `composer test:integration` pass.
2. `composer test:e2e` (Floci, [e2e.md](e2e.md)) passes; CI runs it too.
3. **Real AWS:** `AWS_PROFILE=<e2e profile> ARCO_E2E_ACCOUNT=<id> ARCO_E2E_DRY_RUN=0 composer test:e2e-aws` passes against the dedicated E2E account ([e2e.md: real-AWS run](e2e.md#real-aws-end-to-end-run)). Check its output: the cleanup deleted every `<app>-e2e-*` queue, bucket and table and the APP_KEY parameter, and left nothing unexpected alone. Until that account exists, run the dry run (default) once its profile is set up, and note in the release that the real-AWS run was not done.
4. `packages/arco/CHANGELOG.md` has the version's section.
5. Runtime builds, if the release ships a new one: [runtime-builds.md](runtime-builds.md).
6. Push the tag `vX.Y.Z`: `.github/workflows/split.yml` splits each package into its repository.
