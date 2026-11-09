# Deployment and Operations

This guide covers a cloud runtime and a separate Event Node. Use `legacy` only for a local or test environment. The Event Node serves admission on the venue LAN; public internet is not part of the admission path, but the venue LAN and node are required.

For the data-transfer sequence and node-specific commands, see [Second-half architecture](second-half-architecture.md). This guide records the production configuration and operating limits.

## Runtime requirements

- PHP `^8.3` as required by `api/composer.json`; the Render Docker image uses PHP 8.4 with `pdo_pgsql`, `sodium`, and `xmlwriter`.
- PostgreSQL 18, with a separate database for the cloud and for each Event Node.
- A PHP runtime and web server for each application instance. The Laravel application is served from `api/public`.
- HTTPS for the cloud, Event Node API, and staff mobile connections. The certificate must be trusted by staff devices.
- Resend's HTTPS API for student verification email on the cloud.

The app binds a database to the first runtime identity that uses it. Do not point cloud and node runtimes at the same database. Each runtime needs its own `APP_KEY`; do not copy the cloud key to a node.

## Configure the cloud on Render and Neon

This deployment uses the `ingress-cloud` Docker web service on Render Free and a separate PostgreSQL 18 database on Neon. Keep Event Node databases on their own hosts. Never share one database between cloud and node runtimes.

### Create the Neon database

In Neon organization `org-winter-wave-74338525`, use project `sweet-silence-93495570`, branch `production`, Singapore region (`aws-ap-southeast-1`), and PostgreSQL 18. Create a database role dedicated to Ingress. Copy the direct connection string, not the pooled (`-pooler`) endpoint. Add `sslmode=verify-full` to its query string; Neon connection examples may use `require`, which is not sufficient for this deployment. The container uses the system CA bundle at `/etc/ssl/cert.pem` to verify both the certificate chain and hostname. See [Neon's TLS verification guidance](https://neon.com/docs/security/security-overview) for the certificate requirements.

Neon's published [PostgreSQL 18 announcement](https://neon.com/blog/postgres-18) still labels Neon availability as preview and recommends waiting before using it for production. This repository deliberately rejects database versions other than PostgreSQL 18. Check the Neon Console and current provider notices before creating the database; if PostgreSQL 18 is still marked preview, keep this setup to evaluation and test data only. Do not use it for real student or event records.

Keep the database URL private. Do not paste it into source control, issue comments, build arguments, or logs. Neon branches/projects can suspend when idle; the first connection after suspension may take longer.

### Create the Resend sender

In Resend, add and verify the sending domain from the Dashboard's **Domains** page. From **API Keys**, create a key with **Sending access** restricted to that domain. Set `MAIL_FROM_ADDRESS` to a verified address on it. The key is a secret and belongs only in Render's environment settings. See [Resend API key permissions](https://resend.com/changelog/new-api-key-permissions). Use a test recipient or a Resend test domain while validating email; do not send unsolicited messages.

### Create the Render web service

In Render, create a **Web Service** from the Ingress repository with these settings:

| Setting | Value |
| --- | --- |
| Name | `ingress-cloud` |
| Branch | `main` |
| Runtime | Docker |
| Root Directory | `api` |
| Dockerfile Path | `./Dockerfile` |
| Instance Type | Free |
| Region | Singapore |
| Health Check Path | `/up` |
| Pre-Deploy Command | Leave unset |

Do not add a persistent disk. Render Free does not provide one. The image contains the application release; database state belongs in Neon.

Set the following in **Render → ingress-cloud → Environment**. Add secret values through the dashboard, not in a checked-in file:

| Variable | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | The public HTTPS origin only; path must be empty or `/`, with no query or fragment |
| `APP_KEY` | A fresh Laravel key generated for this cloud runtime |
| `INGRESS_RUNTIME` | `cloud` |
| `INGRESS_DEMO_MODE` | `false` |
| `INGRESS_AUTHORITY_PRIVATE_KEY` | Base64 Ed25519 private signing key; keep secret in Render |
| `INGRESS_AUTHORITY_PUBLIC_KEY` | Matching Base64 Ed25519 public key |
| `TRUSTED_PROXIES` | `REMOTE_ADDR` |
| `INGRESS_TRUSTED_HOSTS` | Leave empty unless using a custom domain; if set, use comma-separated exact hostnames |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | Neon direct connection URL with `sslmode=verify-full` |
| `DB_SSLMODE` | `verify-full` |
| `DB_SSLROOTCERT` | `/etc/ssl/cert.pem` |
| `SESSION_DRIVER` | `database` |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_SECURE_COOKIE` | `true` |
| `CACHE_STORE` | `database` |
| `APP_MAINTENANCE_DRIVER` | `cache` |
| `APP_MAINTENANCE_STORE` | `database` |
| `QUEUE_CONNECTION` | `sync` |
| `LOG_CHANNEL` | `stderr` |
| `LOG_LEVEL` | `info` |
| `MAIL_MAILER` | `resend` |
| `RESEND_API_KEY` | The Resend API key |
| `MAIL_FROM_ADDRESS` | An address on the verified Resend domain |
| `MAIL_FROM_NAME` | `Ingress` or the approved sender name |

If the Render URL is `https://ingress-cloud.onrender.com`, use that exact origin for `APP_URL`. Add custom domains only after Render reports HTTPS is active. Set `INGRESS_CORS_ALLOWED_ORIGINS` only when a browser client needs cross-origin API access; use exact HTTPS origins, comma-separated. Native mobile clients do not need a CORS entry.

Generate `APP_KEY` on your Windows development machine from `api` using `php artisan key:generate --show`. Copy its output directly into the Render `APP_KEY` secret field. Do not commit the output or reuse a key from an Event Node.

Generate the cloud's Ed25519 signing pair from PowerShell in a trusted PHP environment with `sodium` enabled:

```powershell
php -r '$pair=sodium_crypto_sign_keypair(); echo "INGRESS_AUTHORITY_PRIVATE_KEY=",base64_encode(sodium_crypto_sign_secretkey($pair)),PHP_EOL,"INGRESS_AUTHORITY_PUBLIC_KEY=",base64_encode(sodium_crypto_sign_publickey($pair)),PHP_EOL;'
```

Set both values in Render. Keep the private key only in Render's secret environment settings. Provide the public key to Event Nodes through the trusted provisioning channel described in [Transfer authority](second-half-architecture.md#transfer-authority). Keep this pair stable while any grant it signed may still need activation.

Render terminates HTTPS before forwarding requests. The app trusts only the immediate peer address (`REMOTE_ADDR`) and accepts only forwarded scheme and client-IP headers. It validates the `Host` separately using `APP_URL` and `INGRESS_TRUSTED_HOSTS`. Do not set `TRUSTED_PROXIES=*`, trust forwarded host headers, or disable the production HTTPS checks.

The container runs migrations during startup because Render Free has no pre-deploy command. A PostgreSQL session advisory lock serializes startup migration and admin creation. Startup waits up to five minutes to acquire the lock, checks PostgreSQL 18, applies migrations, verifies the cloud authority signing key pair, and enforces the cloud runtime database identity before opening HTTP traffic. The service fails to start if configuration, TLS verification, migration, signing-key validation, or runtime identity validation fails. Use Neon’s direct endpoint for this session-scoped lock. Render health checks call `/up`; it does not return database details.

The Laravel session and cache stores use PostgreSQL; no required state is written to the container filesystem. Logs go to standard output/error. Access logs omit query strings, cookies, and request bodies. Startup errors are static and do not include credentials or connection URLs.

### Generate the first admin account

Render Free has no interactive shell or one-off command. After the first successful deployment, use a trusted Windows machine with PHP 8.4, the `pdo_pgsql` extension, Composer, and a PEM CA bundle trusted for Neon connections. From a repository checkout, install the locked dependencies once:

```powershell
cd .\api
composer install --no-dev --prefer-dist --no-interaction
cd ..
```

Then create the initial verified administrator:

```powershell
powershell -ExecutionPolicy Bypass -File .\api\scripts\create_render_admin.ps1
```

The script prompts securely for the Neon `DB_URL`, the Render `APP_KEY`, the public HTTPS `APP_URL`, and a local PostgreSQL CA bundle path. Use a trusted certificate bundle from your PostgreSQL distribution or operating system; do not disable certificate verification to work around a missing bundle. It passes these settings only to the Artisan command's process environment and clears them afterward. The command checks PostgreSQL 18, HTTPS/TLS settings, cloud runtime identity, and the shared migration lock before creating a verified administrator. Choose a unique password in the prompt and store it in the institution's approved password manager. Do not run this script on an untrusted machine.

### Verify the deployment

After Render reports the deployment as live:

1. Open `https://<your-render-host>/up` and confirm an HTTP 200 response.
2. In Render logs, confirm the fixed startup message `Cloud database migrations and runtime identity are ready.` Do not copy secret environment values into logs or support tickets.
3. In Neon SQL Editor, confirm `select current_setting('server_version_num');` starts with `180`, and confirm the expected Ingress migration rows exist in `migrations`.
4. Sign in with the administrator created above. Request a student verification email using a test address, and confirm Resend records a successful delivery. The app must use HTTPS and a verified sender.
5. Confirm the service can be reached over HTTPS and that production debug/demo mode remain disabled.

This checklist checks the cloud service only. It does not establish physical-device, venue-network, printer, admission, or Event Node verification; see the [physical-device test plan](physical-device-test-plan.md).

### Render Free and service limits

- Provider quotas below were checked on 2026-11-09. Render Free web services spin down after 15 minutes without inbound traffic and take about a minute to wake. Each workspace receives 750 free instance hours per calendar month. Render says Free services are intended for evaluation, not production workloads. See [Render Free limitations](https://render.com/docs/free).
- Free services have an ephemeral filesystem, one instance, no persistent disk, no SSH/shell, and no pre-deploy or one-off command. Redeploys and restarts must not be relied on to preserve files.
- Neon Free currently includes 1 GB of storage and 100 compute-unit hours per project each month, with autoscaling up to 2 compute units. Free computes can scale to zero. Check [Neon pricing and limits](https://neon.com/pricing) before use; a suspended or quota-limited database can delay or prevent service startup.
- Resend Free currently allows 3,000 emails per month, 100 per day, and 3 sending domains, with 30-day data retention. Check [Resend pricing](https://resend.com/pricing) before use.
- The Render/Neon/Resend combination is suitable for preparing and evaluating the deployment. Render Free is not a production availability or data-retention guarantee. Choose paid or institution-managed service capacity before real event operations.

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

## Install an Event Node or self-hosted instance

From the `api` directory, install the locked production dependencies and apply migrations after that instance's environment is in place:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Serve `api/public` through the TLS web server. Deny direct public access to `.env`, `storage`, `vendor`, and the repository. Allow the web process to write only to Laravel's required `storage` and `bootstrap/cache` directories. Run the `/up` health route through the same deployment path used by the service monitor. The Render cloud service uses its container entrypoint to run migrations under the shared PostgreSQL advisory lock before starting PHP-FPM and Nginx; do not add a second uncoordinated migration command to Render.

When TLS terminates at a reverse proxy, configure only the trusted proxy peers and allow only the forwarded headers needed to recover the client IP and HTTPS scheme. Validate the request host separately. Laravel uses the forwarded scheme when enforcing HTTPS on transfer and synchronization endpoints. Do not trust all forwarded headers from public clients.

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
