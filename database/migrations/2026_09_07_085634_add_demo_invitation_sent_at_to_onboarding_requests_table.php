<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_requests', function (Blueprint $table): void {
            $table->timestamp('demo_invitation_sent_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_requests', function (Blueprint $table): void {
            $table->dropColumn('demo_invitation_sent_at');
        });
    }
};
