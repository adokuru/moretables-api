<?php

namespace App\Console\Commands;

use App\Models\DiningArea;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPolicy;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Services\ScopedRoleAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class GenerateTestingData extends Command
{
    protected $signature = 'app:generate-testing-data
        {--organizations=3 : Number of organizations to create}
        {--restaurants-per-organization=2 : Number of restaurants to create per organization}
        {--managers-per-restaurant=1 : Number of restaurant managers to create per restaurant}
        {--staff-per-restaurant=3 : Number of restaurant staff users to create per restaurant}
        {--customers=25 : Number of customer users to create}
        {--featured=2 : Number of generated restaurants to mark as featured}
        {--tables-per-restaurant=10 : Number of tables to create per restaurant}
        {--menu-items-per-restaurant=8 : Number of menu items to create per restaurant}';

    protected $description = 'Generate faker organizations, restaurants, owners, staff, and customers for local testing.';

    public function __construct(protected ScopedRoleAssignmentService $scopedRoleAssignmentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $organizationsCount = max(1, (int) $this->option('organizations'));
        $restaurantsPerOrganization = max(1, (int) $this->option('restaurants-per-organization'));
        $managersPerRestaurant = max(0, (int) $this->option('managers-per-restaurant'));
        $staffPerRestaurant = max(0, (int) $this->option('staff-per-restaurant'));
        $customersCount = max(0, (int) $this->option('customers'));
        $featuredCount = max(0, (int) $this->option('featured'));
        $tablesPerRestaurant = max(1, (int) $this->option('tables-per-restaurant'));
        $menuItemsPerRestaurant = max(0, (int) $this->option('menu-items-per-restaurant'));

        $this->call('db:seed', ['--class' => 'RoleAndPermissionSeeder', '--no-interaction' => true]);

        $restaurants = collect();
        $createdOrganizations = 0;
        $createdRestaurantManagers = 0;
        $createdRestaurantStaff = 0;

        foreach (range(1, $organizationsCount) as $organizationIndex) {
            $organization = Organization::factory()->create();
            $createdOrganizations++;

            $owner = User::factory()->create();
            $this->scopedRoleAssignmentService->assignOrganizationOwner($owner, $organization, $owner->id);

            foreach (range(1, $restaurantsPerOrganization) as $restaurantIndex) {
                $restaurant = Restaurant::factory()->create([
                    'organization_id' => $organization->id,
                    'number_of_tables' => $tablesPerRestaurant,
                    'total_seating_capacity' => $tablesPerRestaurant * 4,
                ]);

                $this->scopedRoleAssignmentService->assignRestaurantManager($owner, $restaurant, $owner->id);
                $restaurants->push($restaurant);

                $this->seedRestaurantRelations(
                    restaurant: $restaurant,
                    tablesPerRestaurant: $tablesPerRestaurant,
                    menuItemsPerRestaurant: $menuItemsPerRestaurant,
                );

                $managers = User::factory()->count($managersPerRestaurant)->create();
                $staff = User::factory()->count($staffPerRestaurant)->create();

                $createdRestaurantManagers += $managers->count();
                $createdRestaurantStaff += $staff->count();

                $this->assignScopedUsers($managers, Role::RestaurantManager, $organization, $restaurant);
                $this->assignScopedUsers($staff, Role::RestaurantStaff, $organization, $restaurant);
            }

            $this->line("Created organization {$organizationIndex}/{$organizationsCount}: {$organization->name}");
        }

        if ($featuredCount > 0) {
            $restaurants
                ->shuffle()
                ->take(min($featuredCount, $restaurants->count()))
                ->each(fn (Restaurant $restaurant): bool => $restaurant->update(['is_featured' => true]));
        }

        $customers = User::factory()->count($customersCount)->create();
        $this->assignScopedUsers($customers, Role::Customer);

        $totalRestaurants = $restaurants->count();
        $totalOwners = $createdOrganizations;
        $totalCustomers = $customers->count();
        $totalFeatured = Restaurant::query()->whereIn('id', $restaurants->pluck('id'))->where('is_featured', true)->count();

        $this->newLine();
        $this->info('Testing data generated successfully.');
        $this->table(
            ['Type', 'Created'],
            [
                ['Organizations', $createdOrganizations],
                ['Organization owners', $totalOwners],
                ['Restaurants', $totalRestaurants],
                ['Restaurant managers', $createdRestaurantManagers],
                ['Restaurant staff', $createdRestaurantStaff],
                ['Customers', $totalCustomers],
                ['Featured restaurants', $totalFeatured],
            ],
        );

        $this->comment('Command example: php artisan app:generate-testing-data --organizations=5 --restaurants-per-organization=3 --staff-per-restaurant=4 --featured=6');

        return self::SUCCESS;
    }

    protected function seedRestaurantRelations(
        Restaurant $restaurant,
        int $tablesPerRestaurant,
        int $menuItemsPerRestaurant,
    ): void {
        RestaurantPolicy::factory()->create([
            'restaurant_id' => $restaurant->id,
        ]);

        $availableCuisines = collect(['Nigerian', 'African', 'Seafood', 'Steakhouse', 'Italian', 'Contemporary', 'Fusion']);

        $restaurant->cuisines()->createMany(
            $availableCuisines
                ->shuffle()
                ->take(fake()->numberBetween(1, 3))
                ->values()
                ->map(fn (string $cuisine): array => ['name' => $cuisine])
                ->all(),
        );

        foreach (range(0, 6) as $dayOfWeek) {
            RestaurantHour::factory()->create([
                'restaurant_id' => $restaurant->id,
                'day_of_week' => $dayOfWeek,
            ]);
        }

        $diningAreas = DiningArea::factory()
            ->count(fake()->numberBetween(1, 3))
            ->create([
                'restaurant_id' => $restaurant->id,
            ]);

        foreach (range(1, $tablesPerRestaurant) as $tableIndex) {
            RestaurantTable::factory()->create([
                'restaurant_id' => $restaurant->id,
                'dining_area_id' => $diningAreas->random()->id,
                'name' => 'T'.$tableIndex,
            ]);
        }

        if ($menuItemsPerRestaurant > 0) {
            RestaurantMenuItem::factory()
                ->count($menuItemsPerRestaurant)
                ->create([
                    'restaurant_id' => $restaurant->id,
                ]);
        }
    }

    /**
     * @param  Collection<int, User>  $users
     */
    protected function assignScopedUsers(
        Collection $users,
        string $roleName,
        ?Organization $organization = null,
        ?Restaurant $restaurant = null,
    ): void {
        $users->each(function (User $user) use ($roleName, $organization, $restaurant): void {
            $this->scopedRoleAssignmentService->assignRole(
                user: $user,
                roleName: $roleName,
                organization: $organization,
                restaurant: $restaurant,
                assignedBy: $user->id,
            );
        });
    }
}
