import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { validateRuntime } from '../pages/gateway-core.mjs';

export const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
export const PAGES_URL = 'https://0tyght.github.io/lab-usage-monitor/';
export const REMOTE = 'https://github.com/0tyght/lab-usage-monitor.git';
export const RUNTIME_BRANCH = 'codex/online-runtime';
export const STATE_DIR = join(ROOT, 'storage', 'online');
export const ENV_FILE = join(ROOT, '.env.online');
export const COMPOSE = ['compose', '--project-name', 'lums-online', '-f', join(ROOT, 'compose.online.yaml')];

export function git(args, input) {
  const result = spawnSync('git', ['-C', ROOT, ...args], { input, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024 });
  if (result.error || result.status !== 0) throw new Error(`Git ${args[0]} failed: ${result.error?.message || result.stderr.trim()}`);
  return result.stdout.trim();
}

export function command(program, args, { stream = false } = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(program, args, { cwd: ROOT, windowsHide: true, stdio: stream ? 'inherit' : ['ignore', 'pipe', 'pipe'] });
    let output = '';
    let errors = '';
    child.stdout?.on('data', (data) => { output += data; });
    child.stderr?.on('data', (data) => { errors += data; });
    child.on('error', reject);
    child.on('close', (code) => code === 0 ? resolve(output.trim()) : reject(new Error(`${program} failed (${code}): ${errors.trim()}`)));
  });
}

export function assertRepository() {
  const remote = git(['remote', 'get-url', '--push', 'origin']);
  if (remote !== REMOTE && remote !== 'git@github.com:0tyght/lab-usage-monitor.git') throw new Error('origin must point to 0tyght/lab-usage-monitor; refusing to publish elsewhere.');
  if (!git(['config', 'user.name']) || !git(['config', 'user.email'])) throw new Error('Configure Git user.name and user.email first.');
}

export function readEnvironment(text) {
  const values = {};
  for (const line of text.split(/\r?\n/)) {
    if (!line.trim() || line.trim().startsWith('#')) continue;
    const match = /^([A-Z_]+)=(.*)$/.exec(line);
    if (!match) throw new Error('Invalid .env.online line; expected KEY=value.');
    let value = match[2];
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) value = value.slice(1, -1);
    if (Object.hasOwn(values, match[1])) throw new Error('Duplicate .env.online setting.');
    values[match[1]] = value;
  }
  if (values.APP_URL !== PAGES_URL.slice(0, -1) || values.LUMS_GATEWAY_ORIGIN !== 'https://0tyght.github.io'
      || !/^[a-f0-9]{32}$/.test(values.LUMS_GATEWAY_ID || '')) throw new Error('Invalid permanent gateway configuration in .env.online.');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.LUMS_ADMIN_EMAIL || '') || (values.LUMS_ADMIN_PASSWORD || '').length < 12) throw new Error('Administrator email and a password of at least 12 characters are required.');
  return values;
}

export function ensureEnvironment() {
  mkdirSync(STATE_DIR, { recursive: true });
  let created = false;
  if (!existsSync(ENV_FILE)) {
    const contents = [
      '# Private local bootstrap credentials. Never commit or share this file.',
      '# Changing this password later does not reset an existing database account.',
      'APP_URL=' + PAGES_URL.slice(0, -1),
      'LUMS_GATEWAY_ORIGIN=https://0tyght.github.io',
      'LUMS_GATEWAY_ID=' + randomBytes(16).toString('hex'),
      'LUMS_ADMIN_NAME=ผู้ดูแลระบบ LUMS',
      'LUMS_ADMIN_EMAIL=admin@lums.local',
      'LUMS_ADMIN_PASSWORD=' + randomBytes(24).toString('hex'),
      '',
    ].join('\n');
    writeFileSync(ENV_FILE, contents, { flag: 'wx', mode: 0o600 });
    created = true;
  }
  return { values: readEnvironment(readFileSync(ENV_FILE, 'utf8')), created };
}

export function runtimeState(status, origin = '', gatewayId = '') {
  const value = { schemaVersion: 1, status, origin, gatewayId, updatedAt: new Date().toISOString() };
  validateRuntime(value);
  return value;
}

export function publishRuntime(value) {
  assertRepository();
  validateRuntime(value);
  // Use an isolated Git tree containing ONE public JSON file. Never stage the
  // working directory, copy the application's files, or extract Git credentials.
  const ref = `refs/heads/${RUNTIME_BRANCH}`;
  const remote = git(['ls-remote', 'origin', ref]);
  let parent = '';
  if (remote) {
    git(['fetch', '--no-tags', 'origin', RUNTIME_BRANCH]);
    parent = git(['rev-parse', 'FETCH_HEAD']);
  }
  const blob = git(['hash-object', '-w', '--stdin'], JSON.stringify(value, null, 2) + '\n');
  const tree = git(['mktree'], `100644 blob ${blob}\truntime.json\n`);
  const args = ['commit-tree', tree];
  if (parent) args.push('-p', parent);
  args.push('-m', `chore: LUMS server ${value.status}`);
  const commit = git(args);
  git(['push', 'origin', `${commit}:${ref}`]); // Fast-forward only; no forced updates.
  mkdirSync(STATE_DIR, { recursive: true });
  writeFileSync(join(STATE_DIR, 'runtime.json'), JSON.stringify(value, null, 2) + '\n');
  return commit;
}

export function localRuntime() {
  try { return JSON.parse(readFileSync(join(STATE_DIR, 'runtime.json'), 'utf8')); } catch { return null; }
}

export async function healthy(origin, gatewayId) {
  try {
    const result = await fetch(origin + '/?health=1', { signal: AbortSignal.timeout(5000), redirect: 'error' });
    if (!result.ok) return false;
    const data = await result.json();
    return data.status === 'ok' && data.service === 'lums' && data.gatewayId === gatewayId;
  } catch { return false; }
}

export const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
