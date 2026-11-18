# Arco for Laravel

Laravel integration for [Arco](https://github.com/multi-api/arco), which deploys Laravel to AWS
Lambda Web Functions with FrankenPHP and Octane.

```bash
composer require multiapi/arco-laravel laravel/octane
composer require --dev multiapi/arco
```

Install it as a regular dependency: its service provider ships with your app. On Lambda (only when
Arco runs the app) it:

- trusts the AWS front end's `X-Forwarded-*` headers, so Laravel sees `https`, the real host and
  client IP;
- passes Lambda's session token to every AWS client configured with the function's key (DynamoDB
  cache, SQS, S3, SES, failed jobs, your own services);
- sends stock logging to stderr as JSON, with the request or invocation id and the X-Ray trace id in every
  line (and the job's name on queue workers), and keeps sessions out of files.
- registers each `storage()` of `arco.php` as a disk of the same name (`Storage::disk('uploads')`), points
  the DynamoDB cache store at a table Arco created, and sends failed jobs to the table of
  `failedJobsTable()`.

In any environment it adds `php artisan arco:failed-jobs`: `--json` lists the failed jobs with their exception
as one JSON line, `--flush` forgets them on any failed-jobs store (DynamoDB included, where `queue:flush`
throws), and `--queue=<url>` limits either to one queue.

It also provides the Laravel preset Arco uses to package your app (Octane worker, storage in
`/tmp`, `/up` health route, cached asset paths).

## Arco Cloud extensions

Arco Cloud can add extensions to an app it deploys. An extension is a directory under `.arco-cloud/<name>/`
whose `bootstrap.php` returns the class name of a service provider; on Lambda this package registers each one.
An extension that throws or returns anything else is logged to stderr as one JSON line and skipped, so it never
stops the app from booting.

Documentation, issues and pull requests: [multi-api/arco](https://github.com/multi-api/arco).
This repository is a read-only split.

## License

MIT

## Tenant domains

With `tenantDomains()` in `arco.php`, the app manages its customers' domains through
`Multiapi\Arco\Laravel\Contracts\TenantDomains` (inject it) or the `Multiapi\Arco\Laravel\Facades\TenantDomains` facade:

```php
$status = TenantDomains::add('shop.customer.com');   // pending; a retry returns the current state
TenantDomains::status('shop.customer.com');          // pending | active | failed, with a reason
$page = TenantDomains::list(limit: 50);              // $page->domains, $page->cursor (null at the end)
TenantDomains::remove('shop.customer.com');          // disables at once; may throw TenantDomainRemovalPending
```

Errors are typed, all extending `TenantDomains\Exceptions\TenantDomainException`: `InvalidTenantDomain`,
`TenantDomainDnsIncorrect` (`$expected` holds the CNAME target), `TenantDomainTaken`, `TenantDomainQuotaExceeded`,
`TenantDomainNotFound`, `TenantDomainRemovalPending` and `TenantDomainsUnavailable` (not running on Arco, or
`tenantDomains()` not deployed, or the app's role may not invoke the mediator, for example under a permissions
boundary). It only works on Arco.

A new domain is not served until its certificate is issued **and** applied: only `status` (or a repeated `add`)
applies it, so poll `status` (every 30 seconds or so) until it answers `active`; `list` never changes anything. While
the domain is inactive `status` answers `pending`, not `TenantDomainNotFound`. `pending` carries a reason: none
(certificate being validated), `activating`, `removing` or `activation failed: <ErrorName>` (the next `status`
retries); `failed` carries `certificate <status>`. The viewer's `Authorization` header arrives as sent, as on `domain()`. The visitor's address is `$request->ip()` (not `REMOTE_ADDR`), the
same on a customer domain as on the app's own domain; the customer's host is `$request->getHost()`.

In tests use the fake:

```php
$fake = TenantDomains::fake();
// ... code under test ...
$fake->assertAdded('shop.customer.com');
$fake->assertRemoved('old.customer.com');
$fake->assertNothingAdded();
```

See [Tenant domain routing](https://github.com/multi-api/arco/blob/main/docs/configuration.md#tenant-domain-routing).
