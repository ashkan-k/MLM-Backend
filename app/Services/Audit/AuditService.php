<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public function record(?User $actor, string $action, Model|string $auditable, ?array $old = null, ?array $new = null): AuditLog
    {
        $type = $auditable instanceof Model ? $auditable::class : $auditable;
        $id = $auditable instanceof Model ? $auditable->getKey() : null;

        return AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }
}
