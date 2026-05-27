<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('onboarding_requests', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('job_title')->nullable()->after('phone');
            $table->string('location_count')->nullable()->after('job_title');
            $table->string('contact_reason')->nullable()->after('location_count');
            $table->string('owner_name')->nullable()->change();
            $table->string('address')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('onboarding_requests', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'job_title', 'location_count', 'contact_reason']);
            $table->string('owner_name')->nullable(false)->change();
            $table->string('address')->nullable(false)->change();
        });
    }
};
