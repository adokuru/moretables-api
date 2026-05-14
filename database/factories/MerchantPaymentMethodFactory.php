<?php

namespace Database\Factories;

use App\Models\MerchantPaymentMethod;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantPaymentMethod>
 */
class MerchantPaymentMethodFactory extends Factory
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
            'provider_customer_code' => 'CUS_'.$this->faker->unique()->bothify('????????'),
            'authorization_code' => 'AUTH_'.$this->faker->unique()->bothify('????????'),
            'email' => $this->faker->safeEmail(),
            'reusable' => true,
            'brand' => 'visa',
            'card_type' => 'visa',
            'last4' => (string) $this->faker->numberBetween(1000, 9999),
            'exp_month' => str_pad((string) $this->faker->numberBetween(1, 12), 2, '0', STR_PAD_LEFT),
            'exp_year' => (string) $this->faker->numberBetween((int) now()->format('Y'), (int) now()->addYears(5)->format('Y')),
            'bin' => '408408',
            'bank' => $this->faker->company(),
            'signature' => $this->faker->sha1(),
            'channel' => 'card',
            'metadata' => [],
            'is_default' => true,
        ];
    }
}
