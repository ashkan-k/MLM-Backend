<?php

namespace App\Services\Integration\FraSoft;

use App\Models\FraSoftMapping;
use App\Models\FraSoftSyncLog;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Gateway\GatewaySaleService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FraSoftSyncService
{
    public function __construct(
        private readonly FraSoftClient $client,
        private readonly FraSoftMapper $mapper,
        private readonly SyncConflictResolver $conflicts,
        private readonly GatewaySaleService $sales,
    ) {}

    public function inbound(string $eventType, array $payload, string $idempotencyKey): FraSoftSyncLog
    {
        $existing = FraSoftSyncLog::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing && $existing->status === 'processed') {
            return $existing;
        }

        $log = $existing ?: FraSoftSyncLog::query()->create([
            'direction' => 'inbound',
            'event_type' => $eventType,
            'idempotency_key' => $idempotencyKey,
            'status' => 'processing',
            'attempts' => 0,
            'payload' => $payload,
        ]);
        $log->attempts++;

        try {
            match ($eventType) {
                'user.upsert', 'representative.upsert' => $this->upsertUser($payload),
                'gateway.sold' => $this->upsertSale($payload),
                default => $this->conflicts->unknown($eventType, $payload),
            };
            $log->status = 'processed';
            $log->processed_at = now();
            $log->error = null;
        } catch (\Throwable $e) {
            $log->status = 'failed';
            $log->error = $e->getMessage();
        }

        $log->save();

        return $log;
    }

    public function outboundPull(string $resource = 'organization'): array
    {
        $payload = $this->client->pull($resource);
        $key = 'outbound-'.$resource.'-'.Str::uuid();
        $this->inbound($resource === 'organization' ? 'user.upsert' : $resource, $payload, $key);

        return $payload;
    }

    private function upsertUser(array $payload): User
    {
        $mapped = $this->mapper->user($payload);
        $user = null;
        $mapping = FraSoftMapping::query()->where('entity_type', 'user')->where('external_id', $mapped['external_id'])->first();
        if ($mapping) {
            $user = User::query()->find($mapping->internal_id);
        }
        if (! $user && $mapped['mobile']) {
            $user = User::query()->where('mobile', $mapped['mobile'])->first();
        }
        if (! $user) {
            $user = User::query()->create([
                'name' => $mapped['name'],
                'mobile' => $mapped['mobile'] ?: '09'.str_pad((string) random_int(100000000, 999999999), 9, '0'),
                'email' => $mapped['email'],
                'password' => Hash::make(Str::random(16)),
                'is_active' => true,
            ]);
        } else {
            $user->fill(['name' => $mapped['name'], 'email' => $mapped['email'] ?? $user->email])->save();
        }

        FraSoftMapping::query()->updateOrCreate(
            ['entity_type' => 'user', 'external_id' => $mapped['external_id']],
            ['internal_id' => $user->id, 'payload' => $payload]
        );

        foreach ($mapped['roles'] as $slug) {
            $role = Role::query()->where('slug', $slug)->first();
            if ($role) {
                UserRole::query()->firstOrCreate(
                    ['user_id' => $user->id, 'role_id' => $role->id],
                    ['effective_from' => now()->toDateString(), 'is_active' => true]
                );
            }
        }

        return $user;
    }

    private function upsertSale(array $payload): void
    {
        $reps = [];
        foreach ($this->mapper->shares($payload) as $share) {
            $map = FraSoftMapping::query()->where('entity_type', 'user')->where('external_id', $share['external_user_id'])->first();
            if ($map) {
                $reps[] = ['user_id' => $map->internal_id, 'share_percent' => $share['share_percent']];
            }
        }

        $this->sales->record([
            'external_id' => (string) ($payload['gateway_id'] ?? $payload['external_id']),
            'name' => $payload['name'] ?? 'FraSoft Gateway',
            'source' => 'frasoft',
            'status' => 'successful',
            'amount' => $payload['amount'] ?? 0,
            'representatives' => $reps ?: [['user_id' => $this->upsertUser($payload['representative'] ?? $payload)->id, 'share_percent' => '100.000']],
            'idempotency_key' => 'frasoft-sale-'.($payload['id'] ?? Str::uuid()),
            'sold_at' => $payload['sold_at'] ?? now(),
        ]);
    }
}
