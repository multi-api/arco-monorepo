'use strict';
// The web function's invocation count in Laravel direct mode: Caddy writes one JSON access log line per response to
// its arco_count logger (lib/caddyfile.js), which sends it here over TCP. Each '\n' is one response; the lines are
// never parsed. Nothing here throws into the runtime: a counter that fails to listen only loses the count.
const net = require('node:net');

const HOST = '127.0.0.1';
const PORT = 2021;
const NEWLINE = 10;

/** Starts the counter; onLine() is called once per line received. Returns the net.Server. */
function startCaddyCount(onLine, { host = HOST, port = PORT, onError = () => {} } = {}) {
  const server = net.createServer((socket) => {
    socket.on('error', () => {});
    socket.on('data', (chunk) => {
      for (let at = chunk.indexOf(NEWLINE); at !== -1; at = chunk.indexOf(NEWLINE, at + 1)) {
        try {
          onLine();
        } catch {
          // Telemetry never fails a request.
        }
      }
    });
  });
  server.on('error', (error) => {
    try {
      onError(error);
    } catch {
      // Same.
    }
  });
  server.listen(port, host);
  return server;
}

module.exports = { HOST, PORT, startCaddyCount };
