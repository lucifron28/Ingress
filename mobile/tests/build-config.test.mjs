import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const require = createRequire(import.meta.url);
const appJson = require('../app.json');
const easJson = require('../eas.json');
const resolveAppConfig = require('../app.config.js');

const keys = ['INGRESS_RELEASE_BUILD', 'INGRESS_LAN_DEMO'];

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

test('EAS demo APK is separate from secure preview and production builds', () => {
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
  assert.equal(lanDemo.env.INGRESS_LAN_DEMO_NODE_HOST, undefined);
});

test('preview and production keep cleartext traffic disabled', () => {
  const config = withEnvironment({
    INGRESS_RELEASE_BUILD: 'true',
    INGRESS_LAN_DEMO: 'false',
  }, () => resolveAppConfig({ config: appJson.expo }));

  assert.equal(buildProperties(config)?.android.usesCleartextTraffic, false);
  assert.equal(config.extra.ingressLanDemo, false);
});

test('LAN demo build allows HTTP without a pinned host or CA certificate', () => {
  const config = withEnvironment({
    INGRESS_RELEASE_BUILD: 'true',
    INGRESS_LAN_DEMO: 'true',
  }, () => resolveAppConfig({ config: appJson.expo }));

  assert.equal(buildProperties(config)?.android.usesCleartextTraffic, true);
  assert.equal(config.extra.ingressLanDemo, true);
  assert.equal(JSON.stringify(config).includes('INGRESS_LAN_DEMO_CA_CERT'), false);
  assert.equal(JSON.stringify(config).includes('INGRESS_LAN_DEMO_NODE_HOST'), false);
  assert.deepEqual(config.ios.infoPlist.NSAppTransportSecurity, {});
});

test('release builds require an explicit transport mode and keep demo opt-in', () => {
  assert.throws(
    () => withEnvironment({ INGRESS_RELEASE_BUILD: 'true' }, () => resolveAppConfig({ config: appJson.expo })),
    /INGRESS_LAN_DEMO/,
  );
  assert.throws(
    () => withEnvironment({ INGRESS_LAN_DEMO: 'true' }, () => resolveAppConfig({ config: appJson.expo })),
    /INGRESS_RELEASE_BUILD/,
  );
});
