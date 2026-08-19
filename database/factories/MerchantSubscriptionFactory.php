<?php

namespace Database\Factories;

use App\MerchantSubscriptionStatus;
use App\Models\BillingPlan;
use App\Models\MerchantSubscription;
use App\Models\Organization;
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
            'organization_id' => fn (array $attributes): ?int => Restaurant::query()
                ->whereKey($attributes['restaurant_id'])
                ->value('organization_id'),
            'billing_plan_id' => fn () => BillingPlan::query()->value('id') ?? BillingPlan::factory()->create()->id,
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

    /**
     * A business-level record: owned by the organization, inherited by its restaurants.
     */
    public function forBusiness(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->id,
            'restaurant_id' => null,
        ]);
    }
}
