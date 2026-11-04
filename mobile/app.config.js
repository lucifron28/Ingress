const allowLocalHttp = process.env.EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE === 'true';

module.exports = ({ config }) => ({
  ...config,
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
});
