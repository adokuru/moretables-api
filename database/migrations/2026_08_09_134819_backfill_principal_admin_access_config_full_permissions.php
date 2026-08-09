<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FULL_PERMISSIONS = [
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
    ];

    /**
     * Principal Admin should mean "everything" — RestaurantAccessConfig::defaults()
     * was updated to seed new restaurants with the full permission set, but
     * already-existing restaurants' Principal Admin configs were seeded before
     * that change and need the same backfill. Unions rather than overwrites, so
     * any restaurant that had already customized their Principal Admin config
     * beyond the seeded defaults keeps whatever extra it had — this only adds
     * the permissions it was missing, never removes anything.
     */
    public function up(): void
    {
        DB::table('restaurant_access_configs')
            ->where('slug', 'principal_admin')
            ->where('is_default', true)
            ->orderBy('id')
            ->chunkById(100, function ($configs): void {
                foreach ($configs as $config) {
                    $current = json_decode($config->permissions ?? '[]', true) ?: [];
                    $merged = array_values(array_unique([...$current, ...self::FULL_PERMISSIONS]));

                    if ($merged === $current) {
                        continue;
                    }

                    DB::table('restaurant_access_configs')
                        ->where('id', $config->id)
                        ->update(['permissions' => json_encode($merged)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Deliberately not reversible — we don't know which of the backfilled
        // permissions (if any) a restaurant owner has since intentionally
        // unchecked via the Access Config editor, so rolling back to a smaller
        // set here would risk silently re-granting something that was
        // deliberately revoked after this migration ran.
    }
};
