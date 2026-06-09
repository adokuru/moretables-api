<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_point_transactions', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('metadata');
            $table->unsignedInteger('points_remaining')->nullable()->after('expires_at');
            $table->unsignedBigInteger('credit_value')->nullable()->after('points_remaining');
            $table->string('credit_currency', 3)->nullable()->after('credit_value');
        });
    }

    public function down(): void
    {
        Schema::table('reward_point_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'expires_at',
                'points_remaining',
                'credit_value',
                'credit_currency',
            ]);
        });
    }
};
