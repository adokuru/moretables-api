<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\Role;
use App\Models\User;
use App\RestaurantStatus;
use App\Services\ScopedRoleAssignmentService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('app:generate-fake-reviews
    {restaurant? : Restaurant ID or slug}
    {--count=20 : Number of reviews to create per restaurant}
    {--restaurants=1 : Number of active restaurants to target when no restaurant is provided}')]
#[Description('Generate fake customer reviews for one or more restaurants')]
class GenerateFakeReviews extends Command
{
    public function __construct(protected ScopedRoleAssignmentService $scopedRoleAssignmentService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $restaurantsLimit = max(1, (int) $this->option('restaurants'));

        $this->call('db:seed', [
            '--class' => RoleAndPermissionSeeder::class,
            '--no-interaction' => true,
        ]);

        $restaurants = $this->resolveRestaurants($restaurantsLimit);

        if ($restaurants->isEmpty()) {
            $this->error('No restaurants found to review.');

            return self::FAILURE;
        }

        $createdReviews = 0;
        $createdCustomers = 0;

        foreach ($restaurants as $restaurant) {
            $customers = User::factory()->count($count)->create();

            foreach ($customers as $customer) {
                $this->scopedRoleAssignmentService->assignRole(
                    user: $customer,
                    roleName: Role::Customer,
                    assignedBy: $customer->id,
                );
            }

            $createdCustomers += $customers->count();

            foreach ($customers as $customer) {
                RestaurantReview::factory()->create([
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $customer->id,
                ]);

                $createdReviews++;
            }
        }

        $this->info('Fake reviews generated successfully.');
        $this->table(
            ['Restaurant', 'Reviews created'],
            $restaurants->map(fn (Restaurant $restaurant): array => [$restaurant->name, $count])->all(),
        );
        $this->comment("Created {$createdReviews} reviews using {$createdCustomers} customer accounts.");
        $this->comment('Example: php artisan app:generate-fake-reviews rated-champion --count=12');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Restaurant>
     */
    protected function resolveRestaurants(int $restaurantsLimit): Collection
    {
        $restaurantArgument = $this->argument('restaurant');

        if (is_string($restaurantArgument) && $restaurantArgument !== '') {
            $restaurant = Restaurant::query()
                ->whereKey($restaurantArgument)
                ->orWhere('slug', $restaurantArgument)
                ->first();

            return $restaurant ? collect([$restaurant]) : collect();
        }

        return Restaurant::query()
            ->where('status', RestaurantStatus::Active)
            ->inRandomOrder()
            ->limit($restaurantsLimit)
            ->get();
    }
}
