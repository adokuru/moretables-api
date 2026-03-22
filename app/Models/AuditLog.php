<?php

namespace App\Models;

use App\AuditLogActorType;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    protected $fillable = [
        'actor_user_id',
        'actor_type',
        'organization_id',
        'restaurant_id',
        'auditable_type',
        'auditable_id',
        'action',
        'description',
        'ip_address',
        'old_values',
        'new_values',
    ];

    protected function casts(): array
    {
        return [
            'actor_type' => AuditLogActorType::class,
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
