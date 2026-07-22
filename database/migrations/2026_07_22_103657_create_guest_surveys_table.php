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
        Schema::create('guest_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('publication_sequence')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->string('status')->default('draft');
            $table->json('questions');
            $table->unsignedInteger('send_delay_minutes')->default(120);
            $table->json('channels');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'version']);
            $table->unique(['restaurant_id', 'publication_sequence']);
            $table->index(['restaurant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_surveys');
    }
};
