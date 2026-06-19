<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_shift_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTimeTz('service_starts_at');
            $table->dateTimeTz('service_ends_at');
            $table->text('body');
            $table->timestamps();
            $table->index(['restaurant_id', 'service_starts_at', 'service_ends_at'], 'shift_notes_service_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_shift_notes');
    }
};
