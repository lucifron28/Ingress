module.exports = ({ config }) => {
  const releaseBuild = process.env.INGRESS_RELEASE_BUILD === 'true';
  const lanDemoValue = process.env.INGRESS_LAN_DEMO;

  if (releaseBuild && lanDemoValue !== 'true' && lanDemoValue !== 'false') {
    throw new Error('Set INGRESS_LAN_DEMO to true or false for every release build.');
  }
  if (!releaseBuild && lanDemoValue === 'true') {
    throw new Error('INGRESS_LAN_DEMO requires INGRESS_RELEASE_BUILD=true so demo trust stays in its isolated APK profile.');
  }

  const lanDemo = releaseBuild && lanDemoValue === 'true';
  const android = { ...config.android };
  const blockedPermissions = new Set(android.blockedPermissions ?? []);
  if (releaseBuild) blockedPermissions.add('android.permission.SYSTEM_ALERT_WINDOW');
  android.blockedPermissions = [...blockedPermissions];

  return {
    ...config,
    android,
    plugins: [
      ...(config.plugins ?? []),
      ['expo-build-properties', { android: { usesCleartextTraffic: lanDemo } }],
    ],
    extra: {
      ...(config.extra ?? {}),
      ingressLanDemo: lanDemo,
    },
    ios: {
      ...config.ios,
      infoPlist: {
        ...config.ios?.infoPlist,
        NSAppTransportSecurity: {},
      },
    },
  };
};
