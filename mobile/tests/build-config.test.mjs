import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const require = createRequire(import.meta.url);
const appJson = require('../app.json');
const easJson = require('../eas.json');
const resolveAppConfig = require('../app.config.js');
const { renderNetworkSecurityConfig, validateLanDemoCa, validateLanDemoHost } = require('../plugins/ingress-network-security.js');
const testCaPath = fileURLToPath(new URL('./fixtures/test-root-ca.der', import.meta.url));
const testLeafPath = fileURLToPath(new URL('./fixtures/test-leaf.der', import.meta.url));

const keys = [
  'INGRESS_RELEASE_BUILD',
  'INGRESS_LAN_DEMO',
  'INGRESS_LAN_DEMO_CA_CERT',
  'INGRESS_LAN_DEMO_NODE_HOST',
  'EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE',
  'EXPO_PUBLIC_API_URL',
  'EXPO_PUBLIC_INGRESS_DEMO_MODE',
];

function withEnvironment(environment, callback) {
  const previous = new Map(keys.map((key) => [key, process.env[key]]));
  for (const key of keys) {
    if (environment[key] === undefined) delete process.env[key];
    else process.env[key] = environment[key];
  }
  try {
    return callback();
  } finally {
    for (const [key, value] of previous) {
      if (value === undefined) delete process.env[key];
      else process.env[key] = value;
    }
  }
}

function buildProperties(config) {
  return config.plugins.find((plugin) => Array.isArray(plugin) && plugin[0] === 'expo-build-properties')?.[1];
}

test('Android camera permission does not request microphone or storage access', () => {
  const permissions = appJson.expo.android.permissions ?? [];
  const blocked = appJson.expo.android.blockedPermissions ?? [];
  const camera = appJson.expo.plugins.find((plugin) => Array.isArray(plugin) && plugin[0] === 'expo-camera');

  assert.ok(!permissions.some((permission) => /RECORD_AUDIO|READ_EXTERNAL_STORAGE|WRITE_EXTERNAL_STORAGE$/.test(permission)));
  assert.equal(camera?.[1]?.microphonePermission, false);
  assert.equal(camera?.[1]?.recordAudioAndroid, false);
  assert.ok(blocked.includes('android.permission.RECORD_AUDIO'));
  assert.ok(blocked.includes('android.permission.READ_EXTERNAL_STORAGE'));
  assert.ok(blocked.includes('android.permission.WRITE_EXTERNAL_STORAGE'));
});

test('EAS preview and production builds are secure, while LAN demo is a separate APK profile', () => {
  const { preview, production, lanDemo } = easJson.build;

  assert.equal(preview.environment, 'preview');
  assert.equal(preview.distribution, 'internal');
  assert.equal(preview.android.buildType, 'apk');
  assert.equal(production.environment, 'production');
  assert.equal(production.android.buildType, 'app-bundle');
  assert.equal(lanDemo.extends, 'preview');
  assert.equal(lanDemo.distribution, 'internal');
  assert.equal(lanDemo.android.buildType, 'apk');

  for (const profile of [preview, production]) {
    assert.equal(profile.env.INGRESS_RELEASE_BUILD, 'true');
    assert.equal(profile.env.INGRESS_LAN_DEMO, 'false');
  }
  assert.equal(lanDemo.env.INGRESS_LAN_DEMO, 'true');
  assert.equal(lanDemo.env.INGRESS_LAN_DEMO_CA_CERT, undefined);
});

test('release config does not need a Cloud API URL and always disables cleartext traffic', () => {
  const config = withEnvironment({
    INGRESS_RELEASE_BUILD: 'true',
    INGRESS_LAN_DEMO: 'false',
    EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE: 'true',
    EXPO_PUBLIC_INGRESS_DEMO_MODE: 'true',
  }, () => resolveAppConfig({ config: appJson.expo }));

  assert.equal(buildProperties(config)?.android.usesCleartextTraffic, false);
  assert.ok(config.plugins.some((plugin) => Array.isArray(plugin) && String(plugin[0]).includes('with-ingress-lan-demo-ca')));
  assert.equal(config.android.networkSecurityConfig, undefined);
  assert.deepEqual(config.ios.infoPlist.NSAppTransportSecurity, {});
});

test('release builds require an explicit LAN trust mode', () => {
  assert.throws(() => withEnvironment({ INGRESS_RELEASE_BUILD: 'true' }, () => resolveAppConfig({ config: appJson.expo })), /INGRESS_LAN_DEMO/);
});

test('production release ignores LAN-demo CA and host environment variables', () => {
  const config = withEnvironment({
    INGRESS_RELEASE_BUILD: 'true',
    INGRESS_LAN_DEMO: 'false',
    INGRESS_LAN_DEMO_CA_CERT: 'unused.pem',
    INGRESS_LAN_DEMO_NODE_HOST: '192.168.1.25',
  }, () => resolveAppConfig({ config: appJson.expo }));
  const plugin = config.plugins.find((entry) => Array.isArray(entry) && String(entry[0]).includes('with-ingress-lan-demo-ca'));

  assert.equal(plugin?.[1]?.lanDemo, false);
  assert.equal(plugin?.[1]?.caCertPath, undefined);
  assert.equal(plugin?.[1]?.nodeHost, undefined);
});

test('LAN demo release requires a readable CA certificate', () => {
  assert.throws(() => withEnvironment({
    INGRESS_RELEASE_BUILD: 'true',
    INGRESS_LAN_DEMO: 'true',
    INGRESS_LAN_DEMO_CA_CERT: 'missing-lan-demo-root-ca.pem',
  }, () => resolveAppConfig({ config: appJson.expo })), /CA certificate/);
});

test('LAN demo release requires the exact Event Node TLS hostname', () => {
  assert.throws(() => withEnvironment({
    INGRESS_RELEASE_BUILD: 'true',
    INGRESS_LAN_DEMO: 'true',
    INGRESS_LAN_DEMO_CA_CERT: testCaPath,
  }, () => resolveAppConfig({ config: appJson.expo })), /INGRESS_LAN_DEMO_NODE_HOST/);
});

test('LAN demo rejects an end-entity certificate used in place of a CA', () => {
  assert.throws(() => validateLanDemoCa(testLeafPath), /currently valid CA certificate/);
});

test('LAN demo release validates and selects only the supplied public CA file', () => {
  const config = withEnvironment({
    INGRESS_RELEASE_BUILD: 'true',
    INGRESS_LAN_DEMO: 'true',
    INGRESS_LAN_DEMO_CA_CERT: testCaPath,
    INGRESS_LAN_DEMO_NODE_HOST: '192.168.1.25',
  }, () => resolveAppConfig({ config: appJson.expo }));
  const plugin = config.plugins.find((entry) => Array.isArray(entry) && String(entry[0]).includes('with-ingress-lan-demo-ca'));

  assert.equal(buildProperties(config)?.android.usesCleartextTraffic, false);
  assert.equal(plugin?.[1]?.lanDemo, true);
  assert.equal(plugin?.[1]?.nodeHost, '192.168.1.25');
  assert.equal(JSON.stringify(config.plugins).includes(testCaPath), false);
});

test('Android TLS policy keeps custom CA trust out of production and scoped to the demo build', () => {
  const productionXml = renderNetworkSecurityConfig({ lanDemo: false });
  const demoXml = renderNetworkSecurityConfig({ lanDemo: true, nodeHost: '192.168.1.25' });

  for (const xml of [productionXml, demoXml]) {
    assert.match(xml, /cleartextTrafficPermitted="false"/);
    assert.match(xml, /certificates src="system"/);
    assert.doesNotMatch(xml, /certificates src="user"|trust-all|TrustAll/);
  }
  assert.doesNotMatch(productionXml, /ingress_lan_demo_ca/);
  assert.match(demoXml, /<domain-config cleartextTrafficPermitted="false">\s*<domain includeSubdomains="false">192\.168\.1\.25<\/domain>/);
  assert.match(demoXml, /certificates src="@raw\/ingress_lan_demo_ca"/);
  assert.doesNotMatch(demoXml.split('<domain-config')[0], /ingress_lan_demo_ca/);
});

test('LAN demo CA trust host rejects schemes, ports, paths, and malformed hosts', () => {
  for (const host of ['https://node.example', 'node.example:8443', 'node.example/api/v1', 'bad name']) {
    assert.throws(() => validateLanDemoHost(host), /INGRESS_LAN_DEMO_NODE_HOST/);
  }
  assert.equal(validateLanDemoHost('Node.Example'), 'node.example');
});
