# Deployment and Operations

This guide covers a cloud runtime and a separate Event Node. Use `legacy` only for a local or test environment. The Event Node serves admission on the venue LAN; public internet is not part of the admission path, but the venue LAN and node are required.

For the data-transfer sequence and node-specific commands, see [Second-half architecture](second-half-architecture.md). This guide records the production configuration and operating limits.

## Runtime requirements

- PHP `^8.3` with `pdo_pgsql`, `sodium`, and `xmlwriter` enabled, as required by `api/composer.json` and the PostgreSQL runtime.
- PostgreSQL 18, with a separate database for the cloud and for each Event Node.
- A PHP runtime and web server for each application instance. The Laravel application is served from `api/public`.
- HTTPS for the cloud, Event Node API, and staff mobile connections. The certificate must be trusted by staff devices.
- A secured SMTP transport for institutional email activation on the cloud.

The app binds a database to the first runtime identity that uses it. Do not point cloud and node runtimes at the same database. Each runtime needs its own `APP_KEY`; do not copy the cloud key to a node.

## Configure the cloud

Create a production environment file outside the web root. Set at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cloud.example.edu
APP_KEY=base64:GENERATED_CLOUD_KEY
INGRESS_RUNTIME=cloud
INGRESS_DEMO_MODE=false

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ingress_cloud
DB_USERNAME=ingress_cloud
DB_PASSWORD=REPLACE_WITH_SECRET

SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
INGRESS_CORS_ALLOWED_ORIGINS=https://app.example.edu
TRUSTED_PROXIES=127.0.0.1

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.example.edu
MAIL_PORT=465
MAIL_USERNAME=REPLACE_WITH_SECRET
MAIL_PASSWORD=REPLACE_WITH_SECRET
MAIL_FROM_ADDRESS=ingress@example.edu
```

Replace the sample origins, proxy address, mail host, and credentials with the values for the deployment. `INGRESS_CORS_ALLOWED_ORIGINS` is a comma-separated list of exact browser origins. It does not need to include native mobile apps. `TRUSTED_PROXIES` must name only the reverse proxy addresses or CIDRs that actually sit in front of Laravel. Leave it empty when Laravel receives TLS directly.

Generate a unique application key in the cloud environment with `php artisan key:generate --show`, then store it in the secret manager. Configure the dedicated Ed25519 authority key pair as described in [Transfer authority](second-half-architecture.md#transfer-authority). Keep `INGRESS_AUTHORITY_PRIVATE_KEY` on the cloud only; provide nodes with the public key only.

`APP_DEBUG=true` or `INGRESS_DEMO_MODE=true` prevents the application from booting in production. Configure `APP_URL` with the public HTTPS origin. Email activation rejects insecure or non-SMTP delivery outside local and testing environments.

## Configure an Event Node

Give each Event Node a unique stable ID and its own PostgreSQL database. Configure independent values for `APP_KEY`, database credentials, and the node transfer secret:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://node-1.example.edu
APP_KEY=base64:GENERATED_NODE_KEY
INGRESS_RUNTIME=node
INGRESS_DEMO_MODE=false
INGRESS_NODE_ID=node-1
INGRESS_NODE_SYNC_KEY=SECRET_FROM_CLOUD_NODE_REGISTRATION
INGRESS_CLOUD_SYNC_URL=https://cloud.example.edu/api/v1
INGRESS_AUTHORITY_PUBLIC_KEY=BASE64_CLOUD_AUTHORITY_PUBLIC_KEY

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ingress_node_1
DB_USERNAME=ingress_node_1
DB_PASSWORD=REPLACE_WITH_SECRET

SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
INGRESS_CORS_ALLOWED_ORIGINS=https://staff-app.example.edu
TRUSTED_PROXIES=127.0.0.1
```

Do not set the authority private key on a node. Register the node from the cloud with `php artisan ingress:register-node node-1`; transfer the printed secret to the node through a separate secure channel. The node secret authenticates snapshot transfer and attendance synchronization. Revoking it with `php artisan ingress:revoke-node node-1` blocks future synchronization, but does not stop offline admission or revoke an authority grant already issued. If the secret is exposed, isolate the node from scanners, revoke the cloud credential, and preserve the node database and logs. Do not treat key revocation as node deactivation.

Provision the node database by installing the current application release and applying migrations. Standard events keep their earlier snapshot schema and admission batch schema for compatibility. Snapshot schema version 4 carries ID-check policy, and admission batch schema version 3 carries each staff ID-check acknowledgment. Upgrade the cloud and Event Node before preparing or importing an event that requires ID checks. The application still reads older snapshot and admission batch versions for existing events.

## Install and configure the application

From the `api` directory, install the locked production dependencies and apply migrations after the environment file is in place:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Serve `api/public` through the TLS web server. Deny direct public access to `.env`, `storage`, `vendor`, and the repository. Allow the web process to write only to Laravel's required `storage` and `bootstrap/cache` directories. Run the `/up` health route through the same deployment path used by the service monitor.

When TLS terminates at a reverse proxy, pass the original host and HTTPS scheme and configure only that proxy in `TRUSTED_PROXIES`. Laravel uses the forwarded scheme when enforcing HTTPS on transfer and synchronization endpoints. Do not trust all forwarded headers from public clients.

## Build the mobile app

The mobile app uses Expo SDK 58 and React Native 0.88.1. The EAS `preview` profile creates an internal Android APK; `production` creates an Android App Bundle for store testing. Both release profiles require `EXPO_PUBLIC_API_URL` to contain the deployed cloud HTTPS API base URL ending in `/api/v1`. They reject the placeholder URL, HTTP, demo mode, and HTTP node access. The variable is public client configuration, so do not put credentials or private keys in it.

Set the cloud URL in the matching EAS environment before building. EAS remote builds do not receive a local `.env` file. Replace the sample host with the staging cloud host:

```bash
cd mobile
eas env:set --name EXPO_PUBLIC_API_URL --value https://ingress-staging.example.edu/api/v1 --environment preview --visibility plaintext
eas env:list --environment preview
eas build --platform android --profile preview
```

Before a store testing build, set the actual cloud URL in the EAS `production` environment and run `eas build --platform android --profile production`. Keep the URL limited to the cloud API. Staff pairing supplies the Event Node URL separately. See the [physical-device test plan](physical-device-test-plan.md), the [EAS environment variable guide](https://docs.expo.dev/eas/environment-variables/), and the [Android APK build guide](https://docs.expo.dev/build-reference/apk/).

The app's native configuration disables cleartext HTTP by default. Staff devices must connect to an Event Node URL with a certificate they trust.

For a local HTTP development server or Event Node, set `EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE=true` before starting Expo or generating the native app configuration, and point `EXPO_PUBLIC_API_URL` at the local server when needed. The mobile node client accepts HTTP only for localhost and private LAN hosts when this flag is enabled. Android's native cleartext allowance applies to the whole development build, so never include the flag in a production build. The checked-in `mobile/.env.example` uses a placeholder cloud origin and is not a production environment file.

## Run the node synchronization scheduler

When `INGRESS_RUNTIME=node` and `INGRESS_CLOUD_SYNC_URL` are set, the application schedules `ingress:sync-admissions` once per minute. Configure the host scheduler to invoke Laravel's scheduler:

```cron
* * * * * cd /srv/ingress/api && php artisan schedule:run >> /dev/null 2>&1
```

The sync command sends authenticated HTTPS batches and retries pending work on later runs. If the cloud is unreachable, the Event Node remains the admission authority and retains unsynchronized movements locally. Do not mark a batch synchronized until its authenticated receipt is accepted.

## Prepare an event for admission

1. On the cloud, set the event's identity policy to Standard or Approved ID check before snapshot preparation. Standard displays the attendee name and identifier. Approved ID check requires staff to confirm each movement after checking an approved ID.
2. Close registration, prepare the snapshot, transfer it to the registered node, import it, and return both receipts to the cloud.
3. Issue and activate the authority grant on the intended node. The grant freezes the cloud snapshot and cannot be safely moved to another node by the current recovery flow.
4. Provision node staff credentials and pair scanners to the node's trusted HTTPS address. Limit the venue firewall so staff devices can reach the Event Node API and database traffic cannot be reached from attendee networks.
5. At the venue, scanners use the LAN and Event Node. A scan only previews the ticket; staff must explicitly confirm a movement. Student offline tickets are display-only. Walk-in issuance and admission remain node operations.
6. When internet service returns, allow the node scheduler to send pending admission and walk-in records to the cloud. Review the organizer attendance report and export only the personal data required for the event.

Organizers can download a full attendance CSV. It contains attendee names, identifiers, email addresses, and movement details. The response is marked `private, no-store`; the person who downloads it must store and share it through an approved channel.

## Backups and recovery limits

Back up each PostgreSQL database independently with an encrypted off-host backup process. Store database backups separately from the corresponding `APP_KEY` and authority private key. A database dump combined with its `APP_KEY` can reveal encrypted ticket credentials and snapshot identity data. Test ordinary cloud restore procedures before production use.

Do not clone or restore an activated Event Node database to another live instance while the original node or its authority grant may still be used. A restore can create two independent admission authorities. The current application has no safe automated recovery or authority-transfer procedure after grant issuance.

If an Event Node fails during admission, stop admission at every gate. Preserve its database, `APP_KEY`, authority grant, node sync key, and logs. Do not start a backup on another host until you have isolated the original instance and confirmed that only one instance can admit. If you cannot recover the current database or confirm that the original instance is retired, keep admission stopped. The application cannot fence an unreachable node or reconstruct unsynchronized movements from cloud data.

Cloud snapshots and imported node rosters retain encrypted personal data. Automatic snapshot and roster retention or deletion is not implemented. Establish an institution-approved retention schedule and an operator-owned removal procedure before processing real attendee data. Photo collection is disabled; the app stores no attendee photos.

## Verification boundary

CI runs backend tests, Composer and npm dependency audits, PostgreSQL concurrency and load-recovery checks, mobile session and release-build-configuration tests, and TypeScript compilation. Real-device, venue-network, printer, and end-to-end admission verification remains with the project owner. The current code has not been declared physically verified by this guide.
