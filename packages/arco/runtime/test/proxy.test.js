'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { startProxy } = require('../lib/supervisor');

const listen = (server) => new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));

// A real upstream that records every request it receives, behind a real proxy on a free port.
async function setup(t, options) {
  const seen = [];
  const upstream = http.createServer((req, res) => {
    seen.push({ url: req.url, headers: req.headers });
    res.writeHead(req.method === 'POST' ? 201 : 200, { 'content-type': 'text/plain' });
    res.end('upstream');
  });
  const upstreamPort = await listen(upstream);
  const lines = [];
  const output = { write: (chunk) => { lines.push(chunk); return true; } };
  const probe = http.createServer();
  const proxyPort = await listen(probe);
  await new Promise((resolve) => probe.close(resolve));
  const proxy = await startProxy('127.0.0.1', proxyPort, '127.0.0.1', upstreamPort, options === undefined ? undefined : { ...options, output });
  t.after(() => { proxy.closeAllConnections(); proxy.close(); upstream.closeAllConnections(); upstream.close(); });
  // Raw requests: http.request keeps the path as written, so '..' and '%5F' reach the proxy untouched.
  const request = (path, { method = 'GET', headers = {} } = {}) => new Promise((resolve, reject) => {
    const req = http.request({ host: '127.0.0.1', port: proxyPort, path, method, headers, agent: false }, (res) => {
      let body = '';
      res.setEncoding('utf8');
      res.on('data', (chunk) => { body += chunk; });
      res.on('end', () => resolve({ status: res.statusCode, type: res.headers['content-type'], body }));
    });
    req.on('error', reject);
    req.end();
  });
  return { seen, lines, request };
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

test('blockInternal answers 404 Not Found for every spelling of /_arco and never reaches the app', async (t) => {
  const { seen, request } = await setup(t, { blockInternal: true });
  for (const path of ['/_arco/queues/x', '/%5Farco/queues/x', '/%5farco/x', '//_arco/x', '/a/../_arco/x', '/_arco', '/_arco?x=1', '/%zz']) {
    const response = await request(path);
    assert.equal(response.status, 404, path);
    assert.equal(response.body, 'Not Found', path);
    assert.match(response.type, /^text\/plain/, path);
  }
  assert.equal(seen.length, 0);
});

test('blockInternal forwards paths that only look similar', async (t) => {
  const { seen, request } = await setup(t, { blockInternal: true });
  assert.equal((await request('/_arcox')).status, 200);
  assert.equal((await request('/api/_arco')).status, 200);
  assert.equal((await request('/')).body, 'upstream');
  assert.equal(seen.length, 3);
});

test('requestIds adds a UUID x-request-id when the request has none of the id headers', async (t) => {
  const { seen, request } = await setup(t, { requestIds: true });
  await request('/');
  assert.match(seen[0].headers['x-request-id'], UUID);
});

test('requestIds keeps the caller id: no x-request-id is added and the log carries it', async (t) => {
  const { seen, lines, request } = await setup(t, { requestIds: true, accessLog: true });
  await request('/', { headers: { 'x-amzn-trace-id': 'Root=1' } });
  assert.equal(seen[0].headers['x-request-id'], undefined);
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(JSON.parse(lines[0]).requestId, 'Root=1');
});

test('accessLog writes one request line per response, with the generated id', async (t) => {
  const { seen, lines, request } = await setup(t, { requestIds: true, accessLog: true });
  await request('/orders/42?x=1', { method: 'POST' });
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(lines.length, 1);
  assert.ok(lines[0].endsWith('\n'));
  const line = JSON.parse(lines[0]);
  assert.deepEqual(Object.keys(line), ['arco', 'status', 'method', 'path', 'ms', 'requestId']);
  assert.deepEqual({ ...line, ms: 0, requestId: null }, { arco: 'request', status: 201, method: 'POST', path: '/orders/42', ms: 0, requestId: null });
  assert.ok(Number.isInteger(line.ms) && line.ms >= 0);
  assert.equal(line.requestId, seen[0].headers['x-request-id']);
});

test('accessLog also logs the blocked 404', async (t) => {
  const { lines, request } = await setup(t, { blockInternal: true, requestIds: true, accessLog: true });
  await request('/_arco/queues/x');
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(lines.length, 1);
  const line = JSON.parse(lines[0]);
  assert.equal(line.status, 404);
  assert.equal(line.path, '/_arco/queues/x');
  assert.match(line.requestId, UUID);
});

test('without options the proxy blocks nothing, adds no id and writes no line', async (t) => {
  const { seen, lines, request } = await setup(t);
  const response = await request('/_arco/queues/x');
  assert.equal(response.status, 200);
  assert.equal(seen[0].headers['x-request-id'], undefined);
  await new Promise((resolve) => setImmediate(resolve));
  assert.deepEqual(lines, []);
});
