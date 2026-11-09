<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

final class PostgresAdvisoryMigrationLock
{
    public const LOCK_KEY = 0x494E4752455353;

    public function run(Connection $connection, callable $operation, int $timeoutSeconds = 300): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSeconds);
        $locked = false;

        do {
            $result = $connection->selectOne('select pg_try_advisory_lock(?) as locked', [self::LOCK_KEY]);
            if (filter_var($result->locked ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $locked = true;
                break;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            sleep(1);
        } while (true);

        $connection->setReconnector(static function (): never {
            throw new RuntimeException('The database connection was lost while holding the migration lock.');
        });

        try {
            $operation();

            return true;
        } finally {
            if ($locked) {
                $result = $connection->selectOne('select pg_advisory_unlock(?) as unlocked', [self::LOCK_KEY]);
                if (! filter_var($result->unlocked ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    throw new RuntimeException('The database migration lock could not be released.');
                }
            }
        }
    }
}
