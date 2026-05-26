<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_programs', function (Blueprint $table): void {
            $table->json('redemption_tiers')->nullable()->after('is_active');
        });

        // Seed default redemption tiers on any existing active program.
        DB::table('reward_programs')
            ->where('is_active', true)
            ->update([
                'redemption_tiers' => json_encode([
                    ['points' => 1000, 'credit_value' => 3000, 'credit_currency' => 'NGN'],
                    ['points' => 2500, 'credit_value' => 5000, 'credit_currency' => 'NGN'],
                    ['points' => 5000, 'credit_value' => 10000, 'credit_currency' => 'NGN'],
                    ['points' => 10000, 'credit_value' => 30000, 'credit_currency' => 'NGN'],
                ]),
            ]);
    }

    public function down(): void
    {
        Schema::table('reward_programs', function (Blueprint $table): void {
            $table->dropColumn('redemption_tiers');
        });
    }
};
