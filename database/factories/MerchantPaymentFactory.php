<?php

namespace Database\Factories;

use App\MerchantPaymentStatus;
use App\Models\MerchantPayment;
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
}
