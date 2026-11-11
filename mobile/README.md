# Ingress Staff Scanner

Ingress Scanner is a staff-only Android app for local Event Node pairing, assigned event and gate selection, ticket scanning, walk-ins, and pass recovery. It has no student sign-in, ticket wallet, event discovery, or Cloud API client. Admission requests go directly to the paired Event Node over HTTPS.

## Local development

Install dependencies and run the checks from this directory:

```powershell
npm ci
npx expo lint
npx tsc --noEmit
node tests/session.test.mjs
node --test tests/build-config.test.mjs tests/scanner-only.test.mjs
```

Staff enter an Event Node API address ending in `/api/v1` or scan its one-time pairing QR. The app accepts HTTPS only. It stores the Node staff token in Android SecureStore and clears it after logout or a matching unauthorized response.

## Android build profiles

- `preview` creates an installable internal APK that trusts Android system CAs.
- `lanDemo` creates an installable internal APK that also trusts one supplied CA for one configured Event Node hostname or IPv4 address.
- `production` creates a Play Store AAB and uses Android system CA trust only.

All profiles bundle the JavaScript with the native app; they do not require Expo Metro at runtime. Expo documents internal APK profiles in its [Android APK build guide](https://docs.expo.dev/build-reference/apk/).

## Configure the isolated LAN-demo APK

Use a dedicated CA that is only used to issue the Event Node's HTTPS certificate for the demo. Keep the CA private key with the Node operator. The scanner build needs only the public CA certificate. The Event Node certificate must include the exact host used by staff in its subject alternative name; normal TLS certificate and hostname checks remain enabled.

In the Expo dashboard for this project, open **Project settings → Environment variables** and add these project variables for the **preview** environment:

| Name | Type | Visibility | Value |
| --- | --- | --- | --- |
| `INGRESS_LAN_DEMO_CA_CERT` | File | Sensitive | Upload the public PEM or DER root CA certificate |
| `INGRESS_LAN_DEMO_NODE_HOST` | Text | Plain text | Exact host in the Node URL, such as `192.168.1.25` or `ingress-node.local`; do not include scheme, port, or path |

The CA is included only when the `lanDemo` profile explicitly sets `INGRESS_LAN_DEMO=true`. It is scoped in Android network security configuration to the exact configured host. Other hosts retain system CA trust, and cleartext HTTP remains disabled. `preview` ignores these variables and does not include the custom CA. The production environment does not need either variable.

Build the LAN-demo APK from this directory:

```powershell
npx eas-cli build --platform android --profile lanDemo
```

Build a system-trust preview APK without the local CA:

```powershell
npx eas-cli build --platform android --profile preview
```

The EAS environment used to resolve `app.config.js` must make the CA file available to the build configuration, so use **Sensitive**, not **Secret**, visibility for the file variable. Expo keeps Secret values unavailable during local app-config resolution; see [Expo environment variable visibility and file variables](https://docs.expo.dev/eas/environment-variables/manage/) and [EAS variable usage](https://docs.expo.dev/eas/environment-variables/usage/).

The custom CA APK is for isolated LAN demonstrations. Do not reuse its CA for other services, and do not add the certificate or its private key to Git. Android's [network security configuration](https://developer.android.com/privacy-and-security/security-config) supports per-domain trust anchors; this app uses that mechanism while retaining system trust and HTTPS verification.
