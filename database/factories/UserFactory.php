<?php

namespace Database\Factories;

use App\Models\User;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = $this->faker->firstName();
        $lastName = $this->faker->lastName();

        return [
            'name' => $firstName.' '.$lastName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'bio' => $this->faker->sentence(),
            'birthday' => $this->faker->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->unique()->e164PhoneNumber(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'auth_method' => UserAuthMethod::Password,
            'remember_token' => Str::random(10),
            'last_active_at' => now(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function pendingGuest(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $attributes['email'],
            'first_name' => null,
            'last_name' => null,
            'bio' => null,
            'birthday' => null,
            'phone' => null,
            'email_verified_at' => null,
            'password' => null,
            'status' => UserStatus::PendingEmailVerification,
            'auth_method' => UserAuthMethod::Passwordless,
        ]);
    }
}
