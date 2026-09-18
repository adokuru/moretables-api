<?php

namespace App\Console\Commands;

use App\Models\BillingPlan;
use App\Models\DiningArea;
use App\Models\MerchantSubscription;
use App\Models\Organization;
use App\Models\RestaurantAccessConfig;
use App\Models\Restaurant;
use App\Models\RestaurantShift;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\MerchantSubscriptionStatus;
use App\RestaurantStatus;
use App\Services\ScopedRoleAssignmentService;
use App\TableStatus;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Provisions the accounts App Store / Play Store reviewers sign in with, from
 * config('auth.demo'). Idempotent: safe to re-run before every review round,
 * and it repairs an account a previous round left in a half-finished state.
 *
 * A migration calls this on deploy, but it stays a command so it can be re-run
 * by hand — the common case being demo env vars that were set after the deploy
 * that ran the migration.
 */
class ProvisionDemoAccounts extends Command
{
    /** Shared with RefreshDemoReservations, which tops this restaurant's board up daily. */
    public const RESTAURANT_SLUG = 'moretables-demo-kitchen';

    protected $signature = 'app:provision-demo';

    protected $description = 'Create or repair the App Store / Play Store review demo accounts.';

    public function __construct(protected ScopedRoleAssignmentService $roles)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (config('auth.demo.emails') === []) {
            $this->warn('No demo accounts configured — set DEMO_CUSTOMER_EMAIL and/or DEMO_RESTAURANT_EMAIL. Nothing to do.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            $this->provisionCustomer();
            $this->provisionRestaurant();
        });

        return self::SUCCESS;
    }

    /**
     * Customer app reviewer. Created Active with a name so the reviewer lands
     * on Home rather than the "Create account" step.
     */
    protected function provisionCustomer(): void
    {
        $email = trim((string) config('auth.demo.customer.email'));

        if ($email === '') {
            return;
        }

        $user = $this->upsertUser($email, 'MoreTables', 'Demo', password: null);

        $this->attachGlobalRole($user, Role::Customer);

        $this->info("Customer demo ready: {$email} (code ".config('auth.demo.code').')');
    }

    /**
     * Restaurant app reviewer, plus the restaurant they manage. Deliberately a
     * dedicated organization/restaurant rather than attaching the reviewer to a
     * real one — a reviewer must never see a paying customer's guest data.
     */
    protected function provisionRestaurant(): void
    {
        $email = trim((string) config('auth.demo.restaurant.email'));
        $password = (string) config('auth.demo.restaurant.password');

        if ($email === '') {
            return;
        }

        if ($password === '') {
            $this->error('DEMO_RESTAURANT_EMAIL is set but DEMO_RESTAURANT_PASSWORD is not — skipping the restaurant demo.');

            return;
        }

        $user = $this->upsertUser($email, 'Demo', 'Manager', password: $password);

        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'moretables-demo'],
            [
                'name' => 'MoreTables Demo',
                'primary_contact_name' => $user->fullName(),
                'primary_contact_email' => $user->email,
                'status' => 'active',
            ],
        );

        $restaurant = Restaurant::query()->firstOrCreate(
            ['slug' => self::RESTAURANT_SLUG],
            [
                'organization_id' => $organization->id,
                'name' => (string) config('auth.demo.restaurant.name'),
                'email' => $user->email,
                'status' => RestaurantStatus::Active->value,
            ],
        );

        // Force the reviewer-ready state even on a restaurant that already
        // exists from a previous round in some half-onboarded state.
        $restaurant->forceFill([
            'status' => RestaurantStatus::Active->value,
            'is_profile_published' => true,
        ])->save();

        // A fresh database has no roles or access configs yet (this command can
        // run from a migration, before any seeding). Skip rather than throw, so
        // a deploy is never broken by it — re-run the command afterwards.
        $accessConfig = RestaurantAccessConfig::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('slug', 'principal_admin')
            ->first();

        if (! Role::query()->where('name', Role::PrincipalAdmin)->exists() || ! $accessConfig) {
            $this->warn('Roles or access configs are not set up yet — skipping the restaurant demo. Re-run: php artisan app:provision-demo');

            return;
        }

        $this->roles->assignOrganizationOwner($user, $organization, $user->id);
        $this->roles->assignRestaurantPrincipalAdmin($user, $restaurant, $user->id);

        $this->giveActiveSubscription($organization, $restaurant);
        $this->seedFloor($restaurant);
        $this->seedShifts($restaurant);

        // Don't leave the board empty until the nightly refresh first runs.
        $this->call('app:refresh-demo-reservations');

        $this->info("Restaurant demo ready: {$email} (code ".config('auth.demo.code').") — {$restaurant->name}");
    }

    protected function upsertUser(string $email, string $firstName, string $lastName, ?string $password): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $firstName.' '.$lastName,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ],
        );

        $user->forceFill(array_filter([
            'status' => UserStatus::Active->value,
            'auth_method' => $password === null ? UserAuthMethod::Passwordless->value : UserAuthMethod::Password->value,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password' => $password,
        ], static fn ($value): bool => $value !== null))->save();

        return $user;
    }

    protected function attachGlobalRole(User $user, string $roleName): void
    {
        $roleId = Role::query()->where('name', $roleName)->value('id');

        if (! $roleId) {
            return;
        }

        UserRole::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'role_id' => $roleId,
                'organization_id' => null,
                'restaurant_id' => null,
            ],
            ['scope_type' => null, 'assigned_by' => $user->id],
        );
    }

    /**
     * Front-of-house routes sit behind merchant.billing.active, so without a
     * live subscription the reviewer hits a 402 paywall on first launch and
     * the build gets rejected. Premium so every plan-gated feature is visible,
     * and a null period end so it never lapses mid-review.
     */
    protected function giveActiveSubscription(Organization $organization, Restaurant $restaurant): void
    {
        $plan = BillingPlan::query()->where('slug', 'premium')->first()
            ?? BillingPlan::query()->orderByDesc('sort_order')->first();

        if (! $plan) {
            $this->warn('No billing plans found — the demo restaurant will hit the paywall. Seed billing plans first.');

            return;
        }

        $subscription = MerchantSubscription::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'provider_subscription_code' => 'demo-review'],
            ['billing_plan_id' => $plan->id, 'organization_id' => $organization->id],
        );

        $subscription->forceFill([
            'billing_plan_id' => $plan->id,
            'organization_id' => $organization->id,
            'status' => MerchantSubscriptionStatus::Active->value,
            'current_period_start' => now(),
            'current_period_end' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ])->save();
    }

    protected function seedFloor(Restaurant $restaurant): void
    {
        $area = DiningArea::query()->firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'name' => 'Main'],
        );

        foreach (range(1, 8) as $number) {
            RestaurantTable::query()->firstOrCreate(
                ['restaurant_id' => $restaurant->id, 'name' => (string) $number],
                [
                    'dining_area_id' => $area->id,
                    'min_capacity' => 2,
                    'max_capacity' => $number % 2 === 0 ? 4 : 2,
                    'status' => TableStatus::Available->value,
                    'is_active' => true,
                ],
            );
        }

        $restaurant->forceFill([
            'number_of_tables' => $restaurant->tables()->count(),
        ])->save();
    }

    /**
     * Every day of the week, so whichever day the reviewer opens the app there
     * is a service period to show. Without one the dashboard reads "No Shift"
     * and no booking can be made.
     */
    protected function seedShifts(Restaurant $restaurant): void
    {
        foreach (range(0, 6) as $dayOfWeek) {
            RestaurantShift::query()->firstOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'day_of_week' => $dayOfWeek,
                    'name' => 'All Day',
                ],
                [
                    'starts_at' => '00:00',
                    'ends_at' => '23:59',
                    'is_active' => true,
                    'flow_default_max_covers' => 40,
                ],
            );
        }
    }
}
