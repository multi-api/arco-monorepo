'use strict';
// worker.js against a real local HTTP server standing in for the customer's process.
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const path = require('node:path');

const { createHandler } = require(path.join(__dirname, '..', 'worker.js'));

async function app(handle) {
  const requests = [];
  const server = http.createServer((req, res) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const request = { method: req.method, url: req.url, headers: req.headers, body: Buffer.concat(chunks).toString() };
      requests.push(request);
      handle(request, res, requests.length);
    });
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  return { server, requests, port: server.address().port, close: () => { server.closeAllConnections(); server.close(); } };
}

function record(id, body, extra = {}) {
  return {
    eventSource: 'aws:sqs',
    messageId: id,
    body,
    attributes: { ApproximateReceiveCount: '3' },
    messageAttributes: {},
    ...extra,
  };
}

const ENV_KEYS = ['ARCO_WORKER_QUEUE', 'ARCO_QUEUE_ROUTE', 'ARCO_QUEUE_FIFO', 'ARCO_SCHEDULES'];

async function withEnv(values, fn) {
  const saved = Object.fromEntries(ENV_KEYS.map((k) => [k, process.env[k]]));
  for (const k of ENV_KEYS) delete process.env[k];
  Object.assign(process.env, values);
  try { return await fn(); } finally {
    for (const k of ENV_KEYS) { if (saved[k] === undefined) delete process.env[k]; else process.env[k] = saved[k]; }
  }
}

const context = (ms = 60000) => ({ getRemainingTimeInMillis: () => ms });
const queueEnv = { ARCO_WORKER_QUEUE: 'emails', ARCO_QUEUE_ROUTE: '/_arco/queues/emails' };

test('delivers records in order and reports the failed ones', async () => {
  const statuses = [204, 500, 204];
  const a = await app((req, res, n) => { res.statusCode = statuses[n - 1]; res.end(n === 2 ? 'boom' : ''); });
  const logs = [];
  const handler = createHandler({ port: a.port, start: async () => ({}), log: (p, d) => logs.push([p, d]) });
  await withEnv(queueEnv, async () => {
    const result = await handler({ Records: [
      record('m1', '{"a":1}', { messageAttributes: { contentType: { stringValue: 'application/json', dataType: 'String' } } }),
      record('m2', 'raw'),
      record('m3', 'x'),
    ] }, context());
    assert.deepEqual(result, { batchItemFailures: [{ itemIdentifier: 'm2' }] });
  });
  assert.equal(a.requests.length, 3);
  const [first, second] = a.requests;
  assert.equal(first.method, 'POST');
  assert.equal(first.url, '/_arco/queues/emails');
  assert.equal(first.body, '{"a":1}');
  assert.equal(first.headers['content-type'], 'application/json');
  assert.equal(first.headers['x-arco-queue'], 'emails');
  assert.equal(first.headers['x-arco-message-id'], 'm1');
  assert.equal(first.headers['x-arco-receive-count'], '3');
  assert.equal(second.headers['content-type'], 'application/octet-stream');
  assert.ok(logs.some(([, d]) => d.status === 500 && d.body === 'boom'), 'failure log keeps status and body');
  a.close();
});

test('a record that never answers fails before the invocation times out, the next one is still sent', async () => {
  const a = await app((req, res, n) => { if (n !== 1) res.end(); });
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {} });
  const started = Date.now();
  await withEnv(queueEnv, async () => {
    const result = await handler({ Records: [record('m1', 'a'), record('m2', 'b')] }, context(2500));
    assert.deepEqual(result, { batchItemFailures: [{ itemIdentifier: 'm1' }] });
  });
  assert.ok(Date.now() - started < 2500, 'failed within the invocation time');
  assert.equal(a.requests.length, 2);
  a.close();
});

test('FIFO: after the first failure the rest is failed without being sent', async () => {
  const a = await app((req, res) => { res.statusCode = 500; res.end(); });
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {} });
  await withEnv({ ...queueEnv, ARCO_QUEUE_FIFO: '1' }, async () => {
    const result = await handler({ Records: [record('m1', 'a'), record('m2', 'b'), record('m3', 'c')] }, context());
    assert.deepEqual(result, { batchItemFailures: [{ itemIdentifier: 'm1' }, { itemIdentifier: 'm2' }, { itemIdentifier: 'm3' }] });
  });
  assert.equal(a.requests.length, 1);
  a.close();
});

test('connection refused fails every record', async () => {
  const a = await app(() => {});
  const port = a.port;
  a.close();
  const handler = createHandler({ port, start: async () => ({}), log: () => {} });
  await withEnv(queueEnv, async () => {
    const result = await handler({ Records: [record('m1', 'a'), record('m2', 'b')] }, context());
    assert.deepEqual(result, { batchItemFailures: [{ itemIdentifier: 'm1' }, { itemIdentifier: 'm2' }] });
  });
});

const schedules = { ARCO_SCHEDULES: JSON.stringify({ cleanup: '/_arco/schedule/cleanup' }) };

test('schedule: POSTs to the route with the schedule header', async () => {
  const a = await app((req, res) => { res.statusCode = 204; res.end(); });
  const handler = createHandler({ port: a.port, start: async () => ({}), log: () => {} });
  await withEnv(schedules, async () => {
    await handler({ arco: 'schedule', name: 'cleanup' }, context());
  });
  assert.equal(a.requests[0].method, 'POST');
  assert.equal(a.requests[0].url, '/_arco/schedule/cleanup');
  assert.equal(a.requests[0].headers['x-arco-schedule'], 'cleanup');
  assert.equal(a.requests[0].body, '');
  a.close();
});

test('schedule: a non-2xx throws and logs schedule_failed', async () => {
  const a = await app((req, res) => { res.statusCode = 500; res.end('nope'); });
  const logs = [];
  const handler = createHandler({ port: a.port, start: async () => ({}), log: (p, d) => logs.push([p, d]) });
  await withEnv(schedules, async () => {
    await assert.rejects(handler({ arco: 'schedule', name: 'cleanup' }, context()));
  });
  const failed = logs.find(([p]) => p === 'schedule_failed');
  assert.ok(failed);
  assert.equal(failed[1].name, 'cleanup');
  assert.equal(failed[1].status, 500);
  a.close();
});

test('schedule: an unknown name throws', async () => {
  const handler = createHandler({ port: 1, start: async () => ({}), log: () => {} });
  await withEnv(schedules, async () => {
    await assert.rejects(handler({ arco: 'schedule', name: 'other' }, context()), /other/);
  });
});

test('the process is started once across invocations', async () => {
  const a = await app((req, res) => { res.statusCode = 204; res.end(); });
  let starts = 0;
  const handler = createHandler({ port: a.port, start: async () => { starts += 1; return {}; }, log: () => {} });
  await withEnv(schedules, async () => {
    await handler({ arco: 'schedule', name: 'cleanup' }, context());
    await handler({ arco: 'schedule', name: 'cleanup' }, context());
  });
  assert.equal(starts, 1);
  a.close();
});

test('an invocation that finds the process dead fails', async () => {
  const handler = createHandler({ port: 1, start: async () => ({ exitCode: 1 }), log: () => {} });
  await withEnv(schedules, async () => {
    await assert.rejects(handler({ arco: 'schedule', name: 'cleanup' }, context()), /exited/);
  });
});
