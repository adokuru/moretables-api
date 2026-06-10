<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_cancellation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('management_method')->default('card_hold');
            $table->string('party_size_scope')->default('all_party_sizes');
            $table->unsignedTinyInteger('min_party_size')->nullable();
            $table->unsignedTinyInteger('max_party_size')->nullable();
            $table->unsignedInteger('hold_charge_amount');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->json('days');
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['restaurant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_cancellation_policies');
    }
};
