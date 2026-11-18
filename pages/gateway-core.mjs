export const RUNTIME_URL = 'https://raw.githubusercontent.com/0tyght/lab-usage-monitor/codex/online-runtime/runtime.json';
export const RUNTIME_FALLBACK_URL = 'https://api.github.com/repos/0tyght/lab-usage-monitor/contents/runtime.json?ref=codex%2Fonline-runtime';

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
  const page = input.get('page');
  const tokenPattern = page === 'room-checkin' ? /^(?:(?:room-)?[a-f0-9]{32}|lums-(?:cpe-101|cpe-204|ee-301|iot-401|cpe-305)-demo)$/ : /^[a-f0-9]{32}$/;
  if (input.getAll('page').length !== 1 || !['student-checkin','room-checkin'].includes(page)
      || input.getAll('token').length !== 1 || !tokenPattern.test(input.get('token') || '')) throw new Error('link');
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
  const healthy = async candidate => {
    try {
      const health = await fetcher(`${candidate.origin}/?health=1`, options);
      if (!health.ok) return false;
      const data = await health.json();
      return data.status === 'ok' && data.service === 'lums' && data.gatewayId === candidate.gatewayId;
    } catch { return false; }
  };
  if (runtime.status === 'online' && await healthy(runtime)) return { status: 'ready', destination: runtime.origin + '/' + query };
  // GitHub's raw CDN can serve the previous tunnel briefly after a restart.
  // Consult the branch directly only on failure, then validate it and its health.
  try {
    const fresh = await fetcher(RUNTIME_FALLBACK_URL, { ...options, headers: { Accept: 'application/vnd.github.raw+json' } });
    if (!fresh.ok) return { status: 'offline' };
    const current = validateRuntime(await fresh.json());
    if (current.status === 'online' && await healthy(current)) return { status: 'ready', destination: current.origin + '/' + query };
  } catch { /* Unavailable or rate-limited fallback is still offline. */ }
  return { status: 'offline' };
}
