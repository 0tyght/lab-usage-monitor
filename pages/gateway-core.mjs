export const RUNTIME_URL = 'https://raw.githubusercontent.com/0tyght/lab-usage-monitor/codex/online-runtime/runtime.json';

export function validateRuntime(value) {
  if (!value || value.schemaVersion !== 1 || !['online', 'offline'].includes(value.status)) throw new Error('config');
  if (value.status === 'offline') return { status: 'offline' };
  if (!/^[a-f0-9]{32}$/.test(value.gatewayId || '')) throw new Error('config');
  if (typeof value.origin !== 'string' || !/^https:\/\/[a-z0-9]+(?:-[a-z0-9]+)*\.trycloudflare\.com\/?$/.test(value.origin)) throw new Error('config');
  return { status: 'online', origin: new URL(value.origin).origin, gatewayId: value.gatewayId };
}

export function classQuery(search) {
  const input = new URLSearchParams(search);
  if (!input.has('page') && !input.has('token')) return '';
  if (input.getAll('page').length !== 1 || !['student-checkin','room-checkin'].includes(input.get('page'))
      || input.getAll('token').length !== 1 || !/^[a-f0-9]{32}$/.test(input.get('token') || '')) throw new Error('link');
  // Never forward redirect destinations, health parameters, or unrelated input.
  return '?' + new URLSearchParams({ page: input.get('page'), token: input.get('token') });
}

export async function checkConnection({ search = '', fetcher = fetch, signal } = {}) {
  const query = classQuery(search);
  const options = { cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer', signal };
  let response;
  try { response = await fetcher(`${RUNTIME_URL}?t=${Date.now()}`, options); } catch { throw new Error('network'); }
  if (response.status === 404) return { status: 'unconfigured' };
  if (!response.ok) throw new Error('network');
  let runtime;
  try { runtime = validateRuntime(await response.json()); } catch { throw new Error('config'); }
  if (runtime.status === 'offline') return runtime;
  try {
    const health = await fetcher(`${runtime.origin}/?health=1`, options);
    if (!health.ok) throw new Error('offline');
    const data = await health.json();
    if (data.status !== 'ok' || data.service !== 'lums' || data.gatewayId !== runtime.gatewayId) throw new Error('offline');
  } catch { return { status: 'offline' }; }
  return { status: 'ready', destination: runtime.origin + '/' + query };
}
