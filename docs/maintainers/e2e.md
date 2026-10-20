# End-to-end suite against Floci

`composer test:e2e` (`bin/test-docker e2e`) deploys `examples/laravel` (environment `floci`) to a throwaway local [Floci](https://github.com/floci-io/floci), an AWS emulator that runs Lambda Web Functions, and checks every feature through the real `arco` CLI. It needs Docker and nothing from AWS: no account, no profile, no network access to AWS.

## Running it

```sh
composer test:e2e                      # the whole suite (about 3-4 minutes once images are cached)
composer test:e2e -- --filter test_up  # extra PHPUnit arguments pass through
```

Run one E2E at a time. What the runner does:

1. Starts its own Floci, `arco-e2e-floci-<pid>`, from the pinned `FLOCI_IMAGE` with a fresh Docker volume `arco-e2e-floci-data-<pid>`, the host's Docker socket (Floci runs Lambda functions as sibling containers) and:
   - `FLOCI_BASE_URL=http://localhost:4566` (Floci puts this host and port in the domains it hands out),
   - `FLOCI_STORAGE_MODE=persistent`, `FLOCI_STORAGE_PERSISTENT_PATH=/app/data`,
   - `FLOCI_SERVICES_CLOUDFRONT_ALLOWED_PRIVATE_ORIGIN_HOSTS=localhost,*.localhost` (CloudFront may proxy to the local function endpoint),
   - `FLOCI_DOCKER_RESOURCE_NAMESPACE=arco-e2e-floci-<pid>`: Floci labels every container and volume it creates (and any network) `floci_namespace=<namespace>`, which is how the cleanup finds them.
   No port is published: nothing on the host has to be free.
2. Waits up to 120 s for `http://localhost:4566/_localstack/health`.
3. Runs `vendor/bin/phpunit --testsuite e2e` in the test image, inside Floci's network namespace (`--network container:arco-e2e-floci-<pid>`), so `localhost:4566` and `*.localhost:4566` are Floci. The environment is `AWS_ENDPOINT_URL=http://localhost:4566`, keys `test`/`test`, `AWS_REGION=us-east-1`, `AWS_CONFIG_FILE=/dev/null`, `AWS_SHARED_CREDENTIALS_FILE=/dev/null`. The suite refuses to start with anything else (and with `AWS_PROFILE`, `AWS_SESSION_TOKEN` or other credential sources set), so it cannot reach a real AWS account. The Docker socket and `ARCO_E2E_FLOCI_CONTAINER` let a check quote Floci's own log in its failure or skip message.
4. On any exit (success, failure, Ctrl-C) a `trap` removes the PHPUnit container, the Floci container, every container, volume and network labelled `floci_namespace=arco-e2e-floci-<pid>` (Floci names its containers `floci-aws-…` and `floci-lambda-web-…`), and the data volume. The cleanup ignores further Ctrl-C. Other Floci instances on the host (and their function containers) are never touched.

Manual check after changing the runner (interrupt mid-deploy, then nothing named `arco-e2e` may remain):

```sh
perl -e 'setpgrp(0,0); exec @ARGV' bin/test-docker e2e > /tmp/e2e.log 2>&1 &
pid=$!; sleep 90; kill -INT -- -$pid; wait $pid    # exit 130
docker ps -a --format '{{.Names}}' | grep arco-e2e; docker volume ls -q | grep arco-e2e; docker network ls | grep arco-e2e   # all empty
```

An interrupted run skips PHPUnit's end-of-run cleanup, so its app copy stays in `build/tmp/integration/e2e-app-*` (git-ignored); delete it by hand.

The example app is copied without `vendor/` to `build/tmp/integration/e2e-app-<random>` (TempPaths, removed when the run ends) with its path repository pointed at the monorepo's `packages/`; `arco deploy` writes `.arco/` there, not into `examples/laravel`.

## Checks

`setUpBeforeClass` creates the Route 53 zone `arco-e2e.test` in Floci, runs `arco deploy --env=floci --yes --no-interaction` and reads the URL and CloudFront domain from `arco info --env=floci --json`. `tearDownAfterClass` runs `arco remove --env=floci --force` (retained resources go with the container). Each check prints `[e2e] <check> <result> <seconds>` to stderr.

| Check | What it proves |
|---|---|
| deploy | build, code bucket and upload, role, Web Function revision, endpoint, traffic, `/up` verification, resources stack (SQS + DLQ, S3, DynamoDB), console, worker and scheduler functions, ACM certificate, CloudFront distribution, Route 53 records |
| `test_up` | `/up` answers 200 through the function endpoint (first request may pull the runtime image: up to 180 s) |
| `test_custom_domain_through_cloudfront` | `arco info --json` shows a distribution, and Floci's CloudFront answers `/up` for `Host: app.arco-e2e.test` (Floci serves every distribution on its own port and picks it by Host, as the alias record would; `*.cloudfront.net` does not lead to Floci) |
| `test_cache` | `/e2e/cache`: the DynamoDB cache store reads back what it wrote |
| `test_storage` | `/e2e/storage`: the S3 disk writes and reads a file |
| `test_queue_job` | `/e2e/dispatch` then `/e2e/job/{id}` every 5 s for up to 120 s: SQS → worker function ran the job |
| `test_scheduler` | `/e2e/schedule` for up to 150 s: the EventBridge rule ran `schedule:run` on the console function |
| `test_arco_command` | `arco command --env=floci 'about --only=environment'` exits 0 and shows `production` |
| `test_logs` | `arco logs --env=floci --since=10m` exits 0 and prints events |

## Known Floci gaps

A check that cannot pass because Floci lacks or breaks a whole feature is skipped with `Known Floci gap: …` and the observed error. Arco never gets a Floci-only code path; if a check fails because Arco is wrong (it would be wrong on AWS too), fix Arco.

There are currently none. The two former gaps, S3 rejecting the canned ACL `private` that Laravel's S3 disk sends and CloudFront not reaching a Web Function endpoint origin, were fixed upstream (floci-io/floci#5979, #5980) as of `floci/floci:nightly-10192026`, so `test_storage` and `test_custom_domain_through_cloudfront` are mandatory.

## Bumping `FLOCI_IMAGE`

The image is pinned in one place, `FLOCI_IMAGE` in `bin/test-docker`. Lambda Web Functions exist only in Floci's `nightly-*` images for now (release 2.3.0 does not have them).

1. Pick a tag from Docker Hub (`floci/floci`, `nightly-MMDDYYYY`) and `docker pull` it.
2. Set `FLOCI_IMAGE` to it and run `composer test:e2e`.
3. If a Floci gap was skipped and now passes, remove it from this page. If a new check fails, decide whether Floci or Arco is wrong before skipping anything.
4. Commit the bump with the run's summary.

## Upstream issues

Both former gaps were filed and fixed: [floci-io/floci#5979](https://github.com/floci-io/floci/issues/5979) (S3 ACL) and [floci-io/floci#5980](https://github.com/floci-io/floci/issues/5980) (CloudFront origin).

# Real-AWS end-to-end run

`composer test:e2e-aws` (`bin/test-docker e2e-aws`, PHPUnit suite `e2e-aws`, `tests/E2e/AwsE2eTest.php`) deploys `examples/laravel` (environment `e2e`: console, scheduler every minute, queue `default`, storage `files`, cache, Lambda URL only, no custom domain) to the dedicated Arco E2E account and runs the same checks as the Floci suite minus the custom domain. `test_storage` must pass on AWS. It is the release gate (see [releasing.md](releasing.md)); it is manual and is not run in CI.

It is a **dry run unless `ARCO_E2E_DRY_RUN=0`**. Until the dedicated account exists, only the dry run is meaningful, and only once that account's profile is set up.

## Inputs

| Variable | |
|---|---|
| `AWS_PROFILE` | required: a profile in your `~/.aws` for the E2E account. For SSO, run `aws sso login --profile <it>` first (the container mounts `~/.aws` read-only and cannot refresh the token). |
| `ARCO_E2E_ACCOUNT` | required: the 12-digit id of the dedicated E2E account. |
| `ARCO_E2E_DRY_RUN` | `1` (default) or `0` for a real run. |

```sh
AWS_PROFILE=arco-e2e ARCO_E2E_ACCOUNT=123456789012 composer test:e2e-aws                       # dry run
AWS_PROFILE=arco-e2e ARCO_E2E_ACCOUNT=123456789012 ARCO_E2E_DRY_RUN=0 composer test:e2e-aws    # real run
```

The container gets a network, the repository at its host path, the `arco-test-cache` volume, a throwaway `HOME` (tmpfs) with your `~/.aws` mounted read-only at `HOME/.aws`, and from your shell exactly `AWS_PROFILE`, `ARCO_E2E_ACCOUNT` and `ARCO_E2E_DRY_RUN`. It never gets `AWS_ENDPOINT_URL` or access keys from your shell.

## Safety

- **Guard first.** Before any other AWS call, `AwsE2eCleanup::connect()` refuses when any `AWS_ENDPOINT_URL*` variable is set, when `ARCO_E2E_ACCOUNT` is not 12 digits, and when `sts:GetCallerIdentity` for the profile returns any other account (for example a production profile left active). `examples/laravel/arco.php` also locks the `e2e` environment to `ARCO_E2E_ACCOUNT` (`->account()`), so every `arco` command of the run refuses another account too.
- **One app name per run.** `arco-e2e-<8 hex>`, passed to `arco.php` as `ARCO_E2E_APP`; the APP_KEY reference follows it (`arco-ssm:/<app>/e2e/app-key`). The cleanup refuses any other app name, so it can never be pointed at `arco-example` or a real app.
- **Tag-scoped cleanup.** `arco remove` keeps queues, buckets and tables by design. `AwsE2eCleanup` finds candidates by name prefix `<app>-e2e-` (`s3:ListBuckets`, `dynamodb:ListTables`, `sqs:ListQueues`) and by tag (`tag:GetResources` with `arco:app=<app>` and `arco:environment=e2e`), and deletes one only when its name has that prefix **and** its own service reports both tags. A resource with the right name but without the tags, or tagged but named otherwise, is reported (`[cleanup] leaves alone …`) and left alone. Name and tags are checked again right before each delete. Buckets are emptied first (every version and delete marker).
- **Log groups.** A real run leaves the run's CloudWatch log groups behind (`arco remove` keeps logs). The cleanup lists `/aws/lambda/<app>-e2e*` and `/aws/lambda/web/<app>-e2e*` (`logs:DescribeLogGroups`) and deletes a group only when its name is exactly one the run's functions write to (`<app>` holds 8 random hex digits, so no other run's or real app's group can match) **and** its tags (`logs:ListTagsForResource`) do not contradict the run: both `arco:app=<app>` and `arco:environment=e2e`, or neither. Neither is accepted because Lambda creates a group untagged on its first invoke and Arco tags it afterwards only when the profile may (`logs:TagResource`); a group tagged for another app or environment is reported and left alone. Name and tags are checked again right before each delete, and each delete is reported. The dry run lists them.
- **The APP_KEY parameter** `/<app>/e2e/app-key` is the one exception: the run creates it (tagged `arco:app`, `arco:environment`) and the cleanup deletes it by that exact name.

## What a run does

Dry run (`ARCO_E2E_DRY_RUN=1`): guard, `arco package --env=e2e`, then prints the resources a real run would create (from the resolved config) and what the cleanup finds for the new app name (normally nothing). Only read calls reach AWS (`sts:GetCallerIdentity`, `List*`, `Get*`, `Describe*`, `tag:GetResources`). The checks are reported as skipped.

Real run (`ARCO_E2E_DRY_RUN=0`):

1. Guard; create the SecureString parameter `/<app>/e2e/app-key`.
2. `arco deploy --env=e2e --yes`, `arco info --env=e2e --json` for the URL.
3. Checks: `test_up`, `test_cache`, `test_storage`, `test_queue_job`, `test_scheduler`, `test_arco_command`, `test_logs` (as in the Floci table above).
4. `arco remove --env=e2e --yes`, then the cleanup prints its plan, what it leaves alone and each delete. A failed remove or a failed delete fails the run and names what is left.

Step 4 also runs when the deploy fails. If the run is killed (Ctrl-C), nothing cleans up: the app name is printed at the start (`[e2e-aws] … app arco-e2e-…`); run `AWS_PROFILE=<profile> ARCO_E2E_APP=<it> ARCO_E2E_ACCOUNT=<id> vendor/bin/arco remove --env=e2e --yes` from a copy of `examples/laravel`, then delete the retained `<app>-e2e-*` queues, bucket and table and the parameter by hand.

## Permissions of the E2E profile

Besides what `arco deploy` and `arco remove` need ([getting started: minimal IAM permissions](../../packages/arco/docs/getting-started.md#minimal-iam-permissions), with SSM scoped to `parameter/arco-e2e-*`): `ssm:PutParameter`, `ssm:AddTagsToResource`, `ssm:DescribeParameters`, `ssm:DeleteParameter`; `s3:ListAllMyBuckets`, `s3:GetBucketTagging`, `s3:ListBucketVersions`, `s3:DeleteObjectVersion`, `s3:DeleteBucket`; `dynamodb:ListTables`, `dynamodb:DescribeTable`, `dynamodb:ListTagsOfResource`, `dynamodb:DeleteTable`; `sqs:ListQueues`, `sqs:GetQueueUrl`, `sqs:ListQueueTags`, `sqs:DeleteQueue`; `logs:DescribeLogGroups`, `logs:ListTagsForResource`, `logs:DeleteLogGroup`; `tag:GetResources`.

The cleanup itself is covered by `tests/Unit/E2e/AwsE2eCleanupTest.php` against an in-memory account (`FakeAwsAccount`): no network, no profile.
