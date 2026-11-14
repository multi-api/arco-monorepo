# Arco Cloud extensions (process apps)

A [process app](configuration.md#process-runtime-go) can ship **extensions**: programs that Arco Cloud puts inside
the package and the runtime starts next to your app. The runtime knows one generic kind, a **front**: a process that
listens where your app would and forwards requests to it. The runtime does not know what a front does.

You normally do not write these files: Arco Cloud adds them to your package. This page is the contract between the
runtime and a front.

## Where they live

An extension is a directory under `.arco-cloud/` in the project, shipped as `app/.arco-cloud/<name>/` in the package:

```text
.arco-cloud/
  my-front/
    extension.json
    my-front          # the executable
```

```json
{ "front": { "command": ["my-front", "--flag"] } }
```

- `front.command` is a non-empty list of non-empty strings. The first is the executable, **relative to the
  extension's own directory**; the rest are its arguments. The working directory is that directory too.
- A zip that lost the executable bit is handled as for the app: the runtime runs a copy from `/tmp/arco/bin`.
- Only one front runs. The first valid one **by directory name** wins; the others are ignored and the runtime logs
  `{"arco":"extension_ignored","extension":"<name>"}` for each.
- A directory without `extension.json`, or without a `front`, is not an extension. An invalid `extension.json` logs
  `extension_failed` with the error and is skipped.
- For process apps, `.arco-cloud/` is always packaged, whatever `include` and `exclude` say.

## Ports and environment

With a front, the front takes the port the app would have had, and the app moves one port up:

| Process | Listens on |
|---|---|
| front | `127.0.0.1:8000` |
| your app | `127.0.0.1:8001` (its `PORT` is `8001`) |

Without a front the app listens on `127.0.0.1:8000`, as always. Queue and schedule deliveries go to port 8000 too,
so they pass through the front.

The front gets the app's environment plus:

| Variable | Value |
|---|---|
| `ARCO_FRONT_LISTEN` | `127.0.0.1:8000`: where to listen |
| `ARCO_FRONT_UPSTREAM` | `127.0.0.1:8001`: where the app listens |
| `ARCO_STARTED_AT` | Unix time in milliseconds when the runtime process started (not the first request) |

## Deadline header

Every queue and schedule delivery carries `X-Arco-Deadline`: the Unix time in milliseconds by which Arco gives up on
the request. It is the end of the same time budget used for the request's timeout. Web requests do not carry it.

## Start, wait and fallback

1. The app and the front start together. The runtime waits up to **10 seconds** for the app's port.
2. Once the app listens, the front gets **2 seconds more** to listen on 8000.
3. If the front fails to start, exits, errors, or does not listen in time, the runtime logs
   `{"arco":"extension_failed","extension":"<name>","reason":"..."}` **once** and from then on sends every request
   (web, queue and schedule) straight to the app on 8001. A front can never take the app down.
4. If the app itself fails to start or exits, the function instance ends as for any process app.

On shutdown the app and the front both get `SIGTERM` and the same one-second grace as the app
([the `PORT` contract](configuration.md#the-port-contract)), then `SIGKILL`.
