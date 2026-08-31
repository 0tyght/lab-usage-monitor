import { existsSync } from 'node:fs';
import { COMPOSE, ENV_FILE, PAGES_URL, assertRepository, command, delay, ensureEnvironment, healthy, localRuntime, publishRuntime, runtimeState } from './online-lib.mjs';

const action = process.argv[2];
const compose = (...args) => command('docker', [...COMPOSE, ...args], { stream: true });

async function start() {
  assertRepository();
  try { await command('docker', ['info', '--format', '{{.ServerVersion}}']); }
  catch { throw new Error('Docker is not ready. Open Docker Desktop, wait for the engine, then run start-online.ps1 again.'); }
  const { values, created } = ensureEnvironment();
  if (created) console.log('Created a private administrator password in .env.online. It is NOT admin123. Keep this file private.');
  const previous = localRuntime();
  if (previous?.status === 'online' && previous.gatewayId === values.LUMS_GATEWAY_ID
      && await healthy('http://127.0.0.1:8088', values.LUMS_GATEWAY_ID) && await healthy(previous.origin, values.LUMS_GATEWAY_ID)) {
    await compose('exec', '-T', 'app', 'php', 'scripts/online-preflight.php');
    publishRuntime(previous);
    console.log(`LUMS is already online: ${PAGES_URL}`);
    return;
  }
  let started = false;
  try {
    console.log('Building and starting LUMS (first start can take a few minutes)...');
    started = true;
    await compose('up', '--build', '-d', '--wait', '--wait-timeout', '120', 'app');
    await compose('exec', '-T', 'app', 'php', 'scripts/online-preflight.php');
    if (!await healthy('http://127.0.0.1:8088', values.LUMS_GATEWAY_ID)) throw new Error('Local health check did not match this LUMS instance.');
    console.log('Opening a free temporary HTTPS tunnel...');
    await compose('up', '-d', '--force-recreate', 'tunnel');
    let origin = '';
    for (let attempt = 0; attempt < 45; attempt++) {
      const logs = await command('docker', [...COMPOSE, 'logs', '--no-color', '--tail', '120', 'tunnel']);
      origin = logs.match(/https:\/\/[a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com\b/)?.[0] || '';
      if (origin && await healthy(origin, values.LUMS_GATEWAY_ID)) break;
      await delay(2000);
    }
    if (!origin || !await healthy(origin, values.LUMS_GATEWAY_ID)) throw new Error('The tunnel is not reachable yet. Check Docker tunnel logs and your Internet connection.');
    const state = runtimeState('online', origin, values.LUMS_GATEWAY_ID);
    publishRuntime(state);
    console.log(`LUMS is ready: ${PAGES_URL}`);
    console.log(`Current HTTPS server: ${origin}`);
    console.log('Sign in with admin@lums.local and the private password in .env.online (not admin123).');
    console.log('Keep this computer awake. Stop safely with .\\stop-online.ps1; saved records are preserved.');
  } catch (error) {
    if (started) {
      try { await compose('stop', 'tunnel', 'app'); } catch { console.error('Could not stop containers automatically. Run stop-online.ps1.'); }
    }
    try { publishRuntime(runtimeState('offline')); } catch { console.error('Could not publish offline status; the gateway will detect failed connectivity.'); }
    throw error;
  }
}

async function stop() {
  let failure;
  if (existsSync(ENV_FILE)) {
    try { await compose('stop', 'tunnel', 'app'); } catch (error) { failure = error; }
  }
  try { publishRuntime(runtimeState('offline')); } catch (error) { failure ||= error; }
  if (failure) throw failure;
  console.log('LUMS is offline. Database and Docker volume are preserved. Other projects are unchanged.');
}

try {
  if (Number(process.versions.node.split('.')[0]) < 22) throw new Error('Node.js 22 or newer is required.');
  if (action === 'start') await start();
  else if (action === 'stop') await stop();
  else if (action === 'publish-offline') { publishRuntime(runtimeState('offline')); console.log('Permanent gateway marked offline.'); }
  else if (action === 'status') {
    const state = localRuntime();
    const online = state?.status === 'online' && await healthy(state.origin, state.gatewayId);
    console.log(online ? `LUMS online: ${PAGES_URL}` : 'LUMS is offline or not yet configured.');
  } else throw new Error('Usage: node scripts/online.mjs start|stop|status|publish-offline');
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
