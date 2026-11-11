'use strict';
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const path = require('node:path');
const crypto = require('node:crypto');
const { MAX_PATH, requestId: requestIdOf, pipeAccessLog } = require('./accesslog');

const PHP_TMP_SUBDIRS = [
  'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions',
  'storage/framework/views', 'storage/logs', 'caddy/config', 'caddy/data',
];

function prepareTmp(dir, subdirs = PHP_TMP_SUBDIRS) {
  for (const sub of subdirs) fs.mkdirSync(path.join(dir, sub), { recursive: true });
}

function resolveBinary(root, tmp) {
  const bundled = path.join(root, 'bin/frankenphp');
  try {
    fs.accessSync(bundled, fs.constants.X_OK);
    return bundled;
  } catch {
    const copy = path.join(tmp, 'frankenphp');
    if (!fs.existsSync(copy)) {
      fs.copyFileSync(bundled, copy);
      fs.chmodSync(copy, 0o755);
    }
    return copy;
  }
}

// Starts a child, forwards SIGTERM/SIGINT to it and reports its end through onExit(code, signal).
function spawnProcess(command, args, { env, cwd, stdio, onExit } = {}) {
  const child = spawn(command, args, { cwd, env, stdio });
  child.on('exit', (code, signal) => {
    if (onExit) onExit(code, signal);
  });
  for (const signal of ['SIGTERM', 'SIGINT']) process.on(signal, () => child.kill(signal));
  return child;
}

function spawnFrankenphp(binary, caddyfile, env, cwd, log, { accessLog = false } = {}) {
  const stdio = accessLog ? ['inherit', 'pipe', 'inherit'] : 'inherit';
  const child = spawnProcess(binary, ['run', '--config', caddyfile, '--adapter', 'caddyfile'], {
    cwd,
    env,
    stdio,
    onExit: (code, signal) => {
      log('frankenphp_exit', { code, signal });
      process.exit(code ?? 1);
    },
  });
  if (accessLog) pipeAccessLog(child.stdout, process.stdout);
  return child;
}

function waitForPort(host, port, timeoutMs = 9000, name = 'FrankenPHP') {
  const deadline = Date.now() + timeoutMs;
  return new Promise((resolve, reject) => {
    const attempt = () => {
      const socket = net.connect(port, host);
      socket.once('connect', () => { socket.destroy(); resolve(); });
      socket.once('error', () => {
        socket.destroy();
        if (Date.now() > deadline) reject(new Error(`${name} did not listen on ${host}:${port} within ${timeoutMs} ms`));
        else setTimeout(attempt, 25);
      });
    };
    attempt();
  });
}

// True for /_arco and /_arco/..., however the path is spelled: percent-encoded, with repeated slashes or with dot
// segments. An undecodable path counts as internal.
function isInternalPath(rawUrl) {
  let decoded;
  try {
    decoded = decodeURIComponent(String(rawUrl).split('?', 1)[0]);
  } catch {
    return true;
  }
  const segments = [];
  for (const segment of decoded.split('/')) {
    if (segment === '' || segment === '.') continue;
    if (segment === '..') segments.pop();
    else segments.push(segment);
  }
  return segments[0] === '_arco';
}

const HOP_BY_HOP = new Set(['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade', 'te', 'trailer']);

// options is for the process kind (PHP passes none): blockInternal answers 404 for /_arco/ paths, requestIds adds an
// X-Request-ID when the request carries no id, accessLog writes one {"arco":"request",...} line per response to `output`.
function startProxy(host, port, upstreamHost, upstreamPort, options = {}) {
  const { blockInternal = false, requestIds = false, accessLog = false, output = process.stdout } = options;
  const agent = new http.Agent({ keepAlive: true, maxSockets: 256 });
  const server = http.createServer((req, res) => {
    const startedAt = process.hrtime.bigint();
    const state = { requestId: null };
    if (accessLog) {
      res.once('close', () => {
        const [path] = String(req.url).split('?', 1);
        output.write(`${JSON.stringify({
          arco: 'request',
          status: res.statusCode,
          method: req.method,
          path: path.slice(0, MAX_PATH),
          ms: Math.round(Number(process.hrtime.bigint() - startedAt) / 1e6),
          requestId: state.requestId,
        })}\n`);
      });
    }
    if (requestIds) {
      state.requestId = requestIdOf(req.headers);
      if (state.requestId === null) {
        state.requestId = crypto.randomUUID();
        req.headers['x-request-id'] = state.requestId;
      }
    }
    if (blockInternal && isInternalPath(req.url)) {
      res.writeHead(404, { 'content-type': 'text/plain' });
      res.end('Not Found');
      return;
    }
    const headers = {};
    for (const [name, value] of Object.entries(req.headers)) if (!HOP_BY_HOP.has(name)) headers[name] = value;
    const remote = req.socket.remoteAddress;
    headers['x-forwarded-for'] = req.headers['x-forwarded-for'] ? `${req.headers['x-forwarded-for']}, ${remote}` : remote;
    headers['x-forwarded-proto'] ??= 'https';
    headers['x-forwarded-host'] ??= req.headers.host;

    const upstream = http.request({ host: upstreamHost, port: upstreamPort, method: req.method, path: req.url, headers, agent }, (up) => {
      const out = {};
      for (const [name, value] of Object.entries(up.headers)) if (!HOP_BY_HOP.has(name)) out[name] = value;
      res.writeHead(up.statusCode, up.statusMessage, out);
      up.pipe(res);
    });
    upstream.on('error', () => {
      if (!res.headersSent) res.writeHead(502, { 'content-type': 'text/plain' });
      res.end('Bad Gateway');
    });
    req.on('aborted', () => upstream.destroy());
    req.pipe(upstream);
  });
  server.keepAliveTimeout = 65_000;
  return new Promise((resolve) => server.listen(port, host, () => resolve(server)));
}

module.exports = { prepareTmp, resolveBinary, spawnProcess, spawnFrankenphp, waitForPort, startProxy, isInternalPath };
