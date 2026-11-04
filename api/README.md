# Ingress backend

This Laravel application serves the cloud API, organizer console, and Event Node API. Cloud and Event Node deployments use separate PostgreSQL databases. The Event Node is the admission authority during an event and serves staff scanners over the venue LAN.

For local development, copy `.env.example` to `.env`, configure PostgreSQL, then run:

```bash
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=0.0.0.0 --port=8000
```

The example environment enables demo accounts for local use. Production deployments must set `APP_ENV=production`, `APP_DEBUG=false`, and `INGRESS_DEMO_MODE=false`, use HTTPS, and provision separate databases and application keys for cloud and each Event Node. Follow the [deployment guide](../docs/deployment.md) before exposing a runtime.

Run the backend feature suite with `php artisan test`. Run the PostgreSQL concurrency checks separately with `php scripts/verify_pgsql_concurrency.php` after configuring the test database. The root [`README.md`](../README.md) documents the mobile app and local workflows. See the [API reference](../docs/api.md) and [current architecture](../docs/second-half-architecture.md) for routes, data transfer, authority rules, and operating limits.
