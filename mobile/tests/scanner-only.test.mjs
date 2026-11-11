import assert from 'node:assert/strict';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { test } from 'node:test';

const routeRoot = path.resolve('src/app');
const source = (relativePath) => readFileSync(path.join(routeRoot, relativePath), 'utf8');

test('Expo Router exposes only the staff scanner route tree', () => {
  const routePaths = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const target = path.join(directory, entry.name);
      if (entry.isDirectory()) visit(target);
      else if (/\.(tsx?|jsx?)$/.test(entry.name)) routePaths.push(path.relative(routeRoot, target).replaceAll(path.sep, '/'));
    }
  };
  visit(routeRoot);

  assert.deepEqual(routePaths.sort(), [
    '(staff)/_layout.tsx',
    '(staff)/events.tsx',
    '(staff)/scan.tsx',
    '(staff)/walk-in.tsx',
    '_layout.tsx',
    'index.tsx',
    'node-staff-login.tsx',
  ]);
  assert.equal(source('_layout.tsx').includes('AuthProvider'), false);
  assert.equal(existsSync(path.resolve('app/(attendee)')), false);
});
test('assigned events, scans, and walk-ins require the local Event Node API', () => {
  for (const route of ['(staff)/events.tsx', '(staff)/scan.tsx', '(staff)/walk-in.tsx']) {
    const routeSource = source(route);
    assert.match(routeSource, /nodeRequest/);
    assert.doesNotMatch(routeSource, /apiRequest|api-client|AuthContext|useAuth/);
  }
});

test('staff login is local and preserves one-time pairing and secure session resume', () => {
  const loginSource = source('node-staff-login.tsx');
  const layoutSource = source('(staff)/_layout.tsx');
  const rootLayout = source('_layout.tsx');

  assert.match(loginSource, /useNodeStaff/);
  assert.match(loginSource, /parseNodePairingCode/);
  assert.match(loginSource, /Scan pairing code/);
  assert.match(loginSource, /node\.resume\(\)/);
  assert.doesNotMatch(loginSource, /AuthContext|useAuth|staff-access|canUseStaffEntry/);
  assert.doesNotMatch(layoutSource, /AuthContext|useAuth|\/login/);
  assert.match(layoutSource, /node\.logout\(\)/);
  assert.match(rootLayout, /NodeStaffProvider/);
  assert.doesNotMatch(rootLayout, /AuthProvider|AuthContext|\(attendee\)|student-register|server-settings/);
});

test('scanner keeps preview before explicit confirmation and preserves retry identity', () => {
  const scanSource = source('(staff)/scan.tsx');

  assert.match(scanSource, /\/admissions\/preview/);
  assert.match(scanSource, /CONFIRM ADMISSION/);
  assert.match(scanSource, /VERIFY ATTENDEE/);
  assert.match(scanSource, /Crypto\.randomUUID\(\)/);
  assert.match(scanSource, /pendingPreview\.clientScanId/);
  assert.doesNotMatch(scanSource, /Math\.random\(\)/);
});

test('mobile dependencies and environment template contain no attendee/cloud client features', () => {
  const packageJson = JSON.parse(readFileSync(path.resolve('package.json'), 'utf8'));
  const environmentExample = readFileSync(path.resolve('.env.example'), 'utf8');
  const routeSources = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const target = path.join(directory, entry.name);
      if (entry.isDirectory()) visit(target);
      else if (/\.(tsx?|jsx?)$/.test(entry.name)) routeSources.push(readFileSync(target, 'utf8'));
    }
  };
  visit(routeRoot);

  assert.equal(packageJson.dependencies['@expo/vector-icons'], undefined);
  assert.doesNotMatch(environmentExample, /EXPO_PUBLIC_API_URL|INGRESS_ALLOW_HTTP_NODE|INGRESS_DEMO_MODE/);
  assert.doesNotMatch(routeSources.join('\n'), /EXPO_PUBLIC_API_URL|api-client|AuthContext|wallet-sync|ticket-transfer-sync/);
});
