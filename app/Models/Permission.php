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
     * Permissions that unlock the merchant /admin back-office section — every
     * permission that gates at least one /admin page, as opposed to the purely
     * day-to-day /dashboard front-of-house permissions (reservations.manage,
     * reservations.view, waitlist.manage — none of those alone grant /admin,
     * since nothing in /admin is reservation/waitlist-scoped).
     *
     * Each entry here corresponds to a real /admin page per
     * docs/PERMISSION_MATRIX.md: restaurants.view -> Restaurant Profile (view),
     * restaurants.manage -> Restaurant Settings, tables.manage -> Availability
     * Planning, staff.manage -> Accounts, audit_logs.view -> Reporting (view),
     * reporting.export -> Reporting (export), billing.manage -> Billing,
     * integrations.manage -> Integrations, marketing.manage -> Marketing,
     * communications.manage / messaging.manage -> Guest Communication,
     * policies.manage -> Restaurant Settings' Policies tab.
     *
     * staff.manage now included deliberately (previously excluded on purpose,
     * since it only unlocked the dashboard's own User Management "Add"/"Edit").
     * Per explicit product decision, it now also unlocks /admin so a
     * staff.manage-only user can reach /admin/accounts directly instead of
     * being redirected to /dashboard first.
     *
     * @return list<string>
     */
    public static function adminSectionPermissions(): array
    {
        return [
            'restaurants.view',
            'restaurants.manage',
            'tables.manage',
            'staff.manage',
            'audit_logs.view',
            'reporting.export',
            'billing.manage',
            'integrations.manage',
            'marketing.manage',
            'communications.manage',
            'messaging.manage',
            'policies.manage',
        ];
    }
}
