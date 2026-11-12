import assert from 'node:assert/strict';
import { register } from 'node:module';
import { test } from 'node:test';

const encode = (source) => `data:text/javascript,${encodeURIComponent(source)}`;
const secureStoreStub = encode(`
const values = new Map();
let writeFailureKey = null;
export const WHEN_UNLOCKED_THIS_DEVICE_ONLY = 1;
export function failNextWrite(key) { writeFailureKey = key; }
export async function setItemAsync(key, value) {
  if (writeFailureKey === key) { writeFailureKey = null; throw new Error('storage unavailable'); }
  values.set(key, value);
}
export async function getItemAsync(key) { return values.get(key) ?? null; }
export async function deleteItemAsync(key) { values.delete(key); }
`);
const reactNativeStub = encode(`export const Platform = { OS: 'android' };`);
const expoFetchStub = encode(`export const fetch = (...args) => globalThis.fetch(...args);`);
const expoConstantsStub = encode(`
let lanDemoEnabled = false;
const Constants = { get expoConfig() { return { extra: { ingressLanDemo: lanDemoEnabled } }; } };
export function setLanDemoEnabled(enabled) { lanDemoEnabled = enabled; }
export default Constants;
`);
const loader = encode(`
import { existsSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
const mappings = {
  'expo-secure-store': ${JSON.stringify(secureStoreStub)},
  'react-native': ${JSON.stringify(reactNativeStub)},
  'expo/fetch': ${JSON.stringify(expoFetchStub)},
  'expo-constants': ${JSON.stringify(expoConstantsStub)},
};
export async function resolve(specifier, context, nextResolve) {
  if (mappings[specifier]) return { url: mappings[specifier], shortCircuit: true };
  if (specifier.startsWith('.') && !/\\.[a-z]+$/i.test(specifier) && context.parentURL) {
    const base = fileURLToPath(new URL(specifier, context.parentURL));
    for (const candidate of [base + '.ts', base + '.tsx', base + '/index.ts']) {
      if (existsSync(candidate)) return { url: pathToFileURL(candidate).href, shortCircuit: true };
    }
  }
  return nextResolve(specifier, context);
}
`);

register(loader, import.meta.url);

const nodeSession = await import('../src/services/node-session.ts');
const nodeState = await import('../src/services/node-staff-state.ts');
const SecureStore = await import('expo-secure-store');
const { setLanDemoEnabled } = await import('expo-constants');
const { Platform } = await import('react-native');
const staffUser = { id: 7, name: 'Event Staff', email: 'staff@example.test', student_id: null };

test('Event Node authentication and pairing allow private HTTP only in the LAN demo build', async (t) => {
  const originalFetch = globalThis.fetch;

  try {
    await t.test('production builds accept HTTPS and reject HTTP', () => {
      setLanDemoEnabled(false);
      assert.equal(nodeSession.normalizeNodeUrl('https://node.example/api/v1/'), 'https://node.example/api/v1');
      for (const url of [
        'http://localhost:8000/api/v1',
        'http://192.168.1.40/api/v1',
        'https://user:pass@node.example/api/v1',
        'https://node.example/api/v1?x=1',
        'https://node.example/api/v1#x',
        'https://node.example/other',
      ]) {
        assert.throws(() => nodeSession.normalizeNodeUrl(url));
      }
    });

    await t.test('LAN demo accepts a private IPv4 address and defaults to port 8000', () => {
      setLanDemoEnabled(true);
      assert.equal(nodeSession.normalizeNodeUrl('192.168.55.158'), 'http://192.168.55.158:8000/api/v1');
      assert.equal(nodeSession.normalizeNodeUrl('http://192.168.55.158:8080/api/v1'), 'http://192.168.55.158:8080/api/v1');
      for (const url of [
        'http://8.8.8.8:8000/api/v1',
        'http://node.example:8000/api/v1',
        'http://localhost:8000/api/v1',
        'http://192.168.55.158:8000/api/v1?token=secret',
      ]) {
        assert.throws(() => nodeSession.normalizeNodeUrl(url));
      }
      setLanDemoEnabled(false);
    });

    await t.test('discards a previously stored HTTP Node session', async () => {
      await SecureStore.setItemAsync('ingress_node_staff_session_v1', JSON.stringify({
        url: 'http://192.168.1.40/api/v1',
        token: 'legacy-http-token',
        user: staffUser,
      }));
      assert.equal(await nodeSession.getNodeSession(), null);
      assert.equal(await SecureStore.getItemAsync('ingress_node_staff_session_v1'), null);
    });

    await t.test('rejects malformed and insecure one-time pairing codes', () => {
      const validCode = {
        v: 1,
        url: 'https://node.example/api/v1',
        node: 'node-1',
        token: 'a'.repeat(64),
        expires: '2030-01-01T00:00:00Z',
      };
      assert.deepEqual(nodeSession.parseNodePairingCode(JSON.stringify(validCode)), validCode);
      for (const raw of [
        'not-json',
        JSON.stringify({ ...validCode, url: 'http://node.example/api/v1' }),
        JSON.stringify({ ...validCode, token: 'invalid' }),
        JSON.stringify({ ...validCode, extra: true }),
      ]) {
        assert.throws(() => nodeSession.parseNodePairingCode(raw), /valid Event Node pairing code/);
      }
      setLanDemoEnabled(true);
      assert.equal(nodeSession.parseNodePairingCode(JSON.stringify({
        ...validCode,
        url: 'http://192.168.55.158:8000/api/v1',
      })).url, 'http://192.168.55.158:8000/api/v1');
      setLanDemoEnabled(false);
    });

    await t.test('login, requests, and logout use only the stored node session', async () => {
      const calls = [];
      globalThis.fetch = async (url, options) => {
        calls.push({ url: String(url), options });
        if (String(url).endsWith('/auth/node-staff/login')) {
          return new Response(JSON.stringify({ token: 'node-token', user: staffUser, node_staff: true }), { status: 200 });
        }
        if (String(url).endsWith('/me')) return new Response(JSON.stringify({ user: staffUser }), { status: 200 });
        return new Response('{}', { status: 200 });
      };

      const session = await nodeSession.loginNodeStaff('https://node.example/api/v1', ' staff@example.test ', 'local-password');
      assert.equal(session.token, 'node-token');
      assert.equal((await nodeSession.getNodeSession())?.url, 'https://node.example/api/v1');
      assert.equal(calls[0].url, 'https://node.example/api/v1/auth/node-staff/login');
      assert.equal(calls[0].options.credentials, 'omit');
      assert.equal(calls[0].options.redirect, 'error');
      assert.equal(calls[0].options.headers.Authorization, undefined);

      await nodeSession.nodeRequest('/me', {
        headers: { Authorization: 'Bearer forged-token' },
        credentials: 'include',
        redirect: 'follow',
      });
      assert.equal(calls[1].options.headers.Authorization, 'Bearer node-token');
      assert.equal(calls[1].options.credentials, 'omit');
      assert.equal(calls[1].options.redirect, 'error');
      assert.equal(calls[1].url.startsWith('https://node.example/'), true);

      await nodeSession.logoutNodeStaff();
      assert.equal((await nodeSession.getNodeSession()), null);
      assert.equal(calls[2].url, 'https://node.example/api/v1/auth/logout');
      assert.equal(calls[2].options.headers.Authorization, 'Bearer node-token');
      assert.equal(await SecureStore.getItemAsync('ingress_node_staff_session_v1'), null);
    });

    await t.test('pairing verifies the returned Node identity before saving its token', async () => {
      const pairing = {
        v: 1,
        url: 'https://node.example/api/v1',
        node: 'node-1',
        token: 'b'.repeat(64),
        expires: '2030-01-01T00:00:00Z',
      };
      const calls = [];
      globalThis.fetch = async (url, options) => {
        calls.push({ url: String(url), options });
        return new Response(JSON.stringify({ token: 'paired-token', user: staffUser, node_staff: true, node_id: 'node-1' }), { status: 200 });
      };

      const session = await nodeSession.pairNodeStaff(pairing);
      assert.equal(session.token, 'paired-token');
      assert.equal(calls[0].url, 'https://node.example/api/v1/auth/node-staff/pair');
      assert.equal(calls[0].options.credentials, 'omit');
      assert.equal(calls[0].options.redirect, 'error');
      assert.equal(calls[0].options.headers.Authorization, undefined);
      assert.equal((await nodeSession.getNodeSession())?.token, 'paired-token');
      assert.equal((await SecureStore.getItemAsync('ingress_node_staff_session_v1')).includes(pairing.token), false);
    });

    await t.test('pairing rejects a different Node identity and revokes the issued token', async () => {
      const pairing = {
        v: 1,
        url: 'https://node.example/api/v1',
        node: 'node-1',
        token: 'c'.repeat(64),
        expires: '2030-01-01T00:00:00Z',
      };
      const calls = [];
      globalThis.fetch = async (url, options) => {
        calls.push({ url: String(url), options });
        if (String(url).endsWith('/auth/node-staff/pair')) {
          return new Response(JSON.stringify({ token: 'wrong-node-token', user: staffUser, node_staff: true, node_id: 'node-2' }), { status: 200 });
        }
        return new Response('{}', { status: 200 });
      };

      await assert.rejects(nodeSession.pairNodeStaff(pairing), /identity did not match/);
      assert.equal(calls[1].url, 'https://node.example/api/v1/auth/logout');
      assert.equal(calls[1].options.headers.Authorization, 'Bearer wrong-node-token');
      assert.equal((await nodeSession.getNodeSession())?.token, 'paired-token');
    });

    await t.test('failed secure storage revokes the new token and preserves the prior session', async () => {
      let revokedToken;
      globalThis.fetch = async (url, options) => {
        if (String(url).endsWith('/auth/node-staff/login')) {
          return new Response(JSON.stringify({ token: 'unsaved-node-token', user: staffUser, node_staff: true }), { status: 200 });
        }
        if (String(url).endsWith('/auth/logout')) revokedToken = options.headers.Authorization;
        return new Response('{}', { status: 200 });
      };
      SecureStore.failNextWrite('ingress_node_staff_session_v1');

      await assert.rejects(
        nodeSession.loginNodeStaff('https://node.example/api/v1', 'staff@example.test', 'local-password'),
        /Secure storage is unavailable/,
      );
      assert.equal(revokedToken, 'Bearer unsaved-node-token');
      assert.equal((await nodeSession.getNodeSession())?.token, 'paired-token');
    });

    await t.test('a node 401 clears its matching local token and the context starts inactive', async () => {
      let unauthorizedToken;
      nodeSession.setNodeUnauthorizedHandler((token) => { unauthorizedToken = token; });
      globalThis.fetch = async () => new Response('{"message":"Unauthenticated"}', { status: 401 });

      await assert.rejects(nodeSession.nodeRequest('/me'), (error) => error.status === 401 && error instanceof Error);
      assert.equal(unauthorizedToken, 'paired-token');
      assert.equal(await nodeSession.getNodeSession(), null);
      assert.deepEqual(nodeState.INITIAL_NODE_STAFF_STATE, { session: null, active: false });
      nodeSession.setNodeUnauthorizedHandler(null);
    });

    await t.test('a delayed unauthorized response cannot deactivate a newer session', () => {
      const active = { session: { url: 'https://node.example/api/v1', token: 'newer-token', user: staffUser }, active: true };
      assert.equal(nodeState.nodeStaffReducer(active, { type: 'unauthorized', token: 'older-token' }), active);
      assert.deepEqual(nodeState.nodeStaffReducer(active, { type: 'unauthorized', token: 'newer-token' }), { session: null, active: false });
    });

    await t.test('a delayed Node 401 cannot clear a newer secure session', async () => {
      let loginNumber = 0;
      let releaseOldRequest;
      let unauthorizedCalled = false;
      nodeSession.setNodeUnauthorizedHandler(() => { unauthorizedCalled = true; });
      globalThis.fetch = async (url) => {
        if (String(url).endsWith('/auth/node-staff/login')) {
          loginNumber++;
          return new Response(JSON.stringify({ token: `node-token-${loginNumber}`, user: staffUser, node_staff: true }), { status: 200 });
        }
        if (String(url).endsWith('/me')) return new Promise((resolve) => { releaseOldRequest = resolve; });
        return new Response('{}', { status: 200 });
      };

      await nodeSession.loginNodeStaff('https://node-one.example/api/v1', 'staff@example.test', 'local-password');
      const oldRequest = nodeSession.nodeRequest('/me').catch(() => undefined);
      while (!releaseOldRequest) await new Promise((resolve) => setTimeout(resolve, 0));
      await nodeSession.loginNodeStaff('https://node-two.example/api/v1', 'staff@example.test', 'local-password');
      releaseOldRequest(new Response('{"message":"Unauthenticated"}', { status: 401 }));
      await oldRequest;

      assert.equal((await nodeSession.getNodeSession())?.token, 'node-token-2');
      assert.equal(unauthorizedCalled, false);
      await nodeSession.logoutNodeStaff();
      nodeSession.setNodeUnauthorizedHandler(null);
    });

    await t.test('web runtime refuses Node-local staff sign-in', async () => {
      Platform.OS = 'web';
      await assert.rejects(
        nodeSession.loginNodeStaff('https://node.example/api/v1', 'staff@example.test', 'local-password'),
        /available in the mobile app/,
      );
      Platform.OS = 'android';
    });

    await t.test('a disconnected Event Node prevents preview and sends no admission confirmation', async () => {
      const calls = [];
      globalThis.fetch = async (url, options) => {
        calls.push({ url: String(url), options });
        if (String(url).endsWith('/auth/node-staff/login')) {
          return new Response(JSON.stringify({ token: 'offline-test-node-token', user: staffUser, node_staff: true }), { status: 200 });
        }
        throw new TypeError('Network request failed');
      };

      await nodeSession.loginNodeStaff('https://node.example/api/v1', 'staff@example.test', 'local-password');
      await assert.rejects(
        nodeSession.nodeRequest('/staff/events/12/admissions/preview', { method: 'POST', body: '{}' }),
        (error) => error.status === 0 && error.message.includes('Cannot reach the Event Node'),
      );

      assert.equal(calls.length, 2);
      assert.equal(calls[1].url, 'https://node.example/api/v1/staff/events/12/admissions/preview');
      assert.equal(calls.some((call) => call.url.endsWith('/admissions')), false);
      await SecureStore.deleteItemAsync('ingress_node_staff_session_v1');
    });

    await t.test('the scanner session has no cloud credential store', async () => {
      const stored = await SecureStore.getItemAsync('ingress_node_staff_session_v1');
      assert.equal(stored, null);
      assert.equal(Platform.OS, 'android');
    });
  } finally {
    nodeSession.setNodeUnauthorizedHandler(null);
    await SecureStore.deleteItemAsync('ingress_node_staff_session_v1');
    globalThis.fetch = originalFetch;
    setLanDemoEnabled(false);
  }
});
