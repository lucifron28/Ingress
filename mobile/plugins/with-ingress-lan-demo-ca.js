const fs = require('node:fs/promises');
const path = require('node:path');
const { withAndroidManifest, withDangerousMod } = require('expo/config-plugins');
const { readLanDemoCaPem, renderNetworkSecurityConfig, validateLanDemoHost } = require('./ingress-network-security');

module.exports = function withIngressLanDemoCa(config, options = {}) {
  const lanDemo = options.lanDemo === true;
  const caCertPem = lanDemo ? readLanDemoCaPem(process.env.INGRESS_LAN_DEMO_CA_CERT) : null;
  const nodeHost = lanDemo ? validateLanDemoHost(options.nodeHost) : null;

  config = withAndroidManifest(config, (mod) => {
    const application = mod.modResults.manifest.application?.[0];
    if (!application) throw new Error('Android manifest is missing its application node.');
    application.$ ??= {};
    application.$['android:networkSecurityConfig'] = '@xml/ingress_network_security_config';
    return mod;
  });

  return withDangerousMod(config, ['android', async (mod) => {
    const resources = path.join(mod.modRequest.platformProjectRoot, 'app', 'src', 'main', 'res');
    const xmlDirectory = path.join(resources, 'xml');
    const rawDirectory = path.join(resources, 'raw');
    const caTarget = path.join(rawDirectory, 'ingress_lan_demo_ca.pem');

    await fs.mkdir(xmlDirectory, { recursive: true });
    await fs.mkdir(rawDirectory, { recursive: true });
    await fs.writeFile(path.join(xmlDirectory, 'ingress_network_security_config.xml'), renderNetworkSecurityConfig({ lanDemo, nodeHost }), 'utf8');

    if (lanDemo && caCertPem) {
      await fs.writeFile(caTarget, caCertPem, 'utf8');
    } else {
      await fs.rm(caTarget, { force: true });
    }

    return mod;
  }]);
};
