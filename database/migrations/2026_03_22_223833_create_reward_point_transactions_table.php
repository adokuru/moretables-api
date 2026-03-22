<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('adjustment');
            $table->integer('points');
            $table->integer('balance_after');
            $table->string('description')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reward_program_id', 'user_id', 'created_at'], 'reward_points_program_user_created_index');
            $table->index(['reference_type', 'reference_id'], 'reward_points_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_point_transactions');
    }
};
