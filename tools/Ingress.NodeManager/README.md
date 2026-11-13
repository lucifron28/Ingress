# Ingress Node Manager

Ingress Node Manager is a Windows desktop control panel for the existing Ingress Event Node. It reads Node, PostgreSQL, WSL2, Docker, network, firewall, admission, and synchronization status from the existing Network Manager. It does not replace Laravel or change the Node database.

## Requirements

- Windows 11.
- The WSL2 distribution that owns the Event Node's Docker Compose project.
- Docker Desktop with WSL integration enabled.
- Docker Compose 2.24.4 or newer for the LAN port override.
- PowerShell 7. Windows PowerShell is used only as a fallback.
- The existing Node Compose containers must still exist so the app can identify the correct project from Docker labels. The Node may be stopped.

The application does not install or manage Docker, WSL2, PostgreSQL, Python, Node.js, or Android apps. It stores no Node credentials and writes no settings to the Windows profile.

## Build a Windows x64 package

Open PowerShell in the repository root and run:

    .\tools\Ingress.NodeManager\publish.ps1

The self-contained executable and its required scripts are written to:

    tools\Ingress.NodeManager\artifacts\win-x64\

Copy the entire win-x64 folder to the Windows machine that runs the Event Node, then double-click Ingress.NodeManager.exe. Create a shortcut to that executable if you want it on the Desktop. Keep the scripts folder beside the executable.

## Use the app

1. Connect the laptop and scanner to the same private Wi-Fi or hotspot, then leave Docker Desktop and the Event Node's WSL distribution running.
2. Open `Ingress.NodeManager.exe`. The Dashboard lists current private IPv4 addresses and refreshes status at launch and every minute.
3. Click **Start Server**. If multiple adapters are active, choose the one used by the scanner. Review the preview and confirm; if the scoped firewall rule is missing, use **Configure Windows Firewall** and accept the Windows prompt.
4. Use **Open Operator Console** to sign in from the laptop. The console stays on `127.0.0.1`; pair the scanner using the current private HTTP address or one-use pairing QR.
5. Use **Network** to preview a hotspot or address change. The Node Manager checks admission, database, migrations, firewall scope, and operator-port isolation before restarting only the Node web container.
6. Use **Safe Stop** only after admission closes and synchronization is safe. It stops only `ingress-node`, leaving PostgreSQL, the scheduler, and database volume in place.
7. Copy **Safe Diagnostics** when reporting a problem. The report omits credentials and pairing data.

Do not use Docker Compose down, remove, or volume commands for routine operation. The PowerShell Network Manager remains available as a fallback in the repository's scripts directory.

## Status limits

Unknown checks remain Unknown and block disruptive actions. The scheduler container can be checked, but the current Node does not expose a scheduler heartbeat. Windows cannot prove that a phone reaches the laptop over the active hotspot or that camera pairing and ticket scans work. Scanner readiness remains unverified until each physical phone passes the test.

The LAN demo uses plain HTTP, so only run it on an isolated network controlled by the operator. The Node Manager scopes its firewall rule to Docker, the selected adapter, local private address and port, and that adapter's subnet. The operator listener stays on loopback. See the concise [LAN course demo guide](../../docs/lan-course-demo.md) for building one APK and pairing after changing hotspots.

If a Node-only start or recreation fails after a network profile update, the manager atomically restores the previous `INGRESS_NODE_BIND_ADDRESS` and `APP_URL`. It does not issue a second restart automatically. Refresh status and use the safety-gated controls to recover the Node.

## Update safely

Build a fresh package from the branch or release you intend to use. Close the manager, replace the previous application folder with the complete new win-x64 folder, then launch it again. The package has no local database or configuration to migrate. Leave WSL distributions, Docker containers, and database volumes in place.

## Tests

Run the .NET tests with:

    dotnet test tools\Ingress.NodeManager\Ingress.NodeManager.slnx

The PowerShell regression suite is scripts\tests\IngressNetworkManager.Tests.ps1. It includes an isolated Docker/Compose fixture and never uses the live Node project for that fixture.
