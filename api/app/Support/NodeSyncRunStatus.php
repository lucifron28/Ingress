<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

class NodeSyncRunStatus
{
    private const CACHE_PREFIX = 'ingress:node-sync:last-result:';

    public function record(int $localEventId, string $result): void
    {
        if ($localEventId < 1 || ! in_array($result, ['succeeded', 'failed', 'busy'], true)) {
            throw new LogicException('Unsupported node synchronization result.');
        }

        Cache::store('database')->forever(self::CACHE_PREFIX.$localEventId, [
            'result' => $result,
            'recorded_at' => now()->utc()->toIso8601String(),
        ]);
    }

    /** @return array{result: string, recordedAt: CarbonImmutable}|null */
    public function forEvent(int $localEventId): ?array
    {
        if ($localEventId < 1) {
            return null;
        }

        $value = Cache::store('database')->get(self::CACHE_PREFIX.$localEventId);
        if (! is_array($value) || ! in_array($value['result'] ?? null, ['succeeded', 'failed', 'busy'], true)
            || ! is_string($value['recorded_at'] ?? null)) {
            return null;
        }

        try {
            $recordedAt = CarbonImmutable::parse($value['recorded_at'])->utc();
        } catch (Throwable) {
            return null;
        }

        return ['result' => $value['result'], 'recordedAt' => $recordedAt];
    }
}
