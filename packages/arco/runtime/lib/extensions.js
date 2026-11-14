'use strict';
// Arco Cloud extensions shipped inside a process app: app/.arco-cloud/<name>/extension.json. The runtime knows one
// generic kind, a "front": a process that listens where the app would and forwards to it. It knows nothing about
// what a front does.
const nodeFs = require('node:fs');
const path = require('node:path');

const DIRECTORY = '.arco-cloud';

// The first valid front under appDir/.arco-cloud, by directory name; the others are ignored. Never throws.
function findFront(appDir, log, fs = nodeFs) {
  const base = path.join(appDir, DIRECTORY);
  let names;
  try {
    names = fs.readdirSync(base).sort();
  } catch {
    return null;
  }
  let found = null;
  for (const name of names) {
    const dir = path.join(base, name);
    let command;
    try {
      const manifest = JSON.parse(fs.readFileSync(path.join(dir, 'extension.json'), 'utf8'));
      if (!manifest || typeof manifest !== 'object' || !manifest.front) continue;
      command = manifest.front.command;
      if (!Array.isArray(command) || command.length === 0 || !command.every((part) => typeof part === 'string' && part !== '')) {
        throw new Error('front.command must be a non-empty array of strings');
      }
    } catch (error) {
      if (error && error.code === 'ENOENT') continue; // not an extension directory
      log('extension_failed', { extension: name, error: String(error && error.message || error) });
      continue;
    }
    if (found) {
      log('extension_ignored', { extension: name });
      continue;
    }
    found = { name, dir, command };
  }
  return found;
}

module.exports = { findFront, DIRECTORY };
