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
        Schema::create('merchant_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_subscription_id')->nullable();
            $table->foreignId('merchant_payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('paystack');
            $table->string('reference')->unique();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('NGN');
            $table->string('channel')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('gateway_response')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index(['merchant_invoice_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_payments');
    }
};
