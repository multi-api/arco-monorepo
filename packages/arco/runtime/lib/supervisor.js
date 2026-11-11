'use strict';
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const path = require('node:path');
const { pipeAccessLog } = require('./accesslog');

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

const HOP_BY_HOP = new Set(['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade', 'te', 'trailer']);

// options is reserved for the process kind (request ids, access log, /_arco/ guard); PHP passes none.
function startProxy(host, port, upstreamHost, upstreamPort, options = {}) {
  const agent = new http.Agent({ keepAlive: true, maxSockets: 256 });
  const server = http.createServer((req, res) => {
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

module.exports = { prepareTmp, resolveBinary, spawnProcess, spawnFrankenphp, waitForPort, startProxy };
