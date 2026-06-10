<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_policies', function (Blueprint $table): void {
            $table->string('booking_details_locale', 10)->default('en')->after('restaurant_id');
            $table->text('custom_dining_policy')->nullable()->after('deposit_required');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_policies', function (Blueprint $table): void {
            $table->dropColumn(['booking_details_locale', 'custom_dining_policy']);
        });
    }
};
