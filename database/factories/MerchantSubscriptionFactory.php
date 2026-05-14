<?php

namespace Database\Factories;

use App\MerchantSubscriptionStatus;
use App\Models\BillingPlan;
use App\Models\MerchantSubscription;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantSubscription>
 */
class MerchantSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'billing_plan_id' => BillingPlan::factory(),
            'provider' => 'paystack',
            'status' => MerchantSubscriptionStatus::Active,
            'provider_customer_code' => 'CUS_'.$this->faker->unique()->bothify('????????'),
            'provider_subscription_code' => 'SUB_'.$this->faker->unique()->bothify('????????'),
            'provider_email_token' => $this->faker->sha1(),
            'provider_authorization_code' => 'AUTH_'.$this->faker->unique()->bothify('????????'),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
            'next_payment_at' => now()->addMonth(),
            'cancel_at_period_end' => false,
            'metadata' => [],
            'raw_provider_payload' => [],
        ];
    }
}
