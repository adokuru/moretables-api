<?php

use App\AuditLogActorType;
use App\AuthChallengeStatus;
use App\OnboardingRequestStatus;
use App\ReservationSource;
use App\ReservationStatus;
use App\RestaurantStatus;
use App\TableStatus;
use App\WaitlistStatus;
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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('primary_contact_name')->nullable();
            $table->string('primary_contact_email')->nullable();
            $table->string('primary_contact_phone')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('restaurants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default(RestaurantStatus::Draft->value);
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->default('Nigeria');
            $table->string('timezone')->default('Africa/Lagos');
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('collection')->default('gallery');
            $table->string('url');
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('restaurant_cuisines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['restaurant_id', 'name']);
        });

        Schema::create('restaurant_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->unique(['restaurant_id', 'day_of_week']);
        });

        Schema::create('restaurant_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('reservation_duration_minutes')->default(120);
            $table->unsignedInteger('booking_window_days')->default(30);
            $table->unsignedInteger('cancellation_cutoff_hours')->default(24);
            $table->unsignedInteger('min_party_size')->default(1);
            $table->unsignedInteger('max_party_size')->default(12);
            $table->boolean('deposit_required')->default(false);
            $table->timestamps();
        });

        Schema::create('dining_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('min_capacity')->default(1);
            $table->unsignedInteger('max_capacity');
            $table->string('status')->default(TableStatus::Available->value);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['restaurant_id', 'name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id', 'organization_id', 'restaurant_id'], 'user_roles_scope_unique');
            $table->index(['scope_type', 'organization_id', 'restaurant_id'], 'user_roles_scope_index');
        });

        Schema::create('auth_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default(AuthChallengeStatus::Pending->value);
            $table->string('challenge_token')->unique();
            $table->string('code_hash');
            $table->timestamp('code_expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('guest_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone');
            $table->text('notes')->nullable();
            $table->boolean('is_temporary')->default(true);
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restaurant_table_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('canceled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reservation_reference')->unique();
            $table->string('source')->default(ReservationSource::Customer->value);
            $table->string('status')->default(ReservationStatus::Booked->value);
            $table->unsignedInteger('party_size');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['restaurant_id', 'starts_at']);
            $table->index(['restaurant_id', 'status']);
        });

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default(WaitlistStatus::Waiting->value);
            $table->unsignedInteger('party_size');
            $table->dateTime('preferred_starts_at');
            $table->dateTime('preferred_ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('seated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['restaurant_id', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type')->default(AuditLogActorType::User->value);
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restaurant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('action');
            $table->string('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['restaurant_id', 'created_at']);
        });

        Schema::create('onboarding_requests', function (Blueprint $table) {
            $table->id();
            $table->string('restaurant_name');
            $table->string('owner_name');
            $table->string('email');
            $table->string('phone');
            $table->text('address');
            $table->text('notes')->nullable();
            $table->string('status')->default(OnboardingRequestStatus::Pending->value);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_requests');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('waitlist_entries');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('guest_contacts');
        Schema::dropIfExists('auth_challenges');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('restaurant_tables');
        Schema::dropIfExists('dining_areas');
        Schema::dropIfExists('restaurant_policies');
        Schema::dropIfExists('restaurant_hours');
        Schema::dropIfExists('restaurant_cuisines');
        Schema::dropIfExists('restaurant_media');
        Schema::dropIfExists('restaurants');
        Schema::dropIfExists('organizations');
    }
};
