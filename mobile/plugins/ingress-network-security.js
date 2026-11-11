const { X509Certificate } = require('node:crypto');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');

function validateLanDemoCa(certificatePath) {
  if (typeof certificatePath !== 'string' || certificatePath.trim() === '') {
    throw new Error('Set INGRESS_LAN_DEMO_CA_CERT to the public PEM or DER CA certificate file for the LAN demo build.');
  }

  let certificate;
  try {
    const absolutePath = path.resolve(certificatePath);
    const contents = fs.readFileSync(absolutePath);
    if (contents.length > 64 * 1024) throw new Error('Certificate file is too large.');
    certificate = new X509Certificate(contents);
    const now = Date.now();
    const validFrom = Date.parse(certificate.validFrom);
    const validTo = Date.parse(certificate.validTo);
    if (!certificate.ca || !Number.isFinite(validFrom) || !Number.isFinite(validTo) || validFrom > now || validTo < now) {
      throw new Error('Certificate is not a currently valid CA certificate.');
    }
    return absolutePath;
  } catch {
    throw new Error('INGRESS_LAN_DEMO_CA_CERT must point to a readable, currently valid CA certificate file.');
  }
}

function readLanDemoCaPem(certificatePath) {
  const absolutePath = validateLanDemoCa(certificatePath);
  return new X509Certificate(fs.readFileSync(absolutePath)).toString();
}

function validateLanDemoHost(input) {
  if (typeof input !== 'string' || input.trim() === '') {
    throw new Error('Set INGRESS_LAN_DEMO_NODE_HOST to the exact Event Node hostname or IPv4 address.');
  }

  const value = input.trim();
  let host;
  try {
    const url = new URL(`https://${value}`);
    host = url.hostname.toLowerCase();
    if (url.pathname !== '/' || url.port || url.username || url.password || url.search || url.hash || host.includes('[')) {
      throw new Error('Invalid host.');
    }
  } catch {
    throw new Error('INGRESS_LAN_DEMO_NODE_HOST must be a hostname or IPv4 address without a scheme, path, or port.');
  }

  if (net.isIP(host) !== 4 && !/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/.test(host)) {
    throw new Error('INGRESS_LAN_DEMO_NODE_HOST must be a valid hostname or IPv4 address.');
  }
  return host;
}

function escapeXml(value) {
  return value.replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[character]);
}

function renderNetworkSecurityConfig({ lanDemo = false, nodeHost = null } = {}) {
  const demoConfig = lanDemo
    ? `\n  <domain-config cleartextTrafficPermitted="false">\n    <domain includeSubdomains="false">${escapeXml(validateLanDemoHost(nodeHost))}</domain>\n    <trust-anchors>\n      <certificates src="system" />\n      <certificates src="@raw/ingress_lan_demo_ca" />\n    </trust-anchors>\n  </domain-config>`
    : '';

  return `<?xml version="1.0" encoding="utf-8"?>\n<network-security-config>\n  <base-config cleartextTrafficPermitted="false">\n    <trust-anchors>\n      <certificates src="system" />\n    </trust-anchors>\n  </base-config>${demoConfig}\n</network-security-config>\n`;
}

module.exports = { readLanDemoCaPem, renderNetworkSecurityConfig, validateLanDemoCa, validateLanDemoHost };
