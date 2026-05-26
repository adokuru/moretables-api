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
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notify_dining_rating_emails')->default(true)->after('last_active_at');
            $table->boolean('notify_marketing_emails')->default(true)->after('notify_dining_rating_emails');
            $table->boolean('notify_sms_alerts')->default(true)->after('notify_marketing_emails');
            $table->boolean('notify_push_notifications')->default(true)->after('notify_sms_alerts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'notify_dining_rating_emails',
                'notify_marketing_emails',
                'notify_sms_alerts',
                'notify_push_notifications',
            ]);
        });
    }
};
