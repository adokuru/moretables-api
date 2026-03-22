<?php

namespace App\Models;

use App\RoleScopeType;
use Database\Factories\UserRoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRole extends Model
{
    /** @use HasFactory<UserRoleFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role_id',
        'scope_type',
        'organization_id',
        'restaurant_id',
        'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => RoleScopeType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
