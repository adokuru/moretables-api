<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /** @use HasFactory<\Database\Factories\RoleFactory> */
    use HasFactory;

    public const Customer = 'customer';

    public const OrganizationOwner = 'organization_owner';

    public const RestaurantManager = 'restaurant_manager';

    public const RestaurantStaff = 'restaurant_staff';

    public const BusinessAdmin = 'business_admin';

    public const DevAdmin = 'dev_admin';

    public const SuperAdmin = 'super_admin';

    protected $fillable = [
        'name',
        'description',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withTimestamps();
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }
}
