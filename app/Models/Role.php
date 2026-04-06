<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const Customer = 'customer';

    public const OrganizationOwner = 'organization_owner';

    public const PrincipalAdmin = 'principal_admin';

    public const Operations = 'operations';

    public const AnalyticsReporting = 'analytics_reporting';

    public const MarketingGrowth = 'marketing_growth';

    public const GuestRelations = 'guest_relations';

    /** @deprecated */
    public const RestaurantManager = 'restaurant_manager';

    /** @deprecated */
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

    /**
     * @return list<string>
     */
    public static function adminRoles(): array
    {
        return [
            self::BusinessAdmin,
            self::DevAdmin,
            self::SuperAdmin,
        ];
    }

    /**
     * @return list<string>
     */
    public static function systemRoles(): array
    {
        return [
            self::Customer,
            self::OrganizationOwner,
            self::PrincipalAdmin,
            self::Operations,
            self::AnalyticsReporting,
            self::MarketingGrowth,
            self::GuestRelations,
            self::RestaurantManager,
            self::RestaurantStaff,
            self::BusinessAdmin,
            self::DevAdmin,
            self::SuperAdmin,
        ];
    }

    /**
     * @return list<string>
     */
    public static function restaurantStaffRoles(): array
    {
        return [
            self::PrincipalAdmin,
            self::Operations,
            self::AnalyticsReporting,
            self::MarketingGrowth,
            self::GuestRelations,
        ];
    }

    /**
     * @return list<string>
     */
    public static function legacyRestaurantStaffRoles(): array
    {
        return [
            self::RestaurantManager,
            self::RestaurantStaff,
        ];
    }

    /**
     * @return list<string>
     */
    public static function allRestaurantStaffRoles(): array
    {
        return [
            ...self::restaurantStaffRoles(),
            ...self::legacyRestaurantStaffRoles(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function staffLoginRoles(): array
    {
        return [
            self::OrganizationOwner,
            ...self::allRestaurantStaffRoles(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function restaurantAccessRoles(): array
    {
        return [
            ...self::staffLoginRoles(),
            ...self::adminRoles(),
        ];
    }
}
