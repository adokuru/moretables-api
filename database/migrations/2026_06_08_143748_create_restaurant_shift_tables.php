<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('restaurant_shifts')) {
            Schema::create('restaurant_shifts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('restaurant_meal_type_id')->nullable()->constrained('restaurant_meal_types')->nullOnDelete();
                $table->string('name');
                $table->unsignedTinyInteger('day_of_week');
                $table->time('starts_at');
                $table->time('ends_at');
                $table->string('color', 7)->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('turn_control_release_policy')->default('dont_release');
                $table->unsignedSmallInteger('release_hours_before')->nullable();
                $table->unsignedSmallInteger('flow_interval_minutes')->default(15);
                $table->unsignedSmallInteger('flow_default_max_covers')->default(3);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['restaurant_id', 'day_of_week', 'is_active']);
            });
        }

        if (! Schema::hasTable('restaurant_shift_turn_times')) {
            Schema::create('restaurant_shift_turn_times', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_shift_id')->constrained('restaurant_shifts')->cascadeOnDelete();
                $table->unsignedTinyInteger('party_size');
                $table->unsignedSmallInteger('duration_minutes');
                $table->timestamps();

                $table->unique(['restaurant_shift_id', 'party_size'], 'rs_shift_turn_times_party_unique');
            });
        } elseif (! Schema::hasIndex('restaurant_shift_turn_times', 'rs_shift_turn_times_party_unique')) {
            Schema::table('restaurant_shift_turn_times', function (Blueprint $table) {
                $table->unique(['restaurant_shift_id', 'party_size'], 'rs_shift_turn_times_party_unique');
            });
        }

        if (! Schema::hasTable('restaurant_shift_table_availability')) {
            Schema::create('restaurant_shift_table_availability', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_shift_id')->constrained('restaurant_shifts')->cascadeOnDelete();
                $table->foreignId('dining_area_id')->nullable()->constrained('dining_areas')->cascadeOnDelete();
                $table->string('table_type')->nullable();
                $table->boolean('include_combinations')->default(true);
                $table->boolean('is_reservable')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('restaurant_shift_turn_controls')) {
            Schema::create('restaurant_shift_turn_controls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_shift_id')->constrained('restaurant_shifts')->cascadeOnDelete();
                $table->string('rule_type');
                $table->unsignedTinyInteger('party_size')->nullable();
                $table->foreignId('restaurant_table_id')->nullable()->constrained('restaurant_tables')->cascadeOnDelete();
                $table->unsignedTinyInteger('min_turns');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('restaurant_shift_flow_intervals')) {
            Schema::create('restaurant_shift_flow_intervals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_shift_id')->constrained('restaurant_shifts')->cascadeOnDelete();
                $table->time('starts_at');
                $table->unsignedSmallInteger('max_covers');
                $table->timestamps();

                $table->unique(['restaurant_shift_id', 'starts_at'], 'rs_shift_flow_int_starts_unique');
            });
        } elseif (! Schema::hasIndex('restaurant_shift_flow_intervals', 'rs_shift_flow_int_starts_unique')) {
            Schema::table('restaurant_shift_flow_intervals', function (Blueprint $table) {
                $table->unique(['restaurant_shift_id', 'starts_at'], 'rs_shift_flow_int_starts_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_shift_flow_intervals');
        Schema::dropIfExists('restaurant_shift_turn_controls');
        Schema::dropIfExists('restaurant_shift_table_availability');
        Schema::dropIfExists('restaurant_shift_turn_times');
        Schema::dropIfExists('restaurant_shifts');
    }
};
