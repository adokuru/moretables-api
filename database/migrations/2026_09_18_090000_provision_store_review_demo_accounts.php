<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Provisions the App Store / Play Store review demo accounts on deploy, since
 * seeders aren't run on production.
 *
 * The command is idempotent and no-ops when the demo env vars are unset, so
 * this migration is safe on every environment. If the env vars are set *after*
 * this has already run, the migration won't fire again — run the command by
 * hand instead: php artisan app:provision-demo
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tests migrate a fresh in-memory database on every case; they provision
        // demo accounts explicitly when they need them.
        if (app()->environment('testing')) {
            return;
        }

        Artisan::call('app:provision-demo');
    }

    public function down(): void
    {
        // Demo accounts own guest/reservation rows once a reviewer touches the
        // app; deleting them on rollback would cascade further than a rollback
        // should. Remove by hand if they ever need to go.
    }
};
