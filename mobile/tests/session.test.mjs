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
export async function setItemAsync(k, v) { store.set(k, v); }
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

const { AuthStorage } = await import('../src/services/auth-storage.ts');
const { apiRequest, DEMO_MODE } = await import('../src/services/api-client.ts');

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
await AuthStorage.saveUser(JSON.stringify({ id: 1, name: 'Carlo', role: 'student' }));

await AuthStorage.clearAll();

check('clearing a session removes the bearer token', (await AuthStorage.getToken()) === null);
check('clearing a session removes the cached user', (await AuthStorage.getUser()) === null);
check(
  'clearing a session keeps the configured Event Node URL',
  (await AuthStorage.getServerUrl()) === 'http://192.168.1.50:8000/api/v1',
  String(await AuthStorage.getServerUrl()),
);

const realFetch = globalThis.fetch;

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

globalThis.fetch = realFetch;

// React-level navigation is covered by typechecking and code review; this file tests the storage/service layer.
await AuthStorage.saveServerUrl('http://10.0.0.9:8000/api/v1');
await AuthStorage.saveToken('token-from-server-b');

check(
  'storage primitives allow updating the Event Node URL independently of tokens',
  (await AuthStorage.getServerUrl()) === 'http://10.0.0.9:8000/api/v1' &&
    (await AuthStorage.getToken()) === 'token-from-server-b',
);

console.log(`\n${passed} checks passed, ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
