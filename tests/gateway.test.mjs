import test from 'node:test';
import assert from 'node:assert/strict';
import { checkConnection, classQuery, validateRuntime, RUNTIME_URL, RUNTIME_FALLBACK_URL } from '../pages/gateway-core.mjs';
import { readEnvironment, runtimeState, PAGES_URL } from '../scripts/online-lib.mjs';

const token = 'a'.repeat(32);
const runtime = { schemaVersion: 1, status: 'online', origin: 'https://unit-test-lums.trycloudflare.com', gatewayId: token };
const response = (data, status = 200) => ({ ok: status === 200, status, json: async () => data });

test('permanent room QR survives tunnel changes without leaking other query fields', async () => {
  assert.equal(classQuery(`?page=room-checkin&token=${token}&person_name=private`), `?page=room-checkin&token=${token}`);
  for (const query of [`?page=room-checkin&token=${token}&token=${token}`, '?page=room-checkin&token=bad', `?page=room-checkin&page=student-checkin&token=${token}`]) assert.throws(() => classQuery(query));
  for (const name of ['old-room', 'new-room']) {
    let count=0;
    const result=await checkConnection({search:`?page=room-checkin&token=${token}`,fetcher:async()=>response(++count===1?{...runtime,origin:`https://${name}.trycloudflare.com`}:{status:'ok',service:'lums',gatewayId:token})});
    assert.equal(result.destination,`https://${name}.trycloudflare.com/?page=room-checkin&token=${token}`);
  }
});

test('offline and online runtime validation', () => {
  assert.deepEqual(validateRuntime(runtime), { status: 'online', origin: runtime.origin, gatewayId: token });
  assert.deepEqual(validateRuntime({ schemaVersion: 1, status: 'offline' }), { status: 'offline' });
  assert.equal(runtimeState('offline').origin, '');
});
for (const origin of ['http://unit-test-lums.trycloudflare.com', 'https://evil.example', 'https://trycloudflare.com.evil.example', 'https://unit-test-lums.trycloudflare.com@evil.example', 'https://unit-test-lums.trycloudflare.com/path', 'https://unit-test-lums.trycloudflare.com/?next=x', 'https://unit-test-lums.trycloudflare.com:444', 'https://unit-test-lums.trycloudflare.com/#x', 'https://127.0.0.1', '//unit-test-lums.trycloudflare.com', 'javascript:alert(1)', 'https://user:pass@unit-test-lums.trycloudflare.com']) {
  test(`reject unsafe destination: ${origin}`, () => assert.throws(() => validateRuntime({ ...runtime, origin })));
}
test('reject malformed runtime fields', () => {
  for (const bad of [null, {}, { ...runtime, schemaVersion: 2 }, { ...runtime, status: 'unknown' }, { ...runtime, gatewayId: 'bad' }]) assert.throws(() => validateRuntime(bad));
});
test('keep class QR token and remove unrelated redirect input', () => {
  assert.equal(classQuery(''), '');
  assert.equal(classQuery('?next=https://evil.example'), '');
  assert.equal(classQuery(`?page=student-checkin&token=${token}&next=https://evil.example&health=1`), `?page=student-checkin&token=${token}`);
});
for (const query of ['?token=bad', `?page=login&token=${token}`, `?page=student-checkin&token=${token}&token=${token}`, `?page=student-checkin&page=login&token=${token}`, '?page=student-checkin', '?page=../../evil']) {
  test(`reject invalid class link: ${query}`, () => assert.throws(() => classQuery(query)));
}
test('ready only after endpoint identity and health match', async () => {
  const requests = [];
  const fetcher = async (url, options) => {
    requests.push(url);
    assert.equal(options.credentials, 'omit');
    assert.equal(options.referrerPolicy, 'no-referrer');
    assert.equal(options.cache, 'no-store');
    return response(requests.length === 1 ? runtime : { status: 'ok', service: 'lums', gatewayId: token });
  };
  const result = await checkConnection({ search: `?page=student-checkin&token=${token}`, fetcher });
  assert.equal(result.destination, runtime.origin + `/?page=student-checkin&token=${token}`);
  assert.ok(requests[0].startsWith(RUNTIME_URL));
  assert.ok(!requests[0].includes(token));
  assert.equal(requests[1], runtime.origin + '/?health=1');
});
test('an old QR follows the new tunnel without changing its token', async () => {
  for (const name of ['old-lums', 'new-lums']) {
    let count = 0;
    const result = await checkConnection({ search: `?page=student-checkin&token=${token}`, fetcher: async () => response(++count === 1 ? { ...runtime, origin: `https://${name}.trycloudflare.com` } : { status: 'ok', service: 'lums', gatewayId: token }) });
    assert.equal(result.destination, `https://${name}.trycloudflare.com/?page=student-checkin&token=${token}`);
  }
});
test('stale raw CDN falls back to the current validated branch and preserves QR', async () => {
  const fresh={...runtime,origin:'https://fresh-room.trycloudflare.com'};
  const calls=[];
  const result=await checkConnection({search:`?page=room-checkin&token=${token}`,fetcher:async (url,options)=>{
    calls.push(url);
    if (url.startsWith(RUNTIME_URL)) return response(runtime);
    if (url===runtime.origin+'/?health=1') throw new Error('old tunnel unavailable');
    if (url===RUNTIME_FALLBACK_URL) { assert.equal(options.headers.Accept,'application/vnd.github.raw+json'); return response(fresh); }
    if (url===fresh.origin+'/?health=1') return response({status:'ok',service:'lums',gatewayId:token});
    assert.fail('unexpected request');
  }});
  assert.equal(result.destination,`${fresh.origin}/?page=room-checkin&token=${token}`);
  assert.equal(calls.length,4);
});
test('fallback cannot redirect to an untrusted or unhealthy server', async () => {
  for(const bad of [{...runtime,origin:'https://evil.example'}, {...runtime,origin:'https://other.trycloudflare.com',gatewayId:'b'.repeat(32)}]) {
    const result=await checkConnection({fetcher:async url=>{
      if(url.startsWith(RUNTIME_URL)) return response(runtime);
      if(url===RUNTIME_FALLBACK_URL) return response(bad);
      if(url===bad.origin+'/?health=1') return response({status:'ok',service:'lums',gatewayId:token});
      throw new Error('unreachable');
    }});
    assert.equal(result.status,'offline');
  }
});
test('offline status checks branch but never probes a destination', async () => {
  const urls=[];
  assert.equal((await checkConnection({ fetcher: async url => { urls.push(url); return response({ schemaVersion: 1, status: 'offline' }); } })).status, 'offline');
  assert.equal(urls.length, 2);
  assert.equal(urls[1], RUNTIME_FALLBACK_URL);
});
test('stale offline raw cache can discover newly started server', async () => {
  const result=await checkConnection({fetcher:async url=>{
    if(url.startsWith(RUNTIME_URL)) return response({schemaVersion:1,status:'offline'});
    if(url===RUNTIME_FALLBACK_URL) return response(runtime);
    return response({status:'ok',service:'lums',gatewayId:token});
  }});
  assert.equal(result.destination,runtime.origin+'/');
});
test('runtime absent / network failure / malformed configuration', async () => {
  assert.equal((await checkConnection({ fetcher: async () => response({}, 404) })).status, 'unconfigured');
  await assert.rejects(checkConnection({ fetcher: async () => { throw new Error(); } }), /network/);
  await assert.rejects(checkConnection({ fetcher: async () => response({}, 500) }), /network/);
  await assert.rejects(checkConnection({ fetcher: async () => response({}) }), /config/);
});
for (const body of [{ status: 'ok', service: 'other', gatewayId: token }, { status: 'ok', service: 'lums', gatewayId: 'b'.repeat(32) }, { status: 'unavailable', service: 'lums', gatewayId: token }]) {
  test(`unhealthy/mismatched server stays offline: ${JSON.stringify(body)}`, async () => {
    let count = 0;
    const result = await checkConnection({ fetcher: async () => response(++count === 1 ? runtime : body) });
    assert.equal(result.status, 'offline');
  });
}
test('disconnected tunnel and HTTP 5xx are offline', async () => {
  for (const throws of [true, false]) {
    let count = 0;
    const result = await checkConnection({ fetcher: async () => { if (++count === 1) return response(runtime); if (throws) throw new Error(); return response({}, 502); } });
    assert.equal(result.status, 'offline');
  }
});
test('invalid class link makes no network request', async () => {
  await assert.rejects(checkConnection({ search: '?token=bad', fetcher: async () => { assert.fail('must not fetch'); } }), /link/);
});
test('environment validates stable URL and strong bootstrap password', () => {
  const env = `APP_URL=${PAGES_URL.slice(0, -1)}\nLUMS_GATEWAY_ORIGIN=https://0tyght.github.io\nLUMS_GATEWAY_ID=${token}\nLUMS_ADMIN_EMAIL=admin@example.invalid\nLUMS_ADMIN_PASSWORD=${'c'.repeat(48)}\n`;
  assert.equal(readEnvironment(env).LUMS_GATEWAY_ID, token);
  assert.throws(() => readEnvironment(env.replace('c'.repeat(48), 'admin123')));
  assert.throws(() => readEnvironment(env.replace(PAGES_URL.slice(0, -1), 'https://evil.example')));
  assert.throws(() => readEnvironment(env + 'LUMS_GATEWAY_ID=duplicate'));
});
