<?php

namespace App\Services\Integration\FraSoft;

class FraSoftWebhookHandler
{
    public function __construct(private readonly FraSoftSyncService $sync) {}

    public function handle(array $payload): mixed
    {
        $event = $payload['event'] ?? $payload['type'] ?? 'user.upsert';
        $key = $payload['idempotency_key'] ?? $payload['id'] ?? sha1(json_encode($payload));

        return $this->sync->inbound($event, $payload['data'] ?? $payload, (string) $key);
    }
}
