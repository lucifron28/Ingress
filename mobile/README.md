# Ingress Staff Scanner

Ingress Scanner is a staff-only Android app for local Event Node pairing, assigned event and gate selection, ticket scanning, walk-ins, and pass recovery. It has no student sign-in, ticket wallet, event discovery, or Cloud API client. The `lanDemo` APK connects directly to the paired Event Node over the isolated venue LAN.

## Local development

Install dependencies and run the checks from this directory:

```powershell
npm ci
npx expo lint
npx tsc --noEmit
node tests/session.test.mjs
node --test tests/build-config.test.mjs tests/scanner-only.test.mjs
```

Staff scan a one-time pairing QR or enter the current Node IP. The app stores the endpoint and Node staff token locally and clears the token after logout or a matching unauthorized response. After the laptop changes networks, staff can sign out and pair with the new address; the APK does not need to be rebuilt.

## Android build profiles

- `preview` creates an installable internal APK that uses Android system certificate trust and requires HTTPS.
- `lanDemo` creates the installable course-final demo APK. It accepts HTTP only for private IPv4 addresses and accepts a different server IP at pairing time. Its sign-in screen identifies this LAN-demo build.
- `production` creates a Play Store AAB, uses Android system certificate trust, and requires HTTPS.

All profiles bundle the JavaScript with the native app; they do not require Expo Metro at runtime. Expo documents internal APK profiles in its [Android APK build guide](https://docs.expo.dev/build-reference/apk/).

## Build the LAN-demo APK

No fixed IP, certificate, or EAS environment variable is needed. The server address is entered by staff or included in the one-time pairing QR when the Event Node starts. Build the APK from this directory:

```powershell
npx eas-cli build --platform android --profile lanDemo
```

Build a system-trust preview APK without the local CA:

```powershell
npx eas-cli build --platform android --profile preview
```

Only use this APK on an isolated, private network controlled by the demo operator. HTTP does not encrypt staff passwords, session tokens, pairing codes, or ticket requests. Do not install it as the standard or production scanner build.
