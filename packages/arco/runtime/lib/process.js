'use strict';
// The process kind: the customer's own binary (Go first) listens on 127.0.0.1:8000 behind the Node proxy.
const fs = require('node:fs');
const path = require('node:path');
const supervisor = require('./supervisor');
const extensions = require('./extensions');

// Without a front the app listens on PORT; with one the front takes PORT and the app moves to APP_PORT.
const PORT = 8000;
const FRONT_PORT = PORT;
const APP_PORT = 8001;
const HOST = '127.0.0.1';
const TMP = '/tmp/arco';
const START_TIMEOUT_MS = 10_000;
// Extra time the front gets to listen once the app does; after that the app is served directly.
const FRONT_TIMEOUT_MS = 2000;
const STARTED_AT = Math.round(Date.now() - process.uptime() * 1000);
// How long a process gets to end after SIGTERM before it is killed. Lambda spins down idle instances only.
const STOP_GRACE_MS = 1000;

// command is [executable, ...arguments], the executable relative to baseDir. A zip that lost the executable bit gets a
// copy in /tmp/arco/bin (named copyName, default the executable's), as resolveBinary does for FrankenPHP.
function resolveExecutable(baseDir, command, copyName = path.basename(command[0])) {
  const bundled = path.join(baseDir, command[0]);
  try {
    fs.accessSync(bundled, fs.constants.X_OK);
    return bundled;
  } catch {
    const copy = path.join(TMP, 'bin', copyName);
    if (!fs.existsSync(copy)) {
      fs.copyFileSync(bundled, copy);
      fs.chmodSync(copy, 0o755);
    }
    return copy;
  }
}

// Spawns the front; null when it cannot start. A front never ends the runtime.
function spawnFront(front, appEnv, log, state) {
  try {
    const executable = resolveExecutable(front.dir, front.command, `${front.name}-${path.basename(front.command[0])}`);
    const child = supervisor.spawnProcess(executable, front.command.slice(1), {
      cwd: front.dir,
      env: {
        ...appEnv,
        ARCO_FRONT_LISTEN: `${HOST}:${FRONT_PORT}`,
        ARCO_FRONT_UPSTREAM: `${HOST}:${APP_PORT}`,
        // Unix ms of the start of this Node process (not of the first request): the front shares the app's clock.
        ARCO_STARTED_AT: String(STARTED_AT),
      },
      stdio: 'inherit',
      onExit: (code, signal) => {
        if (state.stopping) return;
        log('extension_failed', { extension: front.name, reason: 'exited', code, signal });
        state.upstream.port = APP_PORT;
      },
    });
    child.on('error', (error) => {
      log('extension_failed', { extension: front.name, reason: 'error', error: error.message });
      state.upstream.port = APP_PORT;
    });
    return child;
  } catch (error) {
    log('extension_failed', { extension: front.name, reason: 'spawn', error: error.message });
    return null;
  }
}

// Resolves { child, upstream }: child is the app, upstream.port is where requests go (read it per request: a front
// that dies switches it to the app).
async function startProcess(root, config, env, log) {
  const appDir = path.join(root, 'app');
  const front = extensions.findFront(appDir, log);
  const appPort = front ? APP_PORT : PORT;
  const upstream = { port: front ? FRONT_PORT : PORT };
  const state = { stopping: false, upstream };
  const appEnv = { ...env, PORT: String(appPort), HOST, ARCO: '1', HOME: TMP };
  const executable = resolveExecutable(appDir, config.command);
  const child = supervisor.spawnProcess(executable, config.command.slice(1), {
    cwd: appDir,
    env: appEnv,
    stdio: 'inherit',
    onExit: (code, signal) => {
      if (state.stopping) {
        // Lambda asked for the shutdown: any end of the process is a clean one. A non-zero exit would be reported
        // as Runtime.ExitError in platform.spinDownReport. A Go binary without a signal handler dies by SIGTERM.
        log('process_exit', { code, signal, shutdown: true });
        process.exit(0);
        return;
      }
      log('process_exit', { code, signal });
      process.exit(code ?? 1);
    },
  });
  // The front starts with the app and gets its environment (plus the ARCO_FRONT_* variables).
  const frontChild = front ? spawnFront(front, appEnv, log, state) : null;
  if (front && !frontChild) upstream.port = APP_PORT;
  // supervisor.spawnProcess forwards the signal to each process; one that ignores it is killed after the grace.
  for (const name of ['SIGTERM', 'SIGINT']) {
    process.on(name, () => {
      if (state.stopping) return;
      state.stopping = true;
      setTimeout(() => child.kill('SIGKILL'), STOP_GRACE_MS).unref();
      if (frontChild) setTimeout(() => frontChild.kill('SIGKILL'), STOP_GRACE_MS).unref();
    });
  }
  await supervisor.waitForPort(HOST, appPort, START_TIMEOUT_MS, 'process');
  if (frontChild && upstream.port === FRONT_PORT) {
    try {
      await supervisor.waitForPort(HOST, FRONT_PORT, FRONT_TIMEOUT_MS, 'extension');
    } catch (error) {
      // Only once: an exit or spawn error may have logged already.
      if (upstream.port === FRONT_PORT) log('extension_failed', { extension: front.name, reason: 'not_listening', error: error.message });
      upstream.port = APP_PORT;
    }
  }
  log('process_ready');
  return { child, upstream };
}

module.exports = { PORT, FRONT_PORT, APP_PORT, HOST, startProcess, isInternalPath: supervisor.isInternalPath };
