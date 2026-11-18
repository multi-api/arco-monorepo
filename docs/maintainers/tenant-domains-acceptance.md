# Tenant domains: acceptance run on real AWS

**This runbook needs the maintainer's explicit authorization before anyone runs it.** It creates real CloudFront, Route 53, ACM-managed-certificate, Lambda and IAM resources that cost money and, for the customer domain steps, uses DNS names you control. Nobody runs it as part of a routine release. Until it has been done and the results table below is filled in, `tenantDomains()` stays documented as experimental ([configuration.md](../../packages/arco/docs/configuration.md#tenant-domain-routing)).

What the Floci suite cannot show, and why, is in [e2e.md: Tenant domains](e2e.md#tenant-domains). The unit and contract tests use fakes, so every claim below that depends on what CloudFront answers is unverified until this run.

Run it only in the dedicated Arco E2E account (see [e2e.md: real-AWS run](e2e.md#real-aws-end-to-end-run)), with the same guards: `ARCO_E2E_ACCOUNT` set so every `arco` command refuses another account, never a production profile. Use a throwaway app name (`arco-tenants-<8 hex>`) and delete everything at the end (step 17).

## What you need

- A copy of `examples/laravel` with an `arco.php` environment `acceptance` (the app needs `multiapi/arco-laravel` and `aws/aws-sdk-php`, which the example has), `console()`, and the variables of the `e2e` environment. Set `->account('<id>')`, `->domain(['app.<zone>'])` and `->tenantDomains('routing.<zone>')`. A routing hostname and an app domain in a Route 53 public hosted zone of the account (`<zone>`), and a second DNS zone you control outside Route 53 for customer names (for example a Cloudflare zone, DNS only), plus one more name in a third place if you can (step 6).
- A route in the copy's app for the checks of step 5 (a tiny controller that returns `$_SERVER` headers, `$request->getHost()`, `$request->query('q')` and a file under `public/build/`) and one for the API calls of step 8 (a command or route that calls `TenantDomains::add/status/list/remove` and prints the exception class and `errorCode`). Do not commit them.
- The profile's IAM, below.
- A shell with `aws`, `dig` and `curl`.

### IAM of the acceptance profile

Everything in [getting started: minimal IAM permissions](../../packages/arco/docs/getting-started.md#minimal-iam-permissions) including the custom domain and tenant domain sections (`cloudfront:*DistributionTenant*`, `cloudfront:VerifyDnsConfiguration`, the connection group actions, `lambda:*FunctionConcurrency` and `lambda:DeleteFunction` on `<app>-*-tenants`, IAM on `arco-<app>-*-tenants`, `cloudformation:*Stack*` and change sets on `arco-*`). Besides it, for the checks themselves:

- `cloudfront:ListDistributionTenants`, `GetDistributionTenant`, `GetManagedCertificateDetails`, `ListConnectionGroups`, `GetConnectionGroup`, `GetDistribution` (read, to record what was created);
- `lambda:InvokeFunction` on `<app>-*-tenants` and `lambda:GetFunctionConcurrency`, `lambda:PutFunctionConcurrency`, `lambda:DeleteFunctionConcurrency` (step 14 restores the mediator by hand);
- `route53:ChangeResourceRecordSets`, `ListResourceRecordSets`, `ListHostedZones*`, `GetChange` on the zone;
- `logs:FilterLogEvents`, `logs:DescribeLogGroups`, `logs:DeleteLogGroup` for the app's and the mediator's groups;
- `iam:PutRolePermissionsBoundary` on `role/arco-*` (step 15) and `iam:CreatePolicy`, `iam:DeletePolicy` if you use your own boundary policy;
- `sts:GetCallerIdentity`, `ec2:Describe*` and `ec2:*Subnet*`-free reads for step 16 if the profile creates its own VPC (a NAT-less VPC with private subnets and the Arco endpoints).

## Costs to expect

Small, but not zero, and some are per month whatever you do:

- CloudFront charges per distribution tenant (and for requests and data transfer through them): [CloudFront pricing](https://aws.amazon.com/cloudfront/pricing/). Delete tenants you create. Check the page for the current free allowance and per-tenant price; do not assume them.
- Account quotas on distribution tenants, connection groups and multi-tenant distributions: [CloudFront quotas](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/cloudfront-limits.html). A new account may have low defaults; record the values you saw (step 9).
- Route 53 charges per hosted zone per month and per million queries ([Route 53 pricing](https://aws.amazon.com/route53/pricing/)); the target's `CNAME` is queried by every customer lookup.
- Lambda invocations of the mediator and the app, CloudWatch Logs, the Web Function: negligible at this scale.
- Anycast static IPs are not used by this version, so there is no per-IP-hour charge to expect.

## Steps

Record for each step: the command, the expected observation, the actual result, the date and the CloudFront/Lambda/CloudFormation request ids when something fails. Use the results table at the end.

### Deploy

1. **First deploy, Route 53 target.** `vendor/bin/arco deploy --env=acceptance --yes`. Expect: the `domain()` steps, then `domains.step_tenant_distribution`, `domains.step_tenant_dns` and `deploy.step_tenant_mediator`, exit 0. Check: the stack `arco-<app>-acceptance-tenants` is `CREATE_COMPLETE` with a distribution (`ConnectionMode: tenant-only`) and a connection group; `aws route53 list-resource-record-sets` shows a `CNAME` `routing.<zone>` to the group's routing endpoint (the stack output); `aws lambda get-function-configuration` shows `<app>-acceptance-tenants` with the tags `arco:app`, `arco:environment` and the role `arco-<app>-acceptance-tenants`; `arco info --json` has `tenantDomains` with `tenants: 0`. Record the deploy time, and whether the stack needed more than one attempt.
2. **Redeploy without changes.** Run the same deploy again. Expect: no stack update (no change set executed; the stack's `LastUpdatedTime` is unchanged), no tenant touched, mediator code unchanged (`CodeSha256` the same), exit 0. Record `aws cloudformation describe-stack-events` for the second run: it must be empty.
3. **Refused changes.** (a) Change the target in `arco.php` and deploy: expect `domains.tenant_target_changed` before any change. (b) Add a template change that replaces the distribution (for example change the connection group's `Name` in a scratch copy of the stack, or run the deploy with the stack edited by hand) and deploy: expect `domains.stack_replacement`, with the change set deleted and the stack untouched. Restore `arco.php` afterwards. Record the error text and that the stack and the DNS record are unchanged.

### Managed certificate and routing

4. **Managed certificate over HTTP.** In the external zone, create `CNAME shop.<customer-zone>` to `routing.<zone>` (DNS only if Cloudflare). Call `TenantDomains::add('shop.<customer-zone>')` (step 8) and poll `status` every 30 s. Expect `pending` then `active` without any validation record from you (CloudFront validates over HTTP through the `CNAME`). Record the time to `active`, `aws cloudfront get-managed-certificate-details` for the tenant (status values seen, in order), and any `reason` text while `failed`. Repeat for: (a) a **CNAME chain** (`a.<customer-zone>` to `b.<customer-zone>` to the target; Arco's check follows up to 8 hops): expect `add` to pass its DNS check and the certificate to issue, or record exactly where it does not; (b) a **flattened record** (the customer's DNS provider returns `A` records instead of the `CNAME`, as some do for names they flatten): expect `add` to pass only when every address is in the routing endpoint's addresses, and record whether the certificate issues; (c) a name with **no record yet**: expect `TenantDomainDnsIncorrect` and no tenant created (`aws cloudfront list-distribution-tenants` unchanged).
5. **Requests through the tenant distribution.** With an `active` customer domain, `curl -sS -D- https://shop.<customer-zone>/<probe route>` and check, each one recorded:
   - the request reaches the app (HTTP 200 from the probe) over SigV4/origin access control, with the `Host` rewritten by the viewer-request function;
   - `X-Forwarded-Host` is the customer domain, and `$request->getHost()` agrees with it;
   - the client address arrives in `X-Forwarded-For` (the tenant distribution forwards requests exactly as `domain()` does; `CloudFront-Viewer-Address` is not forwarded);
   - a `+` in the query string (`?q=a+b`) arrives as `a b` exactly as it does on the `domain()` distribution; also `%2B` and `%20`;
   - `/build/<file>` is cached per host: two requests to two customer domains each show `X-Cache: Miss from cloudfront` first, then `Hit`, and a `Hit` on one domain never serves the other's response (use a probe file whose body includes the host);
   - the endpoint's direct URL (the Function URL in `arco info`) answers 403 to an unsigned request, and `curl -H 'Host: shop.<customer-zone>' <direct URL>` also 403.
   Record the headers received. If any of these differs between the tenant distribution and the `domain()` distribution, record both.

### Laravel API

6. **Cloudflare DNS-only target and manual DNS target.** In a second environment (or the same one after step 17 and a redeploy): (a) `dns('cloudflare')` with a Cloudflare zone: expect the target `CNAME` created DNS only with the comment `managed by arco: <app>/<env>`; set the proxy on by hand and deploy again: expect `domains.tenant_target_proxied` and no change. (b) `dns(false)`: the deploy prints `<target> CNAME <endpoint>` and stops with `domains.tenant_manual_cname_missing`; create the record, deploy again: expect it to resume at the check (the stack and policy statement kept) and succeed. Record how long public DNS took to show it.
7. **Not-yet-deployed and unavailable cases.** From the app of a copy whose `role()` lacks `lambda:InvokeFunction` (step 15b sets up the role), and from an app with `ARCO` unset: expect `TenantDomainsUnavailable` in both.
8. **API calls.** Run each through the probe route or a console command and record the class, `errorCode`, message and time:
   - `add` of a ready domain: `pending`, then `status` until `active` (step 4);
   - `add` again for the same domain: same state, no second tenant, no DNS check;
   - `list(limit: 2)` with at least 3 tenants: follow the cursor until `null`; no duplicates and none missing when nothing changes concurrently; an empty page that carries a cursor, if you can provoke one by tagging a foreign tenant by hand in the same distribution (then delete it);
   - `add` of a domain that **another distribution** already holds as an alias or tenant (create a tenant for it on a scratch distribution of your own, or use a domain already on one): record which CloudFront error name comes back (the mediator treats the names in `DUPLICATE` in `packages/arco/runtime/lib/tenants/mediator.js` as a duplicate and answers `domain_taken` when the tenant is not this environment's; any other name comes back as `failed`, so a real name missing from that set is a bug to fix) and whether `TenantDomainTaken` is thrown or `TenantDomainException` with `failed`;
   - the **quota** error: read the account's quota first (step 9). If it is cheap to reach, create tenants until the limit and record the error name (the code maps `EntityLimitExceeded` to `quota`); if it is not, record that it was not reached;
   - `remove`: right after `add`, record whether `TenantDomainRemovalPending` is thrown or the call succeeds, how long the tenant takes to become deletable after disable, the error name returned while it is not (the code treats `ResourceNotDisabled` and `PreconditionFailed` as pending/conflict: record which), and the time until `list` no longer shows it. Call `remove` again: expect success (idempotent).
9. **Empty tenant list and quotas.** On the distribution with zero tenants (right after step 1, or after removing them all), call `aws cloudfront list-distribution-tenants --association-filter DistributionId=<id>` and record the exact JSON: **is `DistributionTenantList` present, as an empty list, or absent?** `packages/arco/src/Deploy/TenantInventory.php` treats a missing list as malformed (`remove.tenant_listing_incomplete`, `info` tenants `null`), so if it is absent in the real answer, `arco remove` and `arco info` need a fix before the feature leaves experimental status. Check the same in the mediator's `list` (it must answer an empty page, not `failed`). Record the quotas from Service Quotas / the quotas page for tenants, connection groups and distributions in the account.

### Remove

10. **`arco remove` without `--force`, with customer domains.** With at least one tenant, `vendor/bin/arco remove --env=acceptance --yes`. Expect: the mediator is drained (reserved concurrency 0, read back), a wait, a full count, then a stop with `remove.tenants_need_force`, exit non-zero, **nothing deleted** (tenants, stack, DNS record all still there) and the printed `arco tenants:restore --env=acceptance` command. Verify with `aws lambda get-function-concurrency` (0) and `aws cloudfront list-distribution-tenants`.
11. **Restore.** Run the printed `vendor/bin/arco tenants:restore --env=acceptance` (then again: expect `already_enabled` and no write; with `--json`, expect `"schema": 1` and `outcome` `restored` the first time; set a reserved concurrency of 5 by hand with `aws lambda put-function-concurrency` and run it once more: expect `limit_kept` and the 5 left in place, then delete it). Expect the app's `add` and `status` to work again, and `arco deploy` to pass `deploy.tenant_mediator_draining` (run it once to confirm; it must have refused while the concurrency was 0, so run a deploy between steps 10 and 11 as well and record `deploy.tenant_mediator_draining`).
12. **Drain margin.** `remove` waits the mediator's timeout plus 60 s (90 s) after setting concurrency 0, so running invocations end. Measure it: start a long `add` loop from a second shell (a script invoking the mediator in a loop with `aws lambda invoke`); start `arco remove` without `--force`; record the timestamp `arco remove` logs for `remove.step_tenant_drain`, the timestamp of the first `TooManyRequestsException` (throttling) from the loop and the timestamp of the last successful invocation. Expect throttling from within seconds of the `PutFunctionConcurrency` call, and no successful invocation after it plus the Lambda propagation delay. Record the actual delay: **was the 60 s margin enough, and how much of it was needed?**
13. **`arco remove --force`.** Restore concurrency (step 11), then `vendor/bin/arco remove --env=acceptance --force --yes`. Expect, in order: drain, wait, count, each tenant disabled then deleted (record per tenant the time between disable and the delete that succeeds, and whether the first delete attempt returned `ResourceNotDisabled` or `PreconditionFailed`), final listing empty (record how long deleted tenants still show), the target's `CNAME` removed, the policy statement, the tenant stack, the mediator function and role, then the rest of the environment. Exit 0. Record the total time and compare with the limits (15 minutes per tenant, 10 minutes of waiting for operations in progress).
14. **`arco remove` without customer domains.** Deploy again and run `arco remove --env=acceptance --yes` with no tenants: expect it to go through without `--force`, deleting the tenant resources in the order above, exit 0.

### Environment variations

15. **Permissions boundary and `role()`.** (a) With `->permissionsBoundary('<boundary policy arn>')` in the environment: expect the mediator's role to carry the boundary (`aws iam get-role`), and, with a boundary that does not allow `cloudfront:*DistributionTenant*` and `lambda:*`, record what fails and where (the mediator's calls answer `AccessDenied`, mapped to what error code). (b) With `->role('<your role arn>')` that lacks `lambda:InvokeFunction` on the mediator: expect `TenantDomainsUnavailable` whose message names the action and the function; add the statement and expect `add` to work.
16. **App in a VPC without NAT.** With `->vpc(...)` private subnets and no NAT or `com.amazonaws.<region>.lambda` endpoint: expect `TenantDomainsUnavailable` or a timeout from `add` (record which, and after how many seconds); add the Lambda interface endpoint and expect it to work. The mediator itself is not in the VPC.

### Clean up

17. Remove the app (`arco remove --force --yes`), then the retained queues, buckets, tables, log groups (`/aws/lambda/<app>-acceptance*`, `/aws/lambda/<app>-acceptance-tenants`) and the parameters by hand; delete the Route 53 and external DNS records you created; confirm in the CloudFront console that no tenant, connection group or multi-tenant distribution with the app's tags remains (`aws resourcegroupstaggingapi get-resources --tag-filters Key=arco:app,Values=<app>`).

## Results

Copy this table into the pull request or release notes that adopts the result.

Tag ownership (steps 4, 10, 13): on 2026-11-18 real CloudFront returned no `Tags` on `GetDistributionTenant`; `TenantInventory` reads ownership with `ListTagsForResource` on the tenant's ARN (`DistributionTenant.Arn`).

| Step | Date | Expected | Observed | Pass? | Notes (request ids, timings, error names) |
|---|---|---|---|---|---|
| 1 First deploy | 2026-11-17 (arco-smoke staging, CLI d6eb1e0) | stack, record, mediator, `tenants: 0` | tenant stack `ROLLBACK_COMPLETE`: `ViewerCertificate`: "configured for SNI and the minimum SSL protocol version is specified as SSLv3" | No | The template had no `ViewerCertificate`. Fixed after this run (default certificate, `sni-only`, `TLSv1.2_2021`); rerun pending. The web deploy and `domain()` had already succeeded. |
| 2 Redeploy unchanged | 2026-11-18 (arco-smoke staging, CLI c6666fe) | no stack update, no tenant touched | redeploys over two existing tenants went through the change set without replacement; same distribution, connection group and endpoint; target record recognised and left alone; tenants untouched | Yes | Not a strictly unchanged redeploy: each one carried a template fix. Rerun once with no change. |
| 3a Target change refused | | `domains.tenant_target_changed` | | | |
| 3b Replacement refused | | `domains.stack_replacement` | | | |
| 4 Certificate via CNAME | 2026-11-18 (arco-smoke staging, CLI 72388e2) | `pending` then `active` | `add` failed: `AccessDenied: CloudFront cannot access the specified Amazon ACM certificate for the operation: RequestCertificate` | No | The mediator role had no ACM action. Fixed after this run (`ArcoTenantCertificates`); rerun on 406372f: `add` created the tenant and the certificate was issued within minutes, but `status` answered `not_found` (`GetDistributionTenantByDomain` only finds active domains) and the domain stayed `inactive` with `Customizations` null and no HTTPS. Fixed after that run: lookup by tenant name as fallback, and `status` applies the issued certificate to the tenant (hypothesis, to confirm on the next run). Step 1 passed: stack created, app reaches the mediator. time to active: |
| 4a CNAME chain | | issues | | | |
| 4b Flattened record | | `add` passes only with all addresses in the endpoint's | | | |
| 4c No record | | `TenantDomainDnsIncorrect` | | | |
| 5 Requests through the tenant | 2026-11-18 (arco-smoke staging, CLI 22fec4b) | app reached, headers, `+`, per-host cache, direct URL 403 | domain `active` after `status` applied the issued certificate (activation confirmed); request answered `502 The CloudFront function returned an invalid value for origin rewrite` | No | The tenant function called `cf.updateRequestOrigin({hostHeader})`. Fixed after this run: same function and origin request policy as `domain()`, assets keyed by `x-forwarded-host`. Rerun pending. |
| 5 Requests through the tenant (rerun) | 2026-11-18 (arco-smoke staging, CLI c6666fe) | app reached, headers, `+`, per-host cache, direct URL 403 | `https://cliente1.e2e.arcocloud.app/prueba?q=a+b&x=%2B` answered by the app: `Host` = endpoint, `X-Forwarded-Host` = customer domain, query `q=a%20b&x=%2B` (`a b`, `+`), `CloudFront-Viewer-Address` present; HTTPS with the managed certificate | Partly | Not checked yet: per-host cache of `/build/*`, direct endpoint URL 403 from outside. `request()->ip()` showed 169.254.100.1: compare with the `domain()` host. |
| 6a Cloudflare DNS only | | record created, proxied refused | | | |
| 6b Manual DNS resume | | stops, resumes | | | DNS wait: |
| 7 Unavailable cases | | `TenantDomainsUnavailable` | | | |
| 8 add / duplicate / list paging | 2026-11-18 (arco-smoke staging, CLI c6666fe) | as documented | `add` → `pending`; `status` → `pending`/`activating` then `active` within about 5 minutes; `list` returned both domains `active`, `cursor: null` | Partly | Duplicate add and paging with a cursor not exercised yet. Activation needs the issued certificate applied with `UpdateDistributionTenant` (confirmed). |
| 8 Domain on another distribution | | `TenantDomainTaken` | | | error name: |
| 8 Quota | | `TenantDomainQuotaExceeded` | | | error name / not reached: |
| 8 remove (pending / idempotent) | 2026-11-18 (arco-smoke staging, CLI c6666fe) | disable then delete | `remove` of an active domain answered `removed: true` on the first call; `list` then showed only the other domain | Yes | disable-to-delete time: immediate (no `ResourceNotDisabled` / `PreconditionFailed` seen). Idempotent second remove not exercised. |
| 9 Empty list answer | | `DistributionTenantList` present (empty) | | | exact JSON: |
| 10 remove without `--force` | | stops, nothing deleted | | | |
| 11 Restore | | works again | | | |
| 12 Drain margin | | throttled within seconds | | | first throttle: / last success: |
| 13 remove `--force` | | everything gone, exit 0 | | | per-tenant timings: |
| 14 remove without tenants | | goes through | | | |
| 15a Boundary | | role has it | | | |
| 15b `role()` without invoke | | `TenantDomainsUnavailable` | | | |
| 16 VPC without NAT | | unavailable, then works with endpoint | | | |
| 17 Clean up | | nothing left | | | |

When every row passes, remove the "experimental" wording from the tenant sections of the user docs, the changelog and `agents.md`, and record the date and CloudFront behaviour found here (especially rows 8 and 9) next to the code that depends on it. When a row does not pass, file the difference as a bug against the code, not as a note: the docs stay "experimental" until it is fixed and the row repeated.
