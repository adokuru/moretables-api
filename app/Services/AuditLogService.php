<?php

namespace App\Services;

use App\AuditLogActorType;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        ?User $actor = null,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Restaurant $restaurant = null,
        ?Organization $organization = null,
        ?string $description = null,
    ): AuditLog {
        if ($auditable instanceof Restaurant) {
            $restaurant ??= $auditable;
            $organization ??= $auditable->organization;
        }

        if ($auditable instanceof Organization) {
            $organization ??= $auditable;
        }

        return AuditLog::query()->create([
            'actor_user_id' => $actor?->id,
            'actor_type' => ($actor ? AuditLogActorType::User : AuditLogActorType::System)->value,
            'organization_id' => $organization?->id,
            'restaurant_id' => $restaurant?->id,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'action' => $action,
            'description' => $description,
            'ip_address' => request()?->ip(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
