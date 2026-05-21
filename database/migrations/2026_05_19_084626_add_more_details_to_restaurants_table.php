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
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->string('executive_chef')->nullable()->after('menu_link');
            $table->json('dietary_options')->nullable()->after('executive_chef');
            $table->json('beverage_options')->nullable()->after('dietary_options');
            $table->json('amenities')->nullable()->after('beverage_options');
            $table->json('smoking_policy')->nullable()->after('amenities');
            $table->string('delivery_options')->nullable()->after('smoking_policy');
            $table->json('safety_policies')->nullable()->after('delivery_options');
            $table->string('parking_info')->nullable()->after('safety_policies');
            $table->string('closest_landmark')->nullable()->after('parking_info');
            $table->text('private_events_info')->nullable()->after('closest_landmark');
            $table->text('catering_info')->nullable()->after('private_events_info');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn([
                'executive_chef',
                'dietary_options',
                'beverage_options',
                'amenities',
                'smoking_policy',
                'delivery_options',
                'safety_policies',
                'parking_info',
                'closest_landmark',
                'private_events_info',
                'catering_info',
            ]);
        });
    }
};
