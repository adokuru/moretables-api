<?php

namespace Database\Factories;

use App\MerchantPaymentStatus;
use App\Models\MerchantPayment;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantPayment>
 */
class MerchantPaymentFactory extends Factory
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
            'provider' => 'paystack',
            'reference' => 'mt_'.$this->faker->unique()->bothify('????????'),
            'status' => MerchantPaymentStatus::Pending,
            'amount' => $this->faker->randomElement([8500000, 13500000, 18500000]),
            'currency' => 'NGN',
            'channel' => 'card',
            'gateway_response' => null,
            'provider_payload' => [],
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
