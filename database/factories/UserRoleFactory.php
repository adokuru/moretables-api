<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRole>
 */
class UserRoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'scope_type' => null,
            'organization_id' => Organization::factory(),
            'restaurant_id' => Restaurant::factory(),
            'assigned_by' => User::factory(),
        ];
    }
}
