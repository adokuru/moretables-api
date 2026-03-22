<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('business_phone')->nullable();
            $table->string('business_email')->nullable();
            $table->string('website')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('tax_id')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('website')->nullable();
            $table->string('instagram_handle')->nullable();
            $table->string('average_price_range')->nullable();
            $table->string('dining_style')->nullable();
            $table->string('dress_code')->nullable();
            $table->unsignedInteger('total_seating_capacity')->nullable();
            $table->unsignedInteger('number_of_tables')->nullable();
            $table->string('menu_source')->nullable();
            $table->string('menu_link')->nullable();
            $table->json('payment_options')->nullable();
            $table->json('accessibility_features')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'business_phone',
                'business_email',
                'website',
                'billing_email',
                'tax_id',
                'registration_number',
                'city',
                'state',
                'country',
            ]);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'website',
                'instagram_handle',
                'average_price_range',
                'dining_style',
                'dress_code',
                'total_seating_capacity',
                'number_of_tables',
                'menu_source',
                'menu_link',
                'payment_options',
                'accessibility_features',
            ]);
        });
    }
};
