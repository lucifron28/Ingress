# Load and synchronization recovery

## Automated run

Run from `api/` with a PostgreSQL account that can create and drop databases:

```powershell
$env:INGRESS_LOAD_DB = "ingress_load_recovery"
$env:INGRESS_LOAD_ATTENDEES = "1000"
$env:INGRESS_LOAD_WORKERS = "4"
php scripts/verify_load_recovery.php
```

The harness creates separate cloud and node databases, migrates both, builds an event with the requested attendee count, and imports the event roster to the node. It refuses to reuse either database name. A successful run drops both databases; set `INGRESS_LOAD_KEEP_DATABASES=1` to retain them for investigation. If a run exits before cleanup, remove only the two names printed at startup after confirming they are the harness databases.

The attendee count can be set from 500 through 5,000. The worker count can be set from 2 through 16; each worker uses its own gate and staff account. The CI job runs 1,000 attendees across four gates.

## Recorded result

Run on Windows 11, PHP 8.4.24, Laravel 13.34.0, and PostgreSQL 18.4 using separate local cloud and node databases:

- 1,000 registered guests were imported to the node.
- Four scanner processes admitted 1,000 unique tickets in 3.35 seconds, or 298.3 admissions per second.
- Each gate recorded 250 admissions. Per-scan database-action latency was 12.70 ms at p50, 15.23 ms at p95, and 72.96 ms maximum.
- The first sync process delivered a 100-admission batch to the cloud. The cloud committed it, then the harness dropped the response to simulate a LAN interruption.
- The node kept the unacknowledged batch. A fresh PHP process retried the same batch ID and package hash, received the cloud's existing receipt, and sent the remaining nine batches.
- Recovery finished in 4.28 seconds. The cloud and node each recorded 1,000 admissions, the cloud recorded ten batches, and the node had no pending batch.
- All nine harness assertions passed.

The automated load and recovery check runs in the PostgreSQL CI job and from `scripts/test-all.ps1`.

## Limits

This run measures the Laravel admission and reconciliation paths against PostgreSQL with four PHP worker processes. Its HTTP client fake commits through the real cloud receive action, then simulates a lost connection or response. It does not measure venue Wi-Fi coverage, physical device cameras, scanner UI timing, or performance beyond the configured workload. The project owner will perform device and venue verification after implementation.
