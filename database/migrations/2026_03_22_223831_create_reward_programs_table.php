<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('period_type')->default('lifetime');
            $table->unsignedInteger('period_value')->nullable();
            $table->boolean('resets_points')->default(false);
            $table->boolean('tier_locked_until_period_end')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('reward_programs')->insert([
            'name' => 'MoreTables Loyalty Rewards',
            'slug' => 'moretables-lifetime-loyalty',
            'description' => 'Lifetime loyalty program with Bronze, Silver, Gold, and Platinum tiers.',
            'period_type' => 'lifetime',
            'period_value' => null,
            'resets_points' => false,
            'tier_locked_until_period_end' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_programs');
    }
};
