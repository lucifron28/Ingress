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

## Recorded runs

These runs used Windows 11, PHP 8.4.24, Laravel 13.34.0, PostgreSQL 18.4, and separate local cloud and node databases. Each run imported 1,000 registered guests, then used four scanner processes and four gates.

Two runs on 2026-11-05 completed all nine assertions:

| Scan time | Throughput | p50 | p95 | Maximum | Failed-sync attempt | Restart replay and remaining sync |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 7.22 s | 138.6/s | 27.47 ms | 35.66 ms | 127.12 ms | 1.58 s | 9.89 s |
| 8.13 s | 123.1/s | 32.75 ms | 39.20 ms | 98.52 ms | 1.68 s | 11.36 s |

Each gate recorded 250 admissions. The harness committed the first 100-admission batch on the cloud, then dropped the response. A fresh process retried the same batch ID and package hash. In both runs, the cloud and node recorded 1,000 admissions, the cloud recorded ten batches, and the node had no pending batch.

An earlier run on the same reported versions completed scans in 3.35 seconds (298.3/s), with 12.70 ms p50, 15.23 ms p95, and 72.96 ms maximum latency. Its recovery completed in 4.28 seconds. The two newer runs were slower; the harness has not isolated why. Treat the earlier throughput as a single-run result, not a capacity guarantee.

The automated load and recovery check runs in the PostgreSQL CI job and from `scripts/test-all.ps1`.

## Limits

This run measures the Laravel admission and reconciliation paths against PostgreSQL with four PHP worker processes. Its HTTP client fake commits through the real cloud receive action, then simulates a lost connection or response. It does not measure venue Wi-Fi coverage, physical device cameras, scanner UI timing, or performance beyond the configured workload. The project owner will perform device and venue verification after implementation.
