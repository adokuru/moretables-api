<?php

namespace App\Models;

use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')->withTimestamps();
    }

    /**
     * Every permission name assignable to a restaurant access config — stored as
     * free-form JSON strings on RestaurantAccessConfig, not FK-constrained to this
     * model's own rows (only the classic Role::permissions() pivot needs a real row).
     * Mirrors the frontend's PERMISSION_LABELS
     * (moretable-web-app/src/lib/api/accounts/accounts.types.ts) — keep both in sync.
     *
     * @return list<string>
     */
    public static function restaurantAccessConfigPermissions(): array
    {
        return [
            'reservations.manage',
            'tables.manage',
            'audit_logs.view',
            'reporting.export',
            'restaurants.manage',
            'integrations.manage',
            'marketing.manage',
            'restaurants.view',
            'billing.manage',
            'communications.manage',
            'messaging.manage',
            'policies.manage',
        ];
    }

    /**
     * Permissions that unlock the merchant /admin back-office section (Restaurant
     * Settings, Billing, Integrations, Marketing, Guest Communication, reporting
     * exports, ...) as opposed to pure day-to-day /dashboard front-of-house use
     * (reservations.manage, tables.manage, waitlist.manage, audit_logs.view —
     * none of those alone grant /admin).
     *
     * staff.manage is deliberately excluded — it only unlocks the dashboard's own
     * User Management "Add"/"Edit" actions (see MerchantRestaurantStaffController),
     * a dashboard-tier feature by explicit design, not a signal that its holder
     * should see the /admin section itself. The default "Operations" access config
     * (RestaurantAccessConfig::defaults()) carries staff.manage precisely so it can
     * grant that one dashboard feature without also opening up /admin.
     *
     * @return list<string>
     */
    public static function adminSectionPermissions(): array
    {
        return [
            'restaurants.manage',
            'billing.manage',
            'integrations.manage',
            'marketing.manage',
            'communications.manage',
            'messaging.manage',
            'policies.manage',
            'reporting.export',
        ];
    }
}
