# LAN course demo

This workflow runs the existing Laravel Event Node and PostgreSQL database on a private Wi-Fi or hotspot network. It supports the Ingress course final project demonstration; it is not a production or campus-wide service.

The LAN demo uses plain HTTP. Other devices on that network can observe staff passwords, session tokens, pairing codes, ticket credentials, and attendee data. Use an isolated network controlled by the demo operator, keep the operator console on the laptop, and never use this mode on public, guest, or shared Wi-Fi. Cloud and standard Event Node deployments continue to use HTTPS.

## Prepare the laptop and scanner

1. Prepare the existing Event Node with its event snapshot, activated authority grant, assigned staff, and local staff credentials. Do not create a second Node or database.
2. Ensure Docker Desktop's WSL2 backend is running and Docker Compose is version 2.24.4 or newer. The Node Manager reports this requirement if the installed version is too old.
3. Build the Windows manager from the repository root:

   ```powershell
   .\tools\Ingress.NodeManager\publish.ps1
   ```

   Copy the complete `tools\Ingress.NodeManager\artifacts\win-x64` folder to the laptop that runs Docker Desktop and WSL2. Keep its `scripts` folder beside `Ingress.NodeManager.exe`.
4. Build the scanner once from `mobile`:

   ```powershell
   npx eas-cli@latest build --platform android --profile lanDemo
   ```

   The profile needs no fixed IP, CA certificate, or EAS environment variables. Install that APK on the demo phone. Do not use the `preview` or `production` profile for this HTTP workflow.

## Start the Event Node

1. Connect the laptop and phone to the same private Wi-Fi or hotspot. Make sure that network permits devices to communicate with each other.
2. Open `Ingress.NodeManager.exe`. It lists current private IPv4 addresses and network adapters. If more than one is active, select the adapter the phone uses.
3. Click **Start Server** and review the address and safety checks. If the existing Node is running in HTTPS mode, the manager switches only its web container to the LAN demo listener after the same checks pass. On first use, select **Configure Windows Firewall** if asked. Windows may show an administrator prompt. The rule is limited to Docker Desktop, the selected adapter and local address, the demo port, and that adapter's private subnet. Canceling leaves the server stopped.
4. The manager reuses the existing PostgreSQL container and data volume. It starts or recreates only the Node web container; the scheduler and database are not removed. The operator console remains published on laptop loopback at `http://127.0.0.1:8444/node/operator`.
5. Use **Open Operator Console** to sign in and manage staff pairing. The scanner API address is shown on the dashboard and is also encoded in the one-use pairing QR. Show each QR only to its intended staff member.

The scanner port defaults to `8000`. To choose another available port, set `INGRESS_NODE_HTTP_PORT` in `api/node-compose.env` before starting the server; the manager uses it for the LAN listener and pairing URL.

## Connect and scan

In the scanner, choose **Scan pairing code** and confirm the displayed Node identity and private IP with the operator. For manual sign-in, enter the current IPv4 address (for example `192.168.1.25`) or `http://192.168.1.25:8000/api/v1`. The app remembers the selected endpoint on that phone.

Scan a ticket, review the admission preview, then explicitly confirm the movement. The Event Node and its local PostgreSQL database handle admission while the internet is unavailable, as long as the laptop and phone remain connected to the same working LAN.

## Change Wi-Fi or hotspot

Connect both devices to the new network, refresh the manager, and select the adapter and current private IP. Preview and apply the change from **Network**. The manager updates the Node URL and host binding, then restarts only the web container. It blocks the restart if admission is active or a safety check is unknown. After the new address appears, sign out and pair or sign in again on the scanner; the installed APK does not need to change.

## Limits and device checks

This setup does not configure the Wi-Fi access point or disable client isolation. Keep the operator console on the laptop. Stop using the demo if an untrusted device joins the network. A successful Windows build and automated tests do not prove phone reachability, camera permission, QR pairing, ticket scans, duplicate-scan handling, or operation during a real WAN outage. Complete the LAN-demo items in the [physical-device test plan](physical-device-test-plan.md) on the actual laptop, phone, and venue network before presenting it.
