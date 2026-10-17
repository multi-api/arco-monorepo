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
- sends stock logging to stderr as JSON, with a request id in every line, and keeps sessions out
  of files.
- registers each `storage()` of `arco.php` as a disk of the same name (`Storage::disk('uploads')`), points
  the DynamoDB cache store at a table Arco created, and sends failed jobs to the table of
  `failedJobsTable()`.

It also provides the Laravel preset Arco uses to package your app (Octane worker, storage in
`/tmp`, `/up` health route, cached asset paths).

Documentation, issues and pull requests: [multi-api/arco](https://github.com/multi-api/arco).
This repository is a read-only split.

## License

MIT
