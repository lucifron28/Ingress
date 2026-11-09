/**
 * Executable checks for the mobile session-handling rules.
 *
 * The app has no JavaScript test runner (no jest, no react-test-renderer) and no
 * React Native runtime is available in CI, so this file runs the *real* service
 * modules (`src/services/auth-storage.ts`, `src/services/api-client.ts`) under Node
 * with the two native packages stubbed through an inline loader. That covers the
 * substrate the session rules rest on:
 *
 *   - clearing a session drops the token and cached user but keeps the cloud API URL
 *   - a 401 is distinguishable from an unreachable server (status 401 vs status 0)
 *   - transport failures stay fail-closed
 *
 * Run from the mobile directory:  node tests/session.test.mjs
 */
import { register } from 'node:module';

const encode = (source) => `data:text/javascript,${encodeURIComponent(source)}`;

const SECURE_STORE_STUB = encode(`
const store = new Map();
export const WHEN_UNLOCKED_THIS_DEVICE_ONLY = 1;
let failureKey = null;
export function failNextWrite(key) { failureKey = key; }
export async function setItemAsync(k, v) {
  if (k === failureKey) { failureKey = null; throw new Error('storage unavailable'); }
  store.set(k, v);
}
export async function getItemAsync(k) { return store.has(k) ? store.get(k) : null; }
export async function deleteItemAsync(k) { store.delete(k); }
`);

const REACT_NATIVE_STUB = encode(`export const Platform = { OS: 'android' };`);
const EXPO_FETCH_STUB = encode(`export const fetch = (...args) => globalThis.fetch(...args);`);

const LOADER = encode(`
import { existsSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

const MAPPINGS = {
  'expo-secure-store': ${JSON.stringify(SECURE_STORE_STUB)},
  'react-native': ${JSON.stringify(REACT_NATIVE_STUB)},
  'expo/fetch': ${JSON.stringify(EXPO_FETCH_STUB)},
};

export async function resolve(specifier, context, nextResolve) {
  if (MAPPINGS[specifier]) {
    return { url: MAPPINGS[specifier], shortCircuit: true };
  }

  // TypeScript sources use extensionless relative imports (Metro resolves them);
  // Node needs the extension spelled out.
  if (specifier.startsWith('.') && !/\\.[a-z]+$/i.test(specifier) && context.parentURL) {
    const base = fileURLToPath(new URL(specifier, context.parentURL));
    for (const candidate of [base + '.ts', base + '.tsx', base + '/index.ts']) {
      if (existsSync(candidate)) {
        return { url: pathToFileURL(candidate).href, shortCircuit: true };
      }
    }
  }

  return nextResolve(specifier, context);
}
`);

register(LOADER, import.meta.url);

const { AuthStorage, TicketWallet } = await import('../src/services/auth-storage.ts');
const { INITIAL_NODE_STAFF_STATE, nodeStaffReducer } = await import('../src/services/node-staff-state.ts');
const SecureStore = await import('expo-secure-store');
const { Platform } = await import('react-native');
const { canUseStaffEntry } = await import('../src/services/staff-access.ts');
const { loadWallet, loadWalletTicket } = await import('../src/services/wallet-sync.ts');
const { loginNodeStaff, getNodeSession, nodeRequest, logoutNodeStaff, normalizeNodeUrl, pairNodeStaff, parseNodePairingCode, setNodeUnauthorizedHandler } = await import('../src/services/node-session.ts');
const { apiRequest, DEMO_MODE, ApiError, setUnauthorizedHandler } = await import('../src/services/api-client.ts');

let passed = 0;
const failures = [];
const check = (label, ok, detail = '') => {
  if (ok) {
    passed++;
    console.log(`PASS  ${label}`);
  } else {
    failures.push(label);
    console.log(`FAIL  ${label}${detail ? '  -- ' + detail : ''}`);
  }
};

check(
  'demo affordances are off unless the build opts in explicitly',
  DEMO_MODE === false,
  `DEMO_MODE=${DEMO_MODE}`,
);

await AuthStorage.saveServerUrl('http://192.168.1.50:8000/api/v1');
await AuthStorage.saveToken('token-from-server-a');
await AuthStorage.saveUser(JSON.stringify({ id: 1, name: 'Ron', role: 'student' }));

await AuthStorage.clearAll();

check('clearing a session removes the bearer token', (await AuthStorage.getToken()) === null);
check('clearing a session removes the cached user', (await AuthStorage.getUser()) === null);
check(
  'clearing a session keeps the configured cloud API URL',
  (await AuthStorage.getServerUrl()) === 'http://192.168.1.50:8000/api/v1',
  String(await AuthStorage.getServerUrl()),
);

const scope = { userId: 42, token: 'wallet-token', serverUrl: 'https://cloud.example/api/v1' };
await AuthStorage.saveServerUrl(scope.serverUrl);
await AuthStorage.saveToken(scope.token);
await AuthStorage.saveUser(JSON.stringify({ id: scope.userId, name: 'Student', role: 'student' }));
const ticket = {
  id: 7, public_id: 'ticket-7', status: 'active', issued_at: '2026-01-01T00:00:00Z',
  qr_payload: 'ingress:v1:ticket-7:secret',
  event: { id: 9, title: 'Event ' + '🎟'.repeat(700), starts_at: '2026-01-01T00:00:00Z', ends_at: '2026-01-02T00:00:00Z' },
  attendee: { name: 'Student', student_id: 'S1', email: 'student@example.test' },
};
check('wallet saves a large Unicode ticket in secure storage', await TicketWallet.replace(scope, [ticket]));
let wallet = await TicketWallet.read(scope);
check('wallet restores the exact opaque QR and Unicode ticket', JSON.stringify(wallet?.tickets) === JSON.stringify([ticket]));
check('wallet rejects another account', (await TicketWallet.read({ ...scope, userId: 43 })) === null);
check('wallet rejects another server', (await TicketWallet.read({ ...scope, serverUrl: 'https://other.example/api/v1' })) === null);
check('wallet rejects another session token', (await TicketWallet.read({ ...scope, token: 'new-token' })) === null);
await TicketWallet.update(scope, { ...ticket, status: 'revoked', qr_payload: null });
wallet = await TicketWallet.read(scope);
check('a refreshed revoked ticket replaces the cached QR', wallet?.tickets[0]?.status === 'revoked' && wallet?.tickets[0]?.qr_payload === null);
const olderRequest = TicketWallet.beginRequest();
const newerRequest = TicketWallet.beginRequest();
await TicketWallet.update(scope, { ...ticket, status: 'revoked', qr_payload: null }, newerRequest);
check('an older response cannot overwrite a newer revocation', !(await TicketWallet.replace(scope, [ticket], olderRequest)) && (await TicketWallet.read(scope))?.tickets[0]?.status === 'revoked');
SecureStore.failNextWrite('ingress_wallet_1');
check('an interrupted wallet write reports failure', !(await TicketWallet.replace(scope, [ticket])));
check('an interrupted write cannot restore a stale wallet', (await TicketWallet.read(scope)) === null);
await TicketWallet.replace(scope, [ticket]);
const pendingSave = TicketWallet.replace(scope, [ticket]);
const pendingLogout = AuthStorage.clearAll();
await Promise.all([pendingSave, pendingLogout]);
check('logout clears the wallet even when a save is in flight', (await TicketWallet.read(scope)) === null);
check('a late save after logout cannot recreate the wallet', !(await TicketWallet.replace(scope, [ticket])));
await AuthStorage.saveToken(scope.token);
await AuthStorage.saveUser(JSON.stringify({ id: scope.userId }));
await TicketWallet.replace(scope, [ticket]);
await AuthStorage.saveServerUrl('https://other.example/api/v1');
check('switching the configured server hides the old wallet', (await TicketWallet.read(scope)) === null);
await AuthStorage.saveServerUrl(scope.serverUrl);
Platform.OS = 'web';
check('web never writes a ticket wallet to local storage', !(await TicketWallet.replace(scope, [ticket])) && (await TicketWallet.read(scope)) === null);
Platform.OS = 'android';
await AuthStorage.clearAll();

const realFetch = globalThis.fetch;
await AuthStorage.saveServerUrl(scope.serverUrl);
await AuthStorage.saveToken(scope.token);
await AuthStorage.saveUser(JSON.stringify({ id: scope.userId, name: 'Student', role: 'student' }));
await TicketWallet.replace(scope, [ticket]);
const staffUser = { id: 77, name: 'Staff', email: 'staff@example.test', role: 'student', student_id: null };
const calls = [];
globalThis.fetch = async (url, options) => {
  calls.push({ url: String(url), options });
  if (String(url).endsWith('/auth/node-staff/login')) return new Response(JSON.stringify({ token: 'node-token', user: staffUser, node_staff: true }), { status: 200 });
  if (String(url).endsWith('/me')) return new Response(JSON.stringify({ user: staffUser }), { status: 200 });
  return new Response('{}', { status: 200 });
};
await loginNodeStaff('https://node.example/api/v1/', 'staff@example.test', 'secret');
await nodeRequest('/me');
check('node login and requests use HTTPS without redirects or cloud credentials', calls.length === 2 && calls.every((call) => call.url.startsWith('https://node.example/') && call.options.redirect === 'error' && call.options.credentials === 'omit') && !calls[0].options.headers.Authorization && calls[1].options.headers.Authorization === 'Bearer node-token');
check('node session persists independently of the cloud wallet', (await getNodeSession())?.token === 'node-token' && (await AuthStorage.getToken()) === scope.token && (await TicketWallet.read(scope))?.tickets[0]?.qr_payload === ticket.qr_payload);
let rejectedUrls = 0;
for (const url of ['http://node.example/api/v1', 'https://user:pass@node.example/api/v1', 'https://node.example/api/v1?x=1', 'https://node.example/api/v1#x', 'https://node.example/other']) {
  try { normalizeNodeUrl(url); } catch { rejectedUrls++; }
}
check('node configuration rejects insecure or ambiguous addresses', rejectedUrls === 5);
delete process.env.EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE;
let localHttpRejectedByDefault = false;
try { normalizeNodeUrl('http://192.168.1.50:8000/api/v1'); } catch { localHttpRejectedByDefault = true; }
process.env.EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE = 'true';
let localHttpAccepted = true;
for (const url of ['http://localhost:8000/api/v1', 'http://10.0.2.2:8000/api/v1', 'http://192.168.1.50:8000/api/v1', 'http://[fd00::1]:8000/api/v1']) {
  try { normalizeNodeUrl(url); } catch { localHttpAccepted = false; }
}
let publicHttpRejected = false;
try { normalizeNodeUrl('http://node.example/api/v1'); } catch { publicHttpRejected = true; }
check('local Event Node HTTP is opt-in and restricted to private hosts', localHttpRejectedByDefault && localHttpAccepted && publicHttpRejected);
delete process.env.EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE;
const beforeEscape = calls.length;
try { await nodeRequest('/../../other'); } catch { /* Expected rejection. */ }
check('node requests cannot escape the configured API path', calls.length === beforeEscape);
try { await nodeRequest('/staff/%2e%2e/other'); } catch { /* Expected rejection. */ }
check('node requests reject encoded path traversal', calls.length === beforeEscape);
check('staff entry hides from known unassigned students and permits assigned students', !canUseStaffEntry({ ...staffUser, is_event_staff: false }) && canUseStaffEntry({ ...staffUser, is_event_staff: true }));
check('staff entry remains available when an older cached profile has no assignment flag', canUseStaffEntry(staffUser));
globalThis.fetch = async () => new Response(JSON.stringify({ status: 'already_admitted', message: 'Already admitted', detail: 'Previously admitted', previous_admission: { access_point: 'Gate A', admitted_at: '2026-01-01T00:00:00Z' } }), { status: 409 });
let nodeConflict;
try { await nodeRequest('/staff/events/1/admissions'); } catch (error) { nodeConflict = error; }
check('node errors preserve admission status and prior gate', nodeConflict?.domainStatus === 'already_admitted' && nodeConflict?.data?.previous_admission?.access_point === 'Gate A');
globalThis.fetch = async () => new Response('{}', { status: 200 });
await logoutNodeStaff();
check('node logout clears only the node session', (await getNodeSession()) === null && (await AuthStorage.getToken()) === scope.token && (await TicketWallet.read(scope)) !== null);
const pairingRaw = JSON.stringify({ v: 1, url: 'https://node.example/api/v1', node: 'node-1', token: 'a'.repeat(64), expires: '2030-01-01T00:00:00Z' });
const pairingCode = parseNodePairingCode(pairingRaw);
let rejectedPairings = 0;
for (const value of ['not-json', JSON.stringify({ ...pairingCode, url: 'http://node.example/api/v1' }), JSON.stringify({ ...pairingCode, token: 'bad' }), JSON.stringify({ ...pairingCode, extra: true })]) {
  try { parseNodePairingCode(value); } catch { rejectedPairings++; }
}
check('pairing rejects malformed, insecure, and unexpected code data', rejectedPairings === 4);
const pairCalls = [];
globalThis.fetch = async (url, options) => {
  pairCalls.push({ url: String(url), options });
  return new Response(JSON.stringify({ token: 'paired-token', user: staffUser, node_staff: true, node_id: 'node-1' }), { status: 200 });
};
await pairNodeStaff(pairingCode);
check('pairing exchanges one-time code over isolated HTTPS and stores only the session', pairCalls.length === 1 && pairCalls[0].url === 'https://node.example/api/v1/auth/node-staff/pair' && pairCalls[0].options.redirect === 'error' && pairCalls[0].options.credentials === 'omit' && !pairCalls[0].options.headers.Authorization && JSON.parse(pairCalls[0].options.body).token === pairingCode.token && (await getNodeSession())?.token === 'paired-token' && !(await SecureStore.getItemAsync('ingress_node_staff_session_v1')).includes(pairingCode.token) && (await AuthStorage.getToken()) === scope.token);
await logoutNodeStaff();
let rejectedNodeRevoked = false;
globalThis.fetch = async (url) => {
  if (String(url).endsWith('/auth/logout')) { rejectedNodeRevoked = true; return new Response('{}', { status: 200 }); }
  return new Response(JSON.stringify({ token: 'untrusted-token', user: staffUser, node_staff: true, node_id: 'node-2' }), { status: 200 });
};
let mismatchRejected = false;
try { await pairNodeStaff(pairingCode); } catch { mismatchRejected = true; }
check('a mismatched pairing response is rejected and its issued token is revoked', mismatchRejected && rejectedNodeRevoked && (await getNodeSession()) === null);
globalThis.fetch = async () => new Response('null', { status: 200 });
let emptyPairRejected = false;
try { await pairNodeStaff(pairingCode); } catch (error) { emptyPairRejected = error.message.includes('identity did not match'); }
check('an invalid pairing response fails without storing a session', emptyPairRejected && (await getNodeSession()) === null);
let pairRevokedOnFailure = false;
globalThis.fetch = async (url) => {
  if (String(url).endsWith('/auth/logout')) { pairRevokedOnFailure = true; return new Response('{}', { status: 200 }); }
  return new Response(JSON.stringify({ token: 'unsaved-pair-token', user: staffUser, node_staff: true, node_id: 'node-1' }), { status: 200 });
};
SecureStore.failNextWrite('ingress_node_staff_session_v1');
let pairStorageFailure = false;
try { await pairNodeStaff(pairingCode); } catch { pairStorageFailure = true; }
check('failed secure storage revokes a paired session and keeps the cloud wallet', pairStorageFailure && pairRevokedOnFailure && (await getNodeSession()) === null && (await TicketWallet.read(scope)) !== null);
globalThis.fetch = async () => new Response(JSON.stringify({ token: 'node-token', user: staffUser, node_staff: true }), { status: 200 });
await loginNodeStaff('https://node.example/api/v1', 'staff@example.test', 'secret');
globalThis.fetch = async () => new Response('{"message":"Unauthenticated"}', { status: 401 });
try { await nodeRequest('/me'); } catch { /* Expected rejection. */ }
check('node 401 clears only the matching node session', (await getNodeSession()) === null && (await AuthStorage.getToken()) === scope.token && (await TicketWallet.read(scope)) !== null);
let loginNumber = 0;
let releaseOldRequest;
let notified = false;
setNodeUnauthorizedHandler(() => { notified = true; });
globalThis.fetch = async (url) => {
  if (String(url).endsWith('/auth/node-staff/login')) {
    loginNumber++;
    return new Response(JSON.stringify({ token: `node-token-${loginNumber}`, user: staffUser, node_staff: true }), { status: 200 });
  }
  if (String(url).endsWith('/me')) return new Promise((resolve) => { releaseOldRequest = resolve; });
  return new Response('{}', { status: 200 });
};
await loginNodeStaff('https://node.example/api/v1', 'staff@example.test', 'secret');
const oldRequest = nodeRequest('/me').catch(() => undefined);
while (!releaseOldRequest) await new Promise((resolve) => setTimeout(resolve, 0));
await loginNodeStaff('https://node2.example/api/v1', 'staff@example.test', 'secret');
releaseOldRequest(new Response('{"message":"Unauthenticated"}', { status: 401 }));
await oldRequest;
check('a delayed node 401 cannot clear a newer node session', (await getNodeSession())?.token === 'node-token-2' && !notified);
await logoutNodeStaff();
setNodeUnauthorizedHandler(null);
let revokedUnsaved = false;
globalThis.fetch = async (url) => {
  if (String(url).endsWith('/auth/logout')) { revokedUnsaved = true; return new Response('{}', { status: 200 }); }
  return new Response(JSON.stringify({ token: 'unsaved-token', user: staffUser, node_staff: true }), { status: 200 });
};
SecureStore.failNextWrite('ingress_node_staff_session_v1');
let secureFailure = false;
try { await loginNodeStaff('https://node.example/api/v1', 'staff@example.test', 'secret'); } catch { secureFailure = true; }
check('failed secure storage rejects sign-in and revokes the unsaved token', secureFailure && revokedUnsaved && (await getNodeSession()) === null);
Platform.OS = 'web';
let webRejected = false;
try { await loginNodeStaff('https://node.example/api/v1', 'staff@example.test', 'secret'); } catch { webRejected = true; }
check('node sessions are not persisted in web storage', webRejected);
Platform.OS = 'android';
globalThis.fetch = realFetch;
await AuthStorage.clearAll();

await AuthStorage.saveServerUrl(scope.serverUrl);
await AuthStorage.saveToken(scope.token);
await AuthStorage.saveUser(JSON.stringify({ id: scope.userId }));
globalThis.fetch = async () => new Response(JSON.stringify({ data: [ticket] }), { status: 200 });
const onlineWallet = await loadWallet(scope);
check('online wallet fetch saves the returned ticket', onlineWallet.saved && onlineWallet.offlineSince === null);
globalThis.fetch = async () => { throw new TypeError('offline'); };
const offlineWallet = await loadWallet(scope);
const offlineTicket = await loadWalletTicket(scope, '7');
check('offline wallet and detail restore the same QR', offlineWallet.offlineSince !== null && offlineTicket.ticket.qr_payload === ticket.qr_payload);
globalThis.fetch = async () => new Response('{"message":"Forbidden"}', { status: 403 });
try { await loadWalletTicket(scope, '7'); } catch { /* Expected rejection. */ }
check('a server rejection removes the cached wallet', (await TicketWallet.read(scope)) === null);
globalThis.fetch = async () => { throw new TypeError('offline'); };
let noFallback = false;
try { await loadWalletTicket(scope, '7'); } catch { noFallback = true; }
check('a rejected ticket is not shown on the next offline attempt', noFallback);
await AuthStorage.clearAll();
await AuthStorage.saveToken('rejected-token');
await AuthStorage.saveUser(JSON.stringify({ id: 42 }));
setUnauthorizedHandler(async (token) => { await AuthStorage.clearIfToken(token); });


globalThis.fetch = async () =>
  new Response('{"message":"Unauthenticated."}', {
    status: 401,
    headers: { 'Content-Type': 'application/json' },
  });

let unauthorizedStatus = null;
try {
  await apiRequest('/me');
} catch (error) {
  unauthorizedStatus = error?.status;
}
check('a 401 is reported as status 401, so the session can be cleared', unauthorizedStatus === 401, `got ${unauthorizedStatus}`);
check('a matching 401 clears the rejected session', (await AuthStorage.getToken()) === null);
await AuthStorage.saveToken('new-session');
await AuthStorage.saveUser(JSON.stringify({ id: 43 }));
check('a delayed 401 cannot clear a newer session', !(await AuthStorage.clearIfToken('rejected-token')) && (await AuthStorage.getToken()) === 'new-session');
setUnauthorizedHandler(null);


globalThis.fetch = async () => {
  throw new TypeError('Network request failed');
};

let offlineStatus = null;
let offlineMessage = '';
try {
  await apiRequest('/me');
} catch (error) {
  offlineStatus = error?.status;
  offlineMessage = error?.message ?? '';
}
check('a transport failure is reported as status 0, so the cached identity is kept', offlineStatus === 0, `got ${offlineStatus}`);
check('a transport failure remains fail-closed for server actions', offlineMessage.includes('SERVER UNAVAILABLE'), offlineMessage);
globalThis.fetch = async () =>
  new Response(
    JSON.stringify({
      status: 'already_admitted',
      message: 'Already Checked In',
      detail: 'Previously admitted at Gate A, 1:42 PM',
      previous_admission: {
        access_point: 'Gate A',
        admitted_at: '2026-10-02T01:42:00.000Z',
      },
      ticket: {
        public_id: 'ING-TEST-1234',
        attendee_name: 'Ron Vincent Cada',
        student_id: 'A23-35524',
      },
    }),
    {
      status: 409,
      headers: { 'Content-Type': 'application/json' },
    },
  );

let capturedError = null;
try {
  await apiRequest('/staff/events/1/admissions');
} catch (error) {
  capturedError = error;
}

check('ApiError preserves server domain status', capturedError?.domainStatus === 'already_admitted');
check('ApiError preserves detail string', capturedError?.detail === 'Previously admitted at Gate A, 1:42 PM');
check(
  'ApiError preserves previous_admission data',
  capturedError?.data?.previous_admission?.access_point === 'Gate A',
);
check(
  'ApiError preserves ticket data',
  capturedError?.data?.ticket?.attendee_name === 'Ron Vincent Cada',
);
check(
  'ApiError is an instance of ApiError and Error',
  capturedError instanceof ApiError && capturedError instanceof Error,
);

globalThis.fetch = realFetch;

// React-level navigation is covered by typechecking and code review; this file tests the storage/service layer.
await AuthStorage.saveServerUrl('http://10.0.0.9:8000/api/v1');
await AuthStorage.saveToken('token-from-server-b');

check(
  'storage primitives allow updating the cloud API URL independently of tokens',
  (await AuthStorage.getServerUrl()) === 'http://10.0.0.9:8000/api/v1' &&
    (await AuthStorage.getToken()) === 'token-from-server-b',
);
import fs from 'node:fs';
import path from 'node:path';

const ticketsIndexSource = fs.readFileSync(path.resolve('app/(attendee)/tickets/index.tsx'), 'utf-8');
check(
  'tickets index has no web DOM span tags',
  !ticketsIndexSource.includes('<span') && !ticketsIndexSource.includes('</span>'),
);

const layoutSource = fs.readFileSync(path.resolve('app/(attendee)/_layout.tsx'), 'utf-8');
check(
  'attendee layout tab bar uses Ionicons instead of text placeholders',
  layoutSource.includes('Ionicons') &&
    !layoutSource.includes('[E]') &&
    !layoutSource.includes('[T]') &&
    !layoutSource.includes('[P]'),
);

const ticketDetailSource = fs.readFileSync(path.resolve('app/(attendee)/tickets/[id].tsx'), 'utf-8');
check(
  'ticket detail uses generic admission notice instead of hardcoded gates',
  ticketDetailSource.includes('Admission Notice') &&
    !ticketDetailSource.includes('Gate Admission Notice') &&
    !ticketDetailSource.includes('Gate A, Gate B'),
);

const scanScreenSource = fs.readFileSync(path.resolve('app/(staff)/scan.tsx'), 'utf-8');
check(
  'scanner calls read-only preview endpoint before admission confirmation',
  scanScreenSource.includes('/admissions/preview'),
);
check(
  'scanner uses Crypto.randomUUID for client scan ID',
  scanScreenSource.includes('Crypto.randomUUID()') && !scanScreenSource.includes('Math.random()'),
);
check(
  'scanner provides explicit Verify Attendee confirmation UI',
  scanScreenSource.includes('VERIFY ATTENDEE') &&
    scanScreenSource.includes('NOT THIS PERSON') &&
    scanScreenSource.includes('CONFIRM ADMISSION'),
);

check(
  'ticket detail conditionally hides QR canvas on revoked or cancelled status',
  ticketDetailSource.includes("ticket.status === 'active'") &&
    ticketDetailSource.includes('This ticket is no longer valid.'),
);
check(
  'ticket detail does not synthesize fake QR credentials when payload is missing',
  !ticketDetailSource.includes('ingress:v1:${ticket.public_id}') &&
    ticketDetailSource.includes('QR Credential Unavailable'),
);

check(
  'scanner preserves client scan ID on ambiguous network errors for idempotent retry',
  scanScreenSource.includes('isAmbiguous') &&
    scanScreenSource.includes('confirmError') &&
    scanScreenSource.includes('pendingPreview.clientScanId'),
);

const nodeStaffContextSource = fs.readFileSync(path.resolve('src/context/NodeStaffContext.tsx'), 'utf-8');
const nodeUnauthorizedHandler = nodeStaffContextSource.match(
  /setNodeUnauthorizedHandler\(\(token\) => \{([\s\S]*?)\n    \}\);/,
)?.[1] || '';
check(
  'a matching node 401 clears both the session and active mode',
  nodeUnauthorizedHandler.includes("dispatch({ type: 'unauthorized', token })"),
);
check(
  'a delayed node 401 leaves a newer session untouched',
  nodeUnauthorizedHandler.indexOf('current.current?.token !== token') >= 0 &&
    nodeUnauthorizedHandler.indexOf('current.current?.token !== token') < nodeUnauthorizedHandler.indexOf('current.current = null'),
);
const activeNodeState = {
  session: { token: 'active-node-token', url: 'https://node.example/api/v1' },
  active: true,
};
const unauthorizedNodeState = nodeStaffReducer(activeNodeState, {
  type: 'unauthorized',
  token: 'active-node-token',
});
check(
  'a matching node 401 atomically clears the saved context state',
  unauthorizedNodeState.session === null && unauthorizedNodeState.active === false,
);
const delayedUnauthorizedState = nodeStaffReducer(activeNodeState, {
  type: 'unauthorized',
  token: 'older-node-token',
});
check(
  'the node state reducer ignores a delayed 401 for an older session',
  delayedUnauthorizedState === activeNodeState && delayedUnauthorizedState.active,
);

const staffEventsSource = fs.readFileSync(path.resolve('app/(staff)/events.tsx'), 'utf-8');
check(
  'staff events refresh when the active session identity changes',
  staffEventsSource.includes('sessionIdentity') &&
    /useEffect\([\s\S]*?\}, \[sessionIdentity, fetchAssignedEvents\]\)/.test(staffEventsSource),
);
check(
  'staff events ignore responses from a previous session',
  staffEventsSource.includes('requestGeneration.current') &&
    (staffEventsSource.match(/if \(!isCurrent\(\)\) return;/g) || []).length >= 2,
);

const loginScreenSource = fs.readFileSync(path.resolve('app/node-staff-login.tsx'), 'utf-8');
const resumeStart = nodeStaffContextSource.indexOf('const resume = async');
const resumeEnd = nodeStaffContextSource.indexOf('const logout = async', resumeStart);
const resumeSource = nodeStaffContextSource.slice(resumeStart, resumeEnd);
check(
  'cached node staff session stays inactive until the user resumes online',
  INITIAL_NODE_STAFF_STATE.active === false &&
    loginScreenSource.includes('onPress={resume}') &&
    resumeSource.indexOf("await nodeRequest<{ user: UserProfile }>('/me')") >= 0 &&
    resumeSource.indexOf("await nodeRequest<{ user: UserProfile }>('/me')") < resumeSource.indexOf("dispatch({ type: 'activate', session: next })"),
);




const originalReleaseFlag = globalThis.__DEV__;
const originalApiUrl = process.env.EXPO_PUBLIC_API_URL;
const releaseApiUrl = 'https://ingress-pp2u.onrender.com/api/v1';
globalThis.__DEV__ = false;
process.env.EXPO_PUBLIC_API_URL = releaseApiUrl;
const { apiRequest: releaseApiRequest } = await import('../src/services/api-client.ts?release-origin-test');
const legacyScope = { userId: 44, token: 'legacy-local-token', serverUrl: 'http://10.0.0.9:8000/api/v1' };
await AuthStorage.saveServerUrl(legacyScope.serverUrl);
await AuthStorage.saveToken(legacyScope.token);
await AuthStorage.saveUser(JSON.stringify({ id: legacyScope.userId, name: 'Old profile' }));
await TicketWallet.replace(legacyScope, [ticket]);
let releaseRequest;
globalThis.fetch = async (url, options) => {
  releaseRequest = { url: String(url), options };
  return new Response('{}', { status: 200 });
};
await releaseApiRequest('/me');
check(
  'release API migration clears a legacy origin session before sending a request',
  releaseRequest?.url === `${releaseApiUrl}/me` &&
    !releaseRequest?.options.headers.Authorization &&
    (await AuthStorage.getServerUrl()) === releaseApiUrl &&
    (await AuthStorage.getToken()) === null &&
    (await AuthStorage.getUser()) === null &&
    (await TicketWallet.read(legacyScope)) === null,
);

await AuthStorage.saveToken('current-cloud-token');
await AuthStorage.saveUser(JSON.stringify({ id: 45, name: 'Current profile' }));
await releaseApiRequest('/me');
check(
  'release API retains a session already scoped to the configured cloud',
  releaseRequest?.url === `${releaseApiUrl}/me` &&
    releaseRequest?.options.headers.Authorization === 'Bearer current-cloud-token' &&
    (await AuthStorage.getToken()) === 'current-cloud-token',
);

await SecureStore.deleteItemAsync('ingress_server_url');
await AuthStorage.saveToken('legacy-token-without-origin');
await AuthStorage.saveUser(JSON.stringify({ id: 46, name: 'Unscoped profile' }));
const missingOriginScope = { userId: 46, token: 'legacy-token-without-origin', serverUrl: releaseApiUrl };
await TicketWallet.replace(missingOriginScope, [ticket]);
await releaseApiRequest('/me');
check(
  'release API clears an upgraded session with no saved origin before sending a request',
  releaseRequest?.url === `${releaseApiUrl}/me` &&
    !releaseRequest?.options.headers.Authorization &&
    (await AuthStorage.getToken()) === null &&
    (await AuthStorage.getUser()) === null &&
    (await TicketWallet.read(missingOriginScope)) === null,
);

globalThis.fetch = realFetch;
if (originalReleaseFlag === undefined) delete globalThis.__DEV__;
else globalThis.__DEV__ = originalReleaseFlag;
if (originalApiUrl === undefined) delete process.env.EXPO_PUBLIC_API_URL;
else process.env.EXPO_PUBLIC_API_URL = originalApiUrl;

console.log(`\n${passed} checks passed, ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
