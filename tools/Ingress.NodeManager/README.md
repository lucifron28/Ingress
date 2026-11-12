# Ingress Node Manager

Ingress Node Manager is a Windows desktop control panel for the existing Ingress Event Node. It reads Node, PostgreSQL, WSL2, Docker, TLS, firewall, admission, and synchronization status from the existing Network Manager. It does not replace Laravel or change the Node database.

## Requirements

- Windows 11.
- The WSL2 distribution that owns the Event Node's Docker Compose project.
- Docker Desktop with WSL integration enabled.
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

1. Leave Docker Desktop and the Event Node's WSL distribution running.
2. Open Ingress.NodeManager.exe. The Dashboard refreshes status at launch and every minute.
3. Choose Network Manager to inspect the detected adapters and saved profiles.
4. Select Phone Hotspot, Laptop Hotspot, or Auto Detect, then choose Preview Changes.
5. If every safety check passes, choose Apply Changes and confirm the Node bind and HTTPS URL change. The manager rechecks the saved profile, admission, database, migration, TLS, firewall, and operator-port gates before it updates the Node.
6. Use Start / Resume Node only when the manager enables it. Use Safe Stop after admission closes and synchronization reaches zero. Safe Stop stops only ingress-node. It leaves PostgreSQL, the scheduler, and the database volume in place.
7. Open Staff & Pairing to start the existing authenticated Laravel operator console in the browser. Event assignment, gate access, credential provisioning, pairing QR expiry, and cancellation remain in that console.
8. Copy Safe Diagnostics when reporting a problem. The report omits credentials and pairing data.

Do not use Docker Compose down, remove, or volume commands for routine operation. The PowerShell Network Manager remains available as a fallback in the repository's scripts directory.

## Status limits

Unknown checks remain Unknown and block disruptive actions. The scheduler container can be checked, but the current Node does not expose a scheduler heartbeat. Windows cannot prove that an Android APK trusts the installed certificate or reaches the laptop over the active hotspot. Scanner readiness remains unverified until each physical phone passes the test.

The manager reports the saved phone and laptop profile addresses. It does not change EAS build variables, certificates, Android trust anchors, firewall rules, or network profiles. Build or select the APK for the same address shown by the chosen profile before scanning.

If a Node-only start or recreation fails after a network profile update, the manager atomically restores the previous `INGRESS_NODE_BIND_ADDRESS` and `APP_URL`. It does not issue a second restart automatically. Refresh status and use the safety-gated controls to recover the Node.

## Update safely

Build a fresh package from the branch or release you intend to use. Close the manager, replace the previous application folder with the complete new win-x64 folder, then launch it again. The package has no local database or configuration to migrate. Leave WSL profiles, certificates, Docker containers, and database volumes in place.

## Tests

Run the .NET tests with:

    dotnet test tools\Ingress.NodeManager\Ingress.NodeManager.slnx

The PowerShell regression suite is scripts\tests\IngressNetworkManager.Tests.ps1. It includes an isolated Docker/Compose fixture and never uses the live Node project for that fixture.
