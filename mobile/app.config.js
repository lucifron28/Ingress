function validateReleaseApiUrl(value) {
  const message = 'Set EXPO_PUBLIC_API_URL to the deployed cloud HTTPS API URL ending in /api/v1 for EAS release builds.';

  if (!value?.trim()) {
    throw new Error(message);
  }

  let apiUrl;
  try {
    apiUrl = new URL(value);
  } catch {
    throw new Error(message);
  }

  if (apiUrl.protocol !== 'https:'
    || !apiUrl.hostname
    || apiUrl.username
    || apiUrl.password
    || apiUrl.search
    || apiUrl.hash
    || !apiUrl.pathname.replace(/\/+$/, '').endsWith('/api/v1')
    || apiUrl.hostname === 'cloud.example.edu') {
    throw new Error(message);
  }
}

module.exports = ({ config }) => {
  const releaseBuild = process.env.INGRESS_RELEASE_BUILD === 'true';

  if (releaseBuild) {
    validateReleaseApiUrl(process.env.EXPO_PUBLIC_API_URL);

    if (process.env.EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE !== 'false'
      || process.env.EXPO_PUBLIC_INGRESS_DEMO_MODE !== 'false') {
      throw new Error('EAS release profiles must disable local HTTP and demo credentials.');
    }
  }

  const allowLocalHttp = !releaseBuild && process.env.EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE === 'true';
  const androidConfig = { ...config.android };
  const blockedPermissions = new Set(androidConfig.blockedPermissions ?? []);

  if (releaseBuild) {
    blockedPermissions.add('android.permission.SYSTEM_ALERT_WINDOW');
  }

  androidConfig.blockedPermissions = [...blockedPermissions];

  return {
    ...config,
    android: androidConfig,
    plugins: [
      ...(config.plugins ?? []),
      ['expo-build-properties', { android: { usesCleartextTraffic: allowLocalHttp } }],
    ],
    ios: {
      ...config.ios,
      infoPlist: {
        ...config.ios?.infoPlist,
        NSAppTransportSecurity: allowLocalHttp ? { NSAllowsLocalNetworking: true } : {},
      },
    },
  };
};
