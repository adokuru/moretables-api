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
        Schema::create('merchant_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('paystack');
            $table->string('provider_customer_code')->nullable();
            $table->string('authorization_code')->nullable()->unique();
            $table->string('email')->nullable();
            $table->boolean('reusable')->default(false);
            $table->string('brand')->nullable();
            $table->string('card_type')->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('exp_month', 2)->nullable();
            $table->string('exp_year', 4)->nullable();
            $table->string('bin')->nullable();
            $table->string('bank')->nullable();
            $table->string('signature')->nullable();
            $table->string('channel')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['restaurant_id', 'is_default']);
            $table->index(['provider', 'provider_customer_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_payment_methods');
    }
};
