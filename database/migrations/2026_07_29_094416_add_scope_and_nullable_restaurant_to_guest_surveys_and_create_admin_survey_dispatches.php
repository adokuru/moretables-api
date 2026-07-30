<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_surveys', function (Blueprint $table): void {
            $table->string('scope')->default('restaurant')->after('id');
            $table->foreignId('restaurant_id')->nullable()->change();
            $table->unsignedInteger('version')->nullable()->change();
            $table->unsignedInteger('send_delay_minutes')->nullable()->change();
            $table->json('channels')->nullable()->change();
            $table->timestamp('scheduled_at')->nullable()->after('published_at');
            $table->index('scope');
        });

        Schema::create('admin_survey_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guest_survey_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('recipients_count')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['guest_survey_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_survey_dispatches');

        Schema::table('guest_surveys', function (Blueprint $table): void {
            $table->dropIndex(['scope']);
            $table->dropColumn(['scope', 'scheduled_at']);
            $table->foreignId('restaurant_id')->nullable(false)->change();
            $table->unsignedInteger('version')->nullable(false)->change();
            $table->unsignedInteger('send_delay_minutes')->default(120)->nullable(false)->change();
            $table->json('channels')->nullable(false)->change();
        });
    }
};
