<?php

namespace App\Services\Integration\FraSoft;

use RuntimeException;

class SyncConflictResolver
{
    public function unknown(string $eventType, array $payload): void
    {
        throw new RuntimeException('رویداد ناشناخته FraSoft: '.$eventType);
    }

    public function preferInbound(array $local, array $remote): array
    {
        return $remote + $local;
    }
}
