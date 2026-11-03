/**
 * Executable checks for the mobile session-handling rules.
 *
 * The app has no JavaScript test runner (no jest, no react-test-renderer) and no
 * React Native runtime is available in CI, so this file runs the *real* service
 * modules (`src/services/auth-storage.ts`, `src/services/api-client.ts`) under Node
 * with the two native packages stubbed through an inline loader. That covers the
 * substrate the session rules rest on:
 *
 *   - clearing a session drops the token and cached user but keeps the Event Node URL
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

const LOADER = encode(`
import { existsSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';

const MAPPINGS = {
  'expo-secure-store': ${JSON.stringify(SECURE_STORE_STUB)},
  'react-native': ${JSON.stringify(REACT_NATIVE_STUB)},
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
const SecureStore = await import('expo-secure-store');
const { Platform } = await import('react-native');
const { loadWallet, loadWalletTicket } = await import('../src/services/wallet-sync.ts');
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
  'clearing a session keeps the configured Event Node URL',
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
  'storage primitives allow updating the Event Node URL independently of tokens',
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




console.log(`\n${passed} checks passed, ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
