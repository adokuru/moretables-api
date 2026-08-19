<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing_plans', function (Blueprint $table): void {
            $table->unsignedInteger('max_restaurants')->nullable()->after('interval');
        });

        foreach (config('billing.plans') as $plan) {
            if (! array_key_exists('max_restaurants', $plan)) {
                continue;
            }

            DB::table('billing_plans')
                ->where('slug', $plan['slug'])
                ->update(['max_restaurants' => $plan['max_restaurants']]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_plans', function (Blueprint $table): void {
            $table->dropColumn('max_restaurants');
        });
    }
};
