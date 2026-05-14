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
        Schema::create('merchant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_plan_id')->constrained()->restrictOnDelete();
            $table->string('provider')->default('paystack');
            $table->string('status')->default('pending');
            $table->string('provider_customer_code')->nullable();
            $table->string('provider_subscription_code')->nullable()->unique();
            $table->string('provider_email_token')->nullable();
            $table->string('provider_authorization_code')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('next_payment_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->json('raw_provider_payload')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index(['provider', 'provider_customer_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_subscriptions');
    }
};
