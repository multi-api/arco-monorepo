# Working on Arco

Instructions for coding agents (Claude Code, Codex, ...) changing this monorepo. If you are **using** Arco to deploy
an app, read [packages/arco/docs/agents.md](packages/arco/docs/agents.md) instead.

## Layout

| Path | What it is |
|---|---|
| `packages/arco` | The CLI, `multiapi/arco`: `src/Commands` (one Symfony command per `arco` command), `src/Config` (`arco.php` API, validation, `ArcoFileEditor`), `src/Deploy` (AWS deploy, remove, ownership guards), `src/Build` and `src/Package` (staging copy and zip), `runtime/` (the Node and PHP code that runs inside Lambda), `lang/en.php` and `lang/es.php` (every message), `docs/` (user docs, shipped in the package) |
| `packages/arco-laravel` | `multiapi/arco-laravel`: the service provider apps install in `require` |
| `packages/arco-secrets` | `multiapi/arco-secrets`: `arco-ssm:` / `arco-secret:` references |
| `examples/laravel` | The app the integration and end-to-end suites deploy |
| `tests/Unit`, `tests/Integration`, `tests/E2e` | PHPUnit suites; `tests/Support` has the temp-path and emulator helpers |
| `docs/maintainers` | Releasing, runtime builds, end-to-end runs |
| `bin/test-docker` | The only way to run tests |

Each package is split into its own repository when a tag is pushed (`.github/workflows/split.yml`), so links inside
`packages/arco` must stay relative to that package.

## Tests

Run tests only in the container, never with `phpunit` or `composer test` on the host:

```bash
bin/test-docker                          # unit, runtime (node --test) and PHPStan
bin/test-docker unit --filter SetCommandTest
bin/test-docker analyse                  # PHPStan, level max
bin/test-docker integration              # Lambda simulation (needs Docker)
bin/test-docker e2e                      # deploys examples/laravel to a throwaway Floci
```

- Many tests create and delete files. A test bug once deleted files from a developer's real temporary directory; the
  container sees only the repository. Never point code that deletes, cleans up or overwrites at `/`, `$HOME`,
  `$TMPDIR` or any real path, not even to see a test fail. Use `tests/Support/TempPaths` for every temp path.
- `bin/test-docker e2e-aws` is the only mode that reaches real AWS (a dedicated account, dry run by default). Do not
  run it unless the maintainer asks.
- Write the failing test first, then the code. Commands are tested through Symfony's `CommandTester` with fake AWS
  clients; look at a neighbouring test before writing a new one.

## Conventions

- **Messages.** Every user-facing text is a key in `packages/arco/lang/en.php` **and** `lang/es.php`, with the same
  placeholders (`tests/Unit/Cli/LangParityTest.php` enforces it; a message saying `{days} days` needs a singular
  `..._one_day` sibling). Raise errors with `$this->userError('<key>', [...])` so `--json` carries the key.
- **The 1.x promise** ([upgrading.md](packages/arco/docs/upgrading.md#the-1x-promise)). `arco.php` methods and their
  documented named arguments, commands and options, `--json` documents (`"schema": 1`, keys only added), NDJSON step
  and error keys with their params (params only gain entries), exit codes, variables Arco sets and AWS names and tags
  are API. Add, never rename or remove; deprecate with a warning naming the replacement.
- **Docs with the code.** A user-visible change updates, in the same branch: `packages/arco/CHANGELOG.md` (under
  `## Unreleased`, as **Added**/**Changed**/**Fixed**, naming new keys), the reference in
  `packages/arco/docs/configuration.md` or `getting-started.md`, the README command table when a command or flag
  changes, `docs/upgrading.md` for anything a user must do, and `packages/arco/docs/agents.md` when a command, flag,
  non-interactive behaviour, `arco.php` method or error key that agents meet changes.
- **Safety in AWS code.** Check ownership (`arco:app` / `arco:environment` tags) before changing or deleting a
  resource; refuse before the first change rather than half way; keep user data (queues, buckets, tables) on remove.
  New AWS calls need the permission added to the IAM lists in `getting-started.md`.
- **Editing `arco.php`.** Commands that edit the user's file go through `ArcoFileEditor` (tokenizer-based, `php -l`,
  reload-and-compare, restore on mismatch). When a shape is not safe to edit, print the line to write by hand.
- Code, comments, docs and commit messages are in English. Commit subjects follow `feat:`, `fix:`, `docs:`,
  `test:`, `refactor:`, `chore:`.
- PHP 8.3+, `declare(strict_types=1)`, PHPStan level max with no new ignores.

## Git

- Work on a branch in its own worktree (`git worktree add ../arco-<topic> -b <type>/<topic> origin/main`); do not
  switch branches in a checkout someone else is using.
- The maintainer pushes, opens pull requests, merges and releases ([releasing.md](docs/maintainers/releasing.md)).
