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
        Schema::table('reservations', function (Blueprint $table) {
            $table->index(['restaurant_id', 'status', 'starts_at'], 'reservations_rest_status_starts_idx');
            $table->index(['restaurant_table_id', 'status', 'starts_at', 'ends_at'], 'reservations_table_status_starts_ends_idx');
            $table->index(['user_id', 'restaurant_id', 'starts_at', 'status'], 'reservations_user_rest_starts_status_idx');
            $table->index(['user_id', 'starts_at'], 'reservations_user_starts_idx');
            $table->index(['status', 'starts_at'], 'reservations_status_starts_idx');
            $table->index(['starts_at'], 'reservations_starts_idx');
        });

        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->index(['restaurant_id', 'status', 'preferred_starts_at'], 'waitlist_rest_status_preferred_idx');
            $table->index(['restaurant_id', 'status', 'created_at'], 'waitlist_rest_status_created_idx');
            $table->index(['user_id', 'preferred_starts_at'], 'waitlist_user_preferred_idx');
        });

        Schema::table('guest_contacts', function (Blueprint $table) {
            $table->index(['restaurant_id', 'is_temporary', 'phone'], 'guests_rest_temp_phone_idx');
            $table->index(['restaurant_id', 'is_temporary', 'email'], 'guests_rest_temp_email_idx');
            $table->index(['restaurant_id', 'is_temporary', 'first_name', 'last_name'], 'guests_rest_temp_name_idx');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->index(['status', 'city'], 'restaurants_status_city_idx');
            $table->index(['status', 'country'], 'restaurants_status_country_idx');
            $table->index(['status', 'created_at'], 'restaurants_status_created_idx');
            $table->index(['status', 'is_featured', 'updated_at'], 'restaurants_featured_updated_idx');
        });

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->index(['restaurant_id', 'is_active', 'status', 'max_capacity'], 'tables_rest_active_status_capacity_idx');
        });

        Schema::table('restaurant_meal_schedules', function (Blueprint $table) {
            $table->index(['restaurant_id', 'day_of_week', 'opens_at'], 'meal_schedules_rest_day_opens_idx');
            $table->index(['restaurant_meal_type_id', 'day_of_week'], 'meal_schedules_type_day_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_owner_created_idx');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_owner_read_idx');
        });

        Schema::table('auth_challenges', function (Blueprint $table) {
            $table->index(['user_id', 'type', 'status'], 'auth_challenges_user_type_status_idx');
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->index(['user_id', 'access_config_id', 'restaurant_id', 'organization_id'], 'user_roles_user_access_scope_idx');
        });

        Schema::table('user_restaurant_list_items', function (Blueprint $table) {
            $table->index(['restaurant_id'], 'list_items_restaurant_idx');
        });

        Schema::table('onboarding_requests', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'onboarding_status_created_idx');
        });

        Schema::table('expo_push_tokens', function (Blueprint $table) {
            $table->index(['user_id', 'last_seen_at'], 'expo_tokens_user_seen_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expo_push_tokens', function (Blueprint $table) {
            $table->dropIndex('expo_tokens_user_seen_idx');
        });

        Schema::table('onboarding_requests', function (Blueprint $table) {
            $table->dropIndex('onboarding_status_created_idx');
        });

        Schema::table('user_restaurant_list_items', function (Blueprint $table) {
            $table->dropIndex('list_items_restaurant_idx');
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropIndex('user_roles_user_access_scope_idx');
        });

        Schema::table('auth_challenges', function (Blueprint $table) {
            $table->dropIndex('auth_challenges_user_type_status_idx');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_owner_created_idx');
            $table->dropIndex('notifications_owner_read_idx');
        });

        Schema::table('restaurant_meal_schedules', function (Blueprint $table) {
            $table->dropIndex('meal_schedules_rest_day_opens_idx');
            $table->dropIndex('meal_schedules_type_day_idx');
        });

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropIndex('tables_rest_active_status_capacity_idx');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex('restaurants_status_city_idx');
            $table->dropIndex('restaurants_status_country_idx');
            $table->dropIndex('restaurants_status_created_idx');
            $table->dropIndex('restaurants_featured_updated_idx');
        });

        Schema::table('guest_contacts', function (Blueprint $table) {
            $table->dropIndex('guests_rest_temp_phone_idx');
            $table->dropIndex('guests_rest_temp_email_idx');
            $table->dropIndex('guests_rest_temp_name_idx');
        });

        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropIndex('waitlist_rest_status_preferred_idx');
            $table->dropIndex('waitlist_rest_status_created_idx');
            $table->dropIndex('waitlist_user_preferred_idx');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_rest_status_starts_idx');
            $table->dropIndex('reservations_table_status_starts_ends_idx');
            $table->dropIndex('reservations_user_rest_starts_status_idx');
            $table->dropIndex('reservations_user_starts_idx');
            $table->dropIndex('reservations_status_starts_idx');
            $table->dropIndex('reservations_starts_idx');
        });
    }
};
