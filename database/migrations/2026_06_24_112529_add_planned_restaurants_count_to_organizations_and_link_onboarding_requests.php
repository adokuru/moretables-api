<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedInteger('planned_restaurants_count')->nullable()->after('status');
        });

        Schema::table('onboarding_requests', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });

        DB::table('onboarding_requests')
            ->where('location_count', '11-20')
            ->update(['location_count' => '11-25']);

        DB::table('onboarding_requests')
            ->where('location_count', '20+')
            ->update(['location_count' => '25+']);
    }

    public function down(): void
    {
        DB::table('onboarding_requests')
            ->where('location_count', '11-25')
            ->update(['location_count' => '11-20']);

        DB::table('onboarding_requests')
            ->where('location_count', '25+')
            ->update(['location_count' => '20+']);

        Schema::table('onboarding_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('planned_restaurants_count');
        });
    }
};
