<?php

namespace Database\Factories;

use App\AuditLogActorType;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'actor_type' => AuditLogActorType::User,
            'organization_id' => Organization::factory(),
            'restaurant_id' => Restaurant::factory(),
            'auditable_type' => 'restaurants',
            'auditable_id' => 1,
            'action' => 'restaurant.updated',
            'description' => fake()->sentence(),
            'ip_address' => fake()->ipv4(),
            'old_values' => ['status' => 'draft'],
            'new_values' => ['status' => 'active'],
        ];
    }
}
