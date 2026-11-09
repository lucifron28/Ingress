import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const require = createRequire(import.meta.url);
const appJson = require('../app.json');
const easJson = require('../eas.json');
const resolveAppConfig = require('../app.config.js');
const mobileEnvironmentExample = readFileSync(new URL('../.env.example', import.meta.url), 'utf8');
const environmentKeys = [
  'EXPO_PUBLIC_API_URL',
  'EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE',
  'EXPO_PUBLIC_INGRESS_DEMO_MODE',
  'INGRESS_RELEASE_BUILD',
];
const secureReleaseEnvironment = {
  INGRESS_RELEASE_BUILD: 'true',
  EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE: 'false',
  EXPO_PUBLIC_INGRESS_DEMO_MODE: 'false',
};

function withEnvironment(environment, callback) {
  const previousValues = new Map(environmentKeys.map((key) => [key, process.env[key]]));

  for (const key of environmentKeys) {
    if (environment[key] === undefined) {
      delete process.env[key];
    } else {
      process.env[key] = environment[key];
    }
  }

  try {
    return callback();
  } finally {
    for (const [key, value] of previousValues) {
      if (value === undefined) {
        delete process.env[key];
      } else {
        process.env[key] = value;
      }
    }
  }
}

test('Android config requests camera without microphone access', () => {
  const permissions = appJson.expo.android.permissions ?? [];
  const blockedPermissions = appJson.expo.android.blockedPermissions ?? [];
  const cameraPlugin = appJson.expo.plugins.find((plugin) => Array.isArray(plugin) && plugin[0] === 'expo-camera');

  assert.ok(!permissions.some((permission) => permission.endsWith('RECORD_AUDIO')));
  assert.equal(cameraPlugin?.[1]?.microphonePermission, false);
  assert.equal(cameraPlugin?.[1]?.recordAudioAndroid, false);
  assert.ok(blockedPermissions.includes('android.permission.RECORD_AUDIO'));
  assert.ok(blockedPermissions.includes('android.permission.READ_EXTERNAL_STORAGE'));
  assert.ok(blockedPermissions.includes('android.permission.WRITE_EXTERNAL_STORAGE'));
});

test('EAS profiles explicitly select safe environments and Android artifact types', () => {
  const preview = easJson.build.preview;
  const production = easJson.build.production;

  assert.equal(preview.environment, 'preview');
  assert.equal(preview.distribution, 'internal');
  assert.equal(preview.android.buildType, 'apk');
  assert.equal(production.environment, 'production');
  assert.equal(production.distribution, 'store');
  assert.equal(production.android.buildType, 'app-bundle');

  for (const profile of [preview, production]) {
    assert.equal(profile.env.INGRESS_RELEASE_BUILD, 'true');
    assert.equal(profile.env.EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE, 'false');
    assert.equal(profile.env.EXPO_PUBLIC_INGRESS_DEMO_MODE, 'false');
  }
});

test('mobile environment example targets the current Render staging API', () => {
  assert.match(mobileEnvironmentExample, /^EXPO_PUBLIC_API_URL=https:\/\/ingress-pp2u\.onrender\.com\/api\/v1$/m);
});

test('release config rejects missing, insecure, placeholder, and incomplete cloud API URLs', () => {
  const invalidUrls = [
    undefined,
    'http://cloud.example.edu/api/v1',
    'https://cloud.example.edu/api/v1',
    'https://cloud.example.test/api',
  ];

  for (const apiUrl of invalidUrls) {
    assert.throws(() => withEnvironment({ ...secureReleaseEnvironment, EXPO_PUBLIC_API_URL: apiUrl }, () => {
      resolveAppConfig({ config: appJson.expo });
    }), /EXPO_PUBLIC_API_URL/);
  }
});

test('release config accepts the HTTPS cloud API and keeps local HTTP disabled', () => {
  const config = withEnvironment({
    ...secureReleaseEnvironment,
    EXPO_PUBLIC_API_URL: 'https://ingress-pp2u.onrender.com/api/v1',
  }, () => resolveAppConfig({ config: appJson.expo }));
  const buildProperties = config.plugins.find((plugin) => Array.isArray(plugin) && plugin[0] === 'expo-build-properties');

  assert.equal(buildProperties[1].android.usesCleartextTraffic, false);
  assert.deepEqual(config.ios.infoPlist.NSAppTransportSecurity, {});
  assert.ok(config.android.blockedPermissions.includes('android.permission.SYSTEM_ALERT_WINDOW'));
});

test('release config rejects local HTTP or demo credential overrides', () => {
  for (const override of [
    { EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE: 'true' },
    { EXPO_PUBLIC_INGRESS_DEMO_MODE: 'true' },
    { EXPO_PUBLIC_INGRESS_DEMO_MODE: undefined },
  ]) {
    assert.throws(() => withEnvironment({
      ...secureReleaseEnvironment,
      EXPO_PUBLIC_API_URL: 'https://ingress-pp2u.onrender.com/api/v1',
      ...override,
    }, () => resolveAppConfig({ config: appJson.expo })), /disable local HTTP and demo credentials/);
  }
});

test('local development retains the explicit private-LAN HTTP opt-in', () => {
  const config = withEnvironment({ EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE: 'true' }, () => resolveAppConfig({ config: appJson.expo }));
  const buildProperties = config.plugins.find((plugin) => Array.isArray(plugin) && plugin[0] === 'expo-build-properties');

  assert.equal(buildProperties[1].android.usesCleartextTraffic, true);
  assert.deepEqual(config.ios.infoPlist.NSAppTransportSecurity, { NSAllowsLocalNetworking: true });
});
