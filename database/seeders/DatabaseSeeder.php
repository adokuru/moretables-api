<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\RestaurantPolicy;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleAndPermissionSeeder::class);

        $owner = User::factory()->create([
            'name' => 'MoreTables Owner',
            'first_name' => 'MoreTables',
            'last_name' => 'Owner',
            'email' => 'owner@moretables.test',
        ]);

        $organization = Organization::factory()->create([
            'name' => 'MoreTables Hospitality',
            'slug' => 'moretables-hospitality',
        ]);

        $restaurant = Restaurant::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'MoreTables Demo Restaurant',
            'slug' => 'moretables-demo-restaurant',
        ]);

        RestaurantPolicy::factory()->create(['restaurant_id' => $restaurant->id]);

        foreach (range(0, 6) as $day) {
            RestaurantHour::factory()->create([
                'restaurant_id' => $restaurant->id,
                'day_of_week' => $day,
            ]);
        }

        $roleId = Role::query()->where('name', Role::OrganizationOwner)->value('id');

        if ($roleId) {
            UserRole::query()->create([
                'user_id' => $owner->id,
                'role_id' => $roleId,
                'scope_type' => null,
                'organization_id' => $organization->id,
                'restaurant_id' => null,
                'assigned_by' => $owner->id,
            ]);
        }
    }
}
