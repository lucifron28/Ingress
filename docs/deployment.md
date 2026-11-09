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

For an account-owner connectivity test before you own a sending domain, use `onboarding@resend.dev` as `MAIL_FROM_ADDRESS` and `lucifron28@gmail.com` as `INGRESS_MAIL_TEST_RECIPIENT`. Resend's shared test sender is restricted to account-owner testing; it cannot activate student accounts at other domains. The mail test sends a static message without a verification token or link.

To send student activation messages to real `@student.mseuf.edu.ph` inboxes, add and verify a sending domain from the Resend Dashboard's **Domains** page. From **API Keys**, create a key with **Sending access** restricted to that domain and set `MAIL_FROM_ADDRESS` to an address on it. The key is a secret and belongs only in Render's environment settings. See [Resend API key permissions](https://resend.com/changelog/new-api-key-permissions) and the [domain verification guide](https://resend.com/docs/dashboard/domains/introduction). A verified sender domain and an authorized institutional mailbox are required for real student activation.

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
| `APP_URL` | `https://ingress-pp2u.onrender.com` (public HTTPS origin only; no path, query, or fragment) |
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
| `MAIL_FROM_ADDRESS` | `onboarding@resend.dev` for account-owner-only testing, or an address on the verified sending domain |
| `MAIL_FROM_NAME` | `Ingress` or the approved sender name |
| `INGRESS_MAIL_TEST_RECIPIENT` | `lucifron28@gmail.com` for the authorized shared-sender test |

Use the current Render origin `https://ingress-pp2u.onrender.com` for `APP_URL`. If Render later assigns a different domain, replace it with the exact HTTPS origin shown on the service. Add custom domains only after Render reports HTTPS is active. Set `INGRESS_CORS_ALLOWED_ORIGINS` only when a browser client needs cross-origin API access; use exact HTTPS origins, comma-separated. Native mobile clients do not need a CORS entry.

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

### Prepare synthetic staging accounts and venues

Render Free has no shell, so run the operator scripts from a trusted Windows checkout with PHP 8.4, `pdo_pgsql`, Composer dependencies installed, and a trusted Neon CA bundle. Use only a Neon database reserved for staging. The preflight checks the cloud runtime identity and PostgreSQL 18, but it cannot determine whether a database contains real records.

Create a synthetic organizer, staff member, or student and seed only the four reference venues:

```powershell
powershell -ExecutionPolicy Bypass -File .\api\scripts\provision_render_staging_account.ps1
```

The script asks for the role and requires typing `STAGING` before connecting. It seeds `VenueSeeder`, then runs `ingress:provision-staging-account`. Use an email ending in `@example.test`; students also require an ID beginning with `STAGE-`. The password is entered through hidden prompts, hashed, and never printed. Synthetic students remain unverified. Repeating the same role and email leaves the existing account unchanged. The tool cannot create administrators; use `create_render_admin.ps1` for the first admin. After creating the organizer, sign in at `/login` and create events with synthetic titles, schedules, attendees, and gate names.

Register a node from the same trusted Windows checkout:

```powershell
powershell -ExecutionPolicy Bypass -File .\api\scripts\register_render_node.ps1
```

Use a new stable node ID. The command checks HTTPS/runtime configuration, direct Neon TLS, PostgreSQL 18, the cloud identity, and the migration advisory lock. It prints `INGRESS_NODE_SYNC_KEY` once after successful registration. Copy it directly into the matching node's private environment, then clear the terminal and do not save it in shell history, source control, or logs. Keep each node on a separate PostgreSQL database with a separate `APP_KEY`.

Test Resend from Windows without sending a student verification link:

```powershell
powershell -ExecutionPolicy Bypass -File .\api\scripts\send_render_mail_test.ps1
```

Enter `onboarding@resend.dev` until a sending domain is verified, and use the address registered to the Resend account as the recipient. The command sends a static message through Laravel's official Resend transport and reports when Resend accepts the request. Check **Resend → Emails** and the authorized inbox to distinguish provider acceptance from final inbox delivery. This test does not activate a student account. Gmail addresses are not accepted by public student registration; real student activation still requires the institutional domain and an authorized student mailbox.

### Verify the deployment

After Render reports the deployment as live:

1. Open `https://<your-render-host>/up` and confirm an HTTP 200 response.
2. In Render logs, confirm the fixed startup message `Cloud database migrations and runtime identity are ready.` Do not copy secret environment values into logs or support tickets.
3. In Neon SQL Editor, confirm `select current_setting('server_version_num');` starts with `180`, and confirm the expected Ingress migration rows exist in `migrations`.
4. Sign in with the administrator created above. Run the Windows Resend connectivity script and inspect **Resend → Emails**. To test student activation, use a verified sender domain and an authorized `@student.mseuf.edu.ph` mailbox. The shared Resend testing sender does not deliver verification messages to arbitrary student addresses.
5. Confirm the service can be reached over HTTPS and that production debug/demo mode remain disabled.

This checklist checks the cloud service only. It does not establish physical-device, venue-network, printer, admission, or Event Node verification; see the [physical-device test plan](physical-device-test-plan.md).

### Render Free and service limits

- Provider quotas below were checked on 2026-11-09. Render Free web services spin down after 15 minutes without inbound traffic and take about a minute to wake. Each workspace receives 750 free instance hours per calendar month. Render says Free services are intended for evaluation, not production workloads. See [Render Free limitations](https://render.com/docs/free).
- Free services have an ephemeral filesystem, one instance, no persistent disk, no SSH/shell, and no pre-deploy or one-off command. Redeploys and restarts must not be relied on to preserve files.
- Neon Free currently includes 1 GB of storage and 100 compute-unit hours per project each month, with autoscaling up to 2 compute units. Free computes can scale to zero. Check [Neon pricing and limits](https://neon.com/pricing) before use; a suspended or quota-limited database can delay or prevent service startup.
- Resend Free currently allows 3,000 emails per month, 100 per day, and 3 sending domains, with 30-day data retention. Check [Resend pricing](https://resend.com/pricing) before use.
- The Render/Neon/Resend combination is suitable for preparing and evaluating the deployment. Render Free is not a production availability or data-retention guarantee. Choose paid or institution-managed service capacity before real event operations.

## Configure a Windows Event Node

Ingress supports two Windows-hosted Event Node profiles. Use WSL2 with Ubuntu 26.04 and Nginx when the venue PC runs Windows 11 22H2 or later and mirrored WSL networking is permitted. Use native Windows IIS otherwise. Select one profile per host; do not run two copies of an activated Event Node. Both profiles use the same Laravel release, PostgreSQL schema, HTTPS API, scanner pairing flow, admission logic, and bounded synchronization scheduler. The WSL profile uses PHP 8.5 from Ubuntu because the locked Symfony packages require PHP 8.4.1 or later; IIS continues to use PHP 8.4 NTS. CI exercises both supported runtimes.

### Preferred profile: WSL2 Ubuntu 26.04 with Nginx

This profile requires Windows 11 22H2 or later, WSL 2, Ubuntu 26.04 LTS, a Windows host with a DHCP-reserved venue address, and an event hostname covered by a publicly trusted TLS certificate. Mirrored networking is required for staff phones to reach Nginx in WSL. Microsoft documents mirrored mode and its Windows 11 version requirement in the [WSL networking guide](https://learn.microsoft.com/en-us/windows/wsl/networking). This changes networking for WSL distributions on the Windows account, so use a dedicated venue host or review other WSL workloads first.

From an elevated PowerShell window, install or update WSL and Ubuntu if needed. If Ubuntu is already installed, keep its existing data and confirm it uses WSL 2:

```powershell
wsl --update
wsl --list --verbose
# Run this only if Ubuntu-26.04 is not listed above.
wsl --install --distribution Ubuntu-26.04
```

In `%UserProfile%\.wslconfig`, enable mirrored networking:

```ini
[wsl2]
networkingMode=mirrored
```

In Ubuntu, enable systemd in `/etc/wsl.conf`:

```ini
[boot]
systemd=true
```

Return to PowerShell and restart WSL so both settings take effect:

```powershell
wsl --shutdown
wsl --distribution Ubuntu-26.04
wsl --list --verbose
```

`wsl --shutdown` stops every running WSL distribution for this Windows account. Schedule it when other WSL applications can be paused.

Install Nginx, PHP 8.5 FPM and the Laravel extensions, Composer, Git, and the scheduler. Ubuntu 26.04 provides PHP 8.5 in its distribution archive ([`php8.5-fpm` package](https://packages.ubuntu.com/resolute/php8.5-fpm)). The lockfile's Symfony 8.1 packages require PHP 8.4.1 or later, so PHP 8.3 from Ubuntu 24.04 cannot install this exact release even though the root Composer constraint is `^8.3`. Node.js 24 is needed only to build Laravel's frontend assets; install it under the deployment account with the pinned [nvm v0.41.0 release](https://github.com/nvm-sh/nvm/releases/tag/v0.41.0), then select a maintained Node 24 release from the [official Node.js downloads](https://nodejs.org/en/download):

```bash
sudo apt update
sudo apt install -y nginx php8.5-cli php8.5-fpm php8.5-pgsql php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-bcmath php8.5-intl composer git unzip cron curl ca-certificates
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.41.0/install.sh | bash
source ~/.bashrc
nvm install 24
nvm use 24
php8.5 -v
php8.5 -m | grep -E '^(mbstring|pdo_pgsql|sodium|xmlwriter)$'
node --version
npm --version
```

Ingress requires `pdo_pgsql`, `sodium`, and `xmlwriter`; the commands above print those module names without displaying application secrets. Do not install a third-party PHP PPA. Ubuntu's PHP packages and the upstream [PHP version support policy](https://www.php.net/supported-versions.php) are the runtime source for this profile.

Install PostgreSQL 18 from the PostgreSQL project's apt repository. Ubuntu's default PostgreSQL version may differ. Follow the project's [Ubuntu installation instructions](https://www.postgresql.org/download/linux/ubuntu/):

```bash
sudo apt install -y postgresql-common
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh
sudo apt update
sudo apt install -y postgresql-18 postgresql-client-18
```

Keep PostgreSQL loopback-only. Set `listen_addresses = 'localhost'` in `/etc/postgresql/18/main/postgresql.conf`, then restart and confirm the listener:

```bash
sudo systemctl enable --now postgresql
sudo systemctl restart postgresql
sudo -u postgres psql -c 'SHOW listen_addresses;'
ss -ltn | grep ':5432'
```

The database must listen only on `127.0.0.1:5432` and/or `[::1]:5432`, never `0.0.0.0:5432` or `[::]:5432`. Create one role and database for this node. Enter the password at PostgreSQL's hidden prompt; do not put it in shell history:

```bash
sudo -u postgres psql
```

At the `psql` prompt:

```sql
CREATE ROLE ingress_node_1 LOGIN;
\password ingress_node_1
CREATE DATABASE ingress_node_1 OWNER ingress_node_1;
\q
```

Check out an approved, reviewed application commit into `/srv/ingress`. Keep the deployed commit fixed for the event. Do not update or replace an activated node during admission:

```bash
sudo install -d -o "$USER" -g www-data -m 0750 /srv/ingress
git clone https://github.com/lucifron28/Ingress.git /srv/ingress
cd /srv/ingress/api
git checkout REPLACE_WITH_REVIEWED_COMMIT
npm ci
npm run build
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
cp .env.example .env
chmod 0640 .env
php artisan key:generate
```

Edit `/srv/ingress/api/.env` with this node's independent `APP_KEY`, database password, and one-time sync key. Keep the cloud runtime and each Event Node on separate databases and application keys. Do not place the cloud authority private key on this node. Use:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://node-1.example.edu
INGRESS_RUNTIME=node
INGRESS_DEMO_MODE=false
INGRESS_NODE_ID=node-1
INGRESS_NODE_SYNC_KEY=SECRET_FROM_CLOUD_NODE_REGISTRATION
INGRESS_CLOUD_SYNC_URL=https://ingress-pp2u.onrender.com/api/v1
INGRESS_AUTHORITY_PUBLIC_KEY=BASE64_CLOUD_AUTHORITY_PUBLIC_KEY
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ingress_node_1
DB_USERNAME=ingress_node_1
DB_PASSWORD=REPLACE_WITH_NODE_DATABASE_PASSWORD
DB_SSLMODE=disable
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_DRIVER=database
CACHE_STORE=database
APP_MAINTENANCE_DRIVER=cache
APP_MAINTENANCE_STORE=database
TRUSTED_PROXIES=
```

Set `INGRESS_NODE_ID` to the cloud-registered node ID. Transfer the sync key out of band and copy only the cloud authority public key. After editing `.env`, apply migrations and build Laravel's production caches:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Install a publicly trusted certificate whose name matches `APP_URL`, using DNS-01 validation. Certificate issuance and renewal are not automated by this repository. Put the full chain at `/etc/ssl/ingress-node/fullchain.pem` and the private key at `/etc/ssl/ingress-node/privkey.pem`; keep the key readable only by root. Do not add an HTTP listener, use a private CA for scanner devices, or disable certificate verification. For campus-only DNS, use split-horizon DNS to resolve the same public hostname to the Windows host's reserved LAN address.

Create the certificate directory as root and install the TLS files with root-only private-key access:

```bash
sudo install -d -o root -g root -m 0700 /etc/ssl/ingress-node
sudo install -o root -g root -m 0600 /path/to/fullchain.pem /etc/ssl/ingress-node/fullchain.pem
sudo install -o root -g root -m 0600 /path/to/privkey.pem /etc/ssl/ingress-node/privkey.pem
```

Give Nginx and PHP-FPM group read access to code and write access only to Laravel's `storage` and `bootstrap/cache` directories. Run these commands from `/srv/ingress` as the deployment account:

```bash
sudo chown -R "$USER":www-data /srv/ingress
sudo find /srv/ingress -type d -exec chmod 0750 {} \;
sudo find /srv/ingress -type f -exec chmod 0640 {} \;
sudo find api/storage api/bootstrap/cache -type d -exec chmod 2770 {} \;
sudo find api/storage api/bootstrap/cache -type f -exec chmod 0660 {} \;
```

Install the checked-in Nginx profile, changing `node-1.example.edu` if needed and preserving the `/srv/ingress/api/public` root, certificate paths, and PHP-FPM socket:

```bash
sudo install -o root -g root -m 0644 deploy/wsl/nginx/ingress-node.conf /etc/nginx/sites-available/ingress-node
sudo ln -s /etc/nginx/sites-available/ingress-node /etc/nginx/sites-enabled/ingress-node
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl enable --now php8.5-fpm nginx cron
sudo systemctl reload nginx
```

Do not make the repository or `.env` writable by the web process. Use a dedicated deployment account and keep `.env` readable only to that account and the `www-data` group. The site rejects unknown TLS hosts, serves only HTTPS on port 443, routes Laravel through `api/public`, denies direct requests for `.env`, `.git`, `vendor`, and private Laravel directories, and does not trust or forward arbitrary proxy headers.

To start the WSL services after Windows reboots, create a Windows Task Scheduler task under the Windows account that owns the Ubuntu distribution. Set the trigger to **At startup**, enable **Run whether user is logged on or not**, and use the action below. Replace `Ubuntu-26.04` with the exact name from `wsl --list --quiet` if it differs:

- Program: `%SystemRoot%\System32\wsl.exe`
- Arguments: `--distribution Ubuntu-26.04 --exec /usr/bin/systemctl start postgresql php8.5-fpm nginx cron`

Set the task not to start a second instance when one is already running. The Linux services are also enabled under systemd, so WSL starts them when the distribution starts. Prevent Windows sleep during event operations and use wired power and network. Test a full Windows reboot and service recovery before the venue; this guide has not performed that field test.

In elevated PowerShell, keep WSL inbound traffic blocked by default and allow HTTPS only from the staff-device subnet. Replace the example subnet before creating the rule. The WSL creator ID and firewall commands follow Microsoft's [Hyper-V firewall guidance](https://learn.microsoft.com/en-us/windows/security/operating-system-security/network-security/windows-firewall/hyper-v-firewall):

```powershell
$wslCreatorId = '{40E0AC32-46A5-438A-A0B2-2B479E8F2E90}'
$staffSubnet = '192.168.50.0/24'
Set-NetFirewallHyperVVMSetting -Name $wslCreatorId -DefaultInboundAction Block
New-NetFirewallHyperVRule -Name 'IngressNodeHTTPS' -DisplayName 'Ingress Event Node HTTPS' -Direction Inbound -VMCreatorId $wslCreatorId -Protocol TCP -LocalPorts 443 -RemoteAddresses $staffSubnet -Action Allow
Get-NetFirewallHyperVVMSetting -Name $wslCreatorId | Select-Object Name, DefaultInboundAction
Get-NetFirewallHyperVRule -Name 'IngressNodeHTTPS'
```

Do not create a broad inbound rule for WSL. Confirm Windows has no other rule that exposes TCP 443 beyond the approved staff network. In the router and campus firewall, allow the same staff subnet and block attendee or guest networks. Do not expose PostgreSQL port 5432.

Run the host-local HTTPS health check without disabling certificate validation. It must return HTTP 200 while PostgreSQL still reports a loopback-only listener:

```bash
curl --fail --proto '=https' --tlsv1.2 --resolve node-1.example.edu:443:127.0.0.1 https://node-1.example.edu/up
ss -ltn | grep ':5432'
sudo nginx -t
```

Use `sudo systemctl stop nginx` for a graceful web-server stop. Stop admission at every gate before stopping the Event Node or Windows host. Keep the database and backups on WSL's Linux filesystem, not under `/mnt/c`; WSL's virtual disk survives WSL restarts and ordinary Windows reboots, but it is not a backup. Back up the node database using the recovery rules below.

### Compatibility profile: native Windows IIS

The Windows Event Node can run directly on Windows without Docker. Use IIS with PHP FastCGI for the web process; do not expose Laravel's development server to the staff network. PHP's Windows guidance recommends a non-thread-safe build with FastCGI. Install PHP 8.4 NTS x64 with `php.exe` and `php-cgi.exe`, Composer, PostgreSQL 18, the IIS CGI feature, and the IIS URL Rewrite module. The checked-in [`api/public/web.config`](../api/public/web.config) disables directory listing, blocks hidden deployment paths, caps requests at 32 MiB, and routes Laravel requests to `index.php`; IIS needs URL Rewrite for that rule. See the [PHP IIS installation guide](https://www.php.net/manual/en/install.windows.iis.php) and [IIS FastCGI configuration](https://learn.microsoft.com/en-us/iis/configuration/system.webserver/fastcgi/).

Unzip PHP to `C:\PHP`, add that directory to the system `PATH`, and create `php.ini` from the production template. In `php.ini`, set `extension_dir="ext"` and enable `mbstring`, `openssl`, `pdo_pgsql`, `sodium`, and `xmlwriter`. Then check both PHP entry points:

```powershell
$phpHome = 'C:\PHP'
Copy-Item "$phpHome\php.ini-production" "$phpHome\php.ini"
notepad "$phpHome\php.ini"
& "$phpHome\php.exe" -m | Select-String '^(mbstring|openssl|pdo_pgsql|sodium|xmlwriter)$'
& "$phpHome\php-cgi.exe" -v
```

Give the node a stable campus DNS name and reserve its LAN address in DHCP. Obtain a publicly trusted certificate whose DNS name matches the Event Node URL, then bind only that hostname on HTTPS port 443 in IIS. Do not add an HTTP binding. Android release builds use the system certificate authorities. Android does not trust user-installed certificate authorities by default, so installing a private CA root in the phone's user certificate store will not make the release app trust it. See [Android Network Security Configuration](https://developer.android.com/privacy-and-security/security-config). Prefer a public certificate with DNS-01 validation and split-horizon DNS that resolves the node hostname to its LAN address on campus. If the venue cannot use a publicly trusted certificate, stop device testing until a hostname-specific native trust configuration has been reviewed and tested; do not add a broad user-CA or trust-all exception.

Create a dedicated PostgreSQL role and database for this node. The commands below use the PostgreSQL 18 default port; adjust the port if the local service uses another one. Run `psql` as the database administrator, then enter the password at PostgreSQL's hidden prompt instead of putting it in a command or shell history:

```powershell
$pgBin = 'C:\Program Files\PostgreSQL\18\bin'
& "$pgBin\psql.exe" -W -h 127.0.0.1 -p 5432 -U postgres -d postgres
```

At the `psql` prompt, run these once for the node:

```sql
CREATE ROLE ingress_node_1 LOGIN;
\password ingress_node_1
CREATE DATABASE ingress_node_1 OWNER ingress_node_1;
\q
```

Keep PostgreSQL listening on loopback only (`listen_addresses = 'localhost'`) and do not open its port in Windows Firewall. Staff phones connect to IIS over HTTPS; only the Laravel process connects to PostgreSQL.

Install the locked backend dependencies and prepare a private `.env` file from the repository checkout:

```powershell
cd C:\Ingress\api
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
Copy-Item .env.example .env
php artisan key:generate
notepad .env
```

Use a separate working copy for the node and never reuse the cloud or another node's `APP_KEY`. Fill in the node settings below, then run migrations and cache the application configuration:

```powershell
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

In IIS, create a website named `IngressNode` and an application pool named `IngressNode`. Set the pool to **No Managed Code**, use the `ApplicationPoolIdentity` identity, and enable automatic startup. Set the site's physical path to `C:\Ingress\api\public`, configure a FastCGI handler for `*.php` that calls `C:\PHP\php-cgi.exe`, install the URL Rewrite module, and add an HTTPS binding for the node DNS name with its trusted certificate. Grant `IIS AppPool\IngressNode` read/execute access to the application and modify access only to `api\storage` and `api\bootstrap\cache`. Use a separate least-privilege Windows service account for Task Scheduler. Give it read/execute access to the application, read access to `.env`, and modify access only to those same two writable directories; do not run it as an administrator. Do not make the repository root the IIS site root. Set PHP's `post_max_size` to `32M` and `upload_max_filesize` to `30M` to stay within the 32 MiB IIS request cap.

In an elevated PowerShell window, first confirm that no broader inbound rule already allows TCP port 443 for this host. Then allow HTTPS from the staff-device subnet. Replace the example subnet before running this rule, and keep Windows Firewall's inbound default set to block:

```powershell
$staffSubnet = '192.168.50.0/24'
New-NetFirewallRule -DisplayName 'Ingress Event Node HTTPS' -Direction Inbound -Protocol TCP -LocalPort 443 -RemoteAddress $staffSubnet -Action Allow
```

Use a staff Wi-Fi network that can reach the reserved node address; disable client isolation for staff devices if it blocks phone-to-node traffic. Keep attendee and guest Wi-Fi separate. Do not open database port 5432 to either network.

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
INGRESS_CLOUD_SYNC_URL=https://ingress-pp2u.onrender.com/api/v1
INGRESS_AUTHORITY_PUBLIC_KEY=BASE64_CLOUD_AUTHORITY_PUBLIC_KEY

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ingress_node_1
DB_USERNAME=ingress_node_1
DB_PASSWORD=REPLACE_WITH_SECRET

SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_DRIVER=database
CACHE_STORE=database
APP_MAINTENANCE_DRIVER=cache
APP_MAINTENANCE_STORE=database
TRUSTED_PROXIES=
```

Replace `node-1.example.edu` with the node's real campus DNS name and use a certificate valid for that name.

Set `INGRESS_NODE_ID` to the same ID registered by `register_render_node.ps1`. Copy the printed one-time sync key into the node's private `.env` and copy only the cloud authority **public** key. Keep `APP_DEBUG=false`, `INGRESS_DEMO_MODE=false`, `SESSION_DRIVER=database`, and `CACHE_STORE=database`. For direct IIS HTTPS, leave `TRUSTED_PROXIES` empty; trust a proxy only if one is installed between IIS and the phone, and then list that exact peer. Never set it to `*`.

Do not set the authority private key on a node. Register it from a trusted Windows checkout with `powershell -ExecutionPolicy Bypass -File .\api\scripts\register_render_node.ps1`; transfer the one-time printed secret to the node through a separate secure channel. The node secret authenticates snapshot transfer and attendance synchronization. Revoking it with `php artisan ingress:revoke-node node-1` blocks future synchronization, but does not stop offline admission or revoke an authority grant already issued. If the secret is exposed, isolate the node from scanners, revoke the cloud credential, and preserve the node database and logs. Do not treat key revocation as node deactivation.

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

The staging cloud API is `https://ingress-pp2u.onrender.com/api/v1`. In Expo's project dashboard, add `EXPO_PUBLIC_API_URL` to the **preview** environment as a plain-text value with that URL, or set it from PowerShell. EAS remote builds do not receive the local `.env` file. The checked-in `mobile/eas.json` selects the `preview` environment, APK output, disabled demo credentials, and disabled HTTP access:

```powershell
cd mobile
npx eas-cli@latest env:set --name EXPO_PUBLIC_API_URL --value https://ingress-pp2u.onrender.com/api/v1 --environment preview --visibility plaintext
npx eas-cli@latest env:list --environment preview
npx eas-cli@latest build --platform android --profile preview
```

After EAS finishes, download the `.apk` from the build page. With Android Platform Tools installed and USB debugging enabled, use `adb devices` to confirm the phone is connected, then `adb install -r .\Ingress-preview.apk`. Repeat for each test phone. Keep the API URL limited to the cloud; staff pairing supplies the Event Node URL separately. Before a store build, configure the `production` EAS environment separately and use the production profile, which creates an Android App Bundle. See the [physical-device test plan](physical-device-test-plan.md), the [EAS environment variable guide](https://docs.expo.dev/eas/environment-variables/), and the [Android APK build guide](https://docs.expo.dev/build-reference/apk/).

The app's native configuration disables cleartext HTTP by default. Staff devices must connect to an Event Node URL with a certificate they trust.

For a local HTTP development server or Event Node, set `EXPO_PUBLIC_INGRESS_ALLOW_HTTP_NODE=true` before starting Expo or generating the native app configuration, and point `EXPO_PUBLIC_API_URL` at the local server when needed. The mobile node client accepts HTTP only for localhost and private LAN hosts when this flag is enabled. Android's native cleartext allowance applies to the whole development build, so never include the flag in a production build. The checked-in `mobile/.env.example` points to the current Render staging API; it contains no secrets.

## Run the node synchronization scheduler

When `INGRESS_RUNTIME=node` and `INGRESS_CLOUD_SYNC_URL` are set, the application schedules `ingress:sync-admissions` once per minute. On Windows, create a task in **Task Scheduler → Create Task** named `Ingress Laravel Scheduler`:

1. Set the trigger to **At startup**, then repeat the task every **1 minute** with duration **Indefinitely**.
2. Set the action's Program to the full path to `php.exe` (for example, `C:\PHP\php.exe`), arguments to `artisan schedule:run --no-interaction`, and **Start in** to `C:\Ingress\api`.
3. Run it under the dedicated, non-administrator service account described above. Select **Run whether user is logged on or not**.
4. In Settings, set **If the task is already running** to **Do not start a new instance**. Enable task history.

Check the registered task and its last result with:

```powershell
Get-ScheduledTask -TaskName 'Ingress Laravel Scheduler'
Get-ScheduledTaskInfo -TaskName 'Ingress Laravel Scheduler'
```

The task does not make the Event Node depend on public internet. It only retries cloud synchronization when the network is available. Laravel's scheduler lock prevents duplicate sync runs. Microsoft's [Task Scheduler repetition guide](https://learn.microsoft.com/en-us/windows/win32/taskschd/repeating-a-task) describes the repeat trigger behavior.

For Linux Event Nodes, configure the host scheduler to invoke Laravel's scheduler:

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
