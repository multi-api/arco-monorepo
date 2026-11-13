# Telemetry (maintainers)

What users are told: [packages/arco/docs/telemetry.md](../../packages/arco/docs/telemetry.md). The collector, its
ingest into Arco Cloud and the admin Invocations page live in `arco-cloud` (`docs/telemetry.md`,
`infra/telemetry/`).

## Code

| Where | What |
|---|---|
| `packages/arco/runtime/lib/telemetry.js` | Node counter and sender (`createTelemetry`: `count`, `tick`, `flush`, `prepare`, `booted`) |
| `packages/arco/runtime/lib/caddycount.js`, `lib/caddyfile.js` | Laravel direct mode: Caddy's `arco_count` logger sends one line per response to a TCP counter on an ephemeral `127.0.0.1` port; if it cannot listen, the Caddyfile is the one without telemetry |
| `packages/arco/runtime/index.js`, `worker.js`, `lib/supervisor.js` | Web (Caddy counter or the proxy's `onResponse`), process queues and schedules |
| `packages/arco/runtime/console/Telemetry.php`, `console.php` | PHP counter; console and `schedule:run` |
| `packages/arco-laravel/src/Console/WorkCommand.php` | Laravel queues; requires `runtime/console/Telemetry.php` from the function package, so its public API (`fromRuntime`, `enabled`, `count`, `tick`, `flush`) must stay backward compatible |
| `tests/Fixtures/telemetry-packets.json` | Packets both implementations must encode identically |

The CLI writes `arcoVersion` (always) and `telemetry: false` (only when off) into `arco-runtime.json`. The collector
rejects any version that is not semver, so packets from a monorepo build (`dev`) are dropped there.

## `ARCO_TELEMETRY_HOST`

Undocumented for users. In a function's environment it replaces the collector: `host:port`, or `host` alone for port
41700. The name is resolved once per environment (IPv4); a name that does not resolve turns telemetry off for that
environment. `ARCO_TELEMETRY_DISABLE=1` still wins.

## Tests

- `bin/test-docker unit` and `bin/test-docker runtime`: both counters, the encoding fixture, the console loop and
  `arco:work` against a local UDP socket (`tests/Support/TelemetryCollector.php`).
- `bin/test-docker integration`: `LambdaSimulationTest`, `ConsoleRuntimeTest` and `WorkerRuntimeTest` point
  `ARCO_TELEMETRY_HOST` at a UDP socket in the PHPUnit container (reached by its name on the test network) and check the
  packets of a web request, a console command, `schedule:run` and a queue batch, the SIGTERM flush, and that
  `ARCO_TELEMETRY_DISABLE=1` and `"telemetry": false` send nothing. `LambdaSimulationTest` also prints the
  `[telemetry overhead]` lines (1,000 requests on and off).

## Testing against the staging collector

The staging collector listens on `telemetry.staging.arcocloud.app:41700`. It counts only semver versions, and staff
see the result in staging's `/admin` Invocations page once the hour has closed (plus about 5 minutes); the
collector's minute log line (`accepted`, `rejected`) shows it sooner (`infra/telemetry/README.md` in `arco-cloud`).

- One packet by hand:

  ```bash
  printf 'arco1 r=laravel k=web v=0.21.0-test g=us-east-1 i=1 c=1 b=0' | nc -u -w1 telemetry.staging.arcocloud.app 41700
  ```

- A real runtime, locally: package an app (`arco package`), unzip it, set `arcoVersion` in its `arco-runtime.json` to a
  semver such as `0.21.0-test`, and run it as `LambdaSimulationTest` does (`node:24-slim`, `node /var/task/index.js`)
  with `-e AWS_REGION=us-east-1 -e ARCO_TELEMETRY_HOST=telemetry.staging.arcocloud.app:41700`. One request sends the
  cold-start packet; `docker stop` sends the rest.
- A deployed app: deploy it with a released CLI (a semver version), then add `ARCO_TELEMETRY_HOST` to a function's
  environment in AWS (`variables()` refuses `ARCO*` names, and the next deploy replaces the variables). Keep the
  existing variables when you do: `aws lambda update-function-configuration --environment` replaces all of them.

Use a test version suffix (`-test`) or a staging-only account so test rows are easy to tell apart.
