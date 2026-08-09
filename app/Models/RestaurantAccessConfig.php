<?php

namespace App\Models;

use Database\Factories\RestaurantAccessConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantAccessConfig extends Model
{
    /** @use HasFactory<RestaurantAccessConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'name',
        'slug',
        'description',
        'permissions',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * The default access configs seeded for every new restaurant.
     *
     * @return list<array{name: string, slug: string, description: string, permissions: list<string>}>
     */
    public static function defaults(): array
    {
        return [
            [
                'name' => 'Principal Admin',
                'slug' => 'principal_admin',
                'description' => 'All reservation management, Floor plan configuration, User management, Restaurant Settings, Integrations, Marketing tools, Billing and subscription, Reporting & exports, Guest CRM',
                // "Everything" — every permission that exists, per explicit product
                // decision (Principal Admin should visibly be all-access, not just
                // mostly-access via the restaurants.manage fallback). Keep this in
                // sync with Permission::restaurantAccessConfigPermissions() (the 12
                // checkboxes) + staff.manage/reservations.view/reservations.manage/
                // waitlist.manage (the 3 baseline dashboard permissions) if either
                // list ever grows.
                'permissions' => [
                    'restaurants.view',
                    'restaurants.manage',
                    'reservations.view',
                    'reservations.manage',
                    'waitlist.manage',
                    'tables.manage',
                    'staff.manage',
                    'audit_logs.view',
                    'billing.manage',
                    'integrations.manage',
                    'communications.manage',
                    'marketing.manage',
                    'messaging.manage',
                    'policies.manage',
                    'reporting.export',
                ],
            ],
            [
                'name' => 'Operations',
                'slug' => 'operations',
                'description' => 'Manage reservations, Manage waitlist, Seat guests / check-in, Edit floor plan & tables, Table status management, Basic restaurant settings, Shift-level overrides, Manage staff',
                'permissions' => [
                    'restaurants.view',
                    'reservations.view',
                    'reservations.manage',
                    'waitlist.manage',
                    'tables.manage',
                    'staff.manage',
                ],
            ],
            [
                'name' => 'Analytics & Reporting',
                'slug' => 'analytics_reporting',
                'description' => 'View dashboards, View reservation analytics, View performance metrics, Export reports, View customer trends',
                'permissions' => [
                    'restaurants.view',
                    'reservations.view',
                    'audit_logs.view',
                ],
            ],
            [
                'name' => 'Marketing & Growth',
                'slug' => 'marketing_growth',
                'description' => 'Edit restaurant profile, Manage promotions, Manage offers, Manage reviews',
                'permissions' => [
                    'restaurants.view',
                    'restaurants.manage',
                ],
            ],
            [
                'name' => 'Guest Relations',
                'slug' => 'guest_relations',
                'description' => 'View guest profiles, Edit guest notes/tags, Manage guest database, View visit history, Manage guest preferences, Export guest lists, Email marketing',
                'permissions' => [
                    'restaurants.view',
                    'reservations.view',
                ],
            ],
        ];
    }

    /**
     * Always return an array even when the DB column is null.
     *
     * @return list<string>
     */
    public function getPermissionsAttribute(mixed $value): array
    {
        if (is_null($value)) {
            return [];
        }

        return is_array($value) ? $value : (json_decode($value, true) ?? []);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'access_config_id');
    }

    public function hasPermission(string $permissionName): bool
    {
        return in_array($permissionName, $this->permissions ?? [], true);
    }
}
