<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_program_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('start_points');
            $table->unsignedInteger('end_points')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['reward_program_id', 'slug']);
        });

        $programId = DB::table('reward_programs')
            ->where('slug', 'moretables-lifetime-loyalty')
            ->value('id');

        if ($programId) {
            DB::table('reward_levels')->insert([
                [
                    'reward_program_id' => $programId,
                    'name' => 'Bronze',
                    'slug' => 'bronze',
                    'start_points' => 0,
                    'end_points' => 999,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'reward_program_id' => $programId,
                    'name' => 'Silver',
                    'slug' => 'silver',
                    'start_points' => 1000,
                    'end_points' => 4999,
                    'sort_order' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'reward_program_id' => $programId,
                    'name' => 'Gold',
                    'slug' => 'gold',
                    'start_points' => 5000,
                    'end_points' => 9999,
                    'sort_order' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'reward_program_id' => $programId,
                    'name' => 'Platinum',
                    'slug' => 'platinum',
                    'start_points' => 10000,
                    'end_points' => null,
                    'sort_order' => 3,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_levels');
    }
};
