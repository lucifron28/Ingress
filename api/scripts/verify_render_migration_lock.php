<?php

use App\Support\PostgresAdvisoryMigrationLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.connections.render_migration_lock_first' => config('database.connections.pgsql'),
    'database.connections.render_migration_lock_second' => config('database.connections.pgsql'),
]);

if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "PostgreSQL connection is required.\n");
    exit(1);
}

$first = DB::connection('render_migration_lock_first');
$second = DB::connection('render_migration_lock_second');
$lockKey = PostgresAdvisoryMigrationLock::LOCK_KEY;
$first->selectOne('select pg_advisory_lock(?)', [$lockKey]);

try {
    $operationRan = false;
    $acquired = (new PostgresAdvisoryMigrationLock)->run($second, static function () use (&$operationRan): void {
        $operationRan = true;
    }, 1);

    if ($acquired || $operationRan) {
        fwrite(STDERR, "A second startup acquired the migration lock while it was held.\n");
        exit(1);
    }
} finally {
    $first->selectOne('select pg_advisory_unlock(?)', [$lockKey]);
}

$operationRan = false;
$acquired = (new PostgresAdvisoryMigrationLock)->run($second, static function () use (&$operationRan): void {
    $operationRan = true;
}, 5);

if (! $acquired || ! $operationRan) {
    fwrite(STDERR, "The migration lock was not released for the next startup.\n");
    exit(1);
}

fwrite(STDOUT, "PostgreSQL advisory migration lock contention passed.\n");
