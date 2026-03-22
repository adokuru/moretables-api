<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;

it('generates organizations restaurants staff customers and featured restaurants for testing', function () {
    $this->artisan('app:generate-testing-data', [
        '--organizations' => 2,
        '--restaurants-per-organization' => 3,
        '--managers-per-restaurant' => 2,
        '--staff-per-restaurant' => 4,
        '--customers' => 5,
        '--featured' => 4,
        '--tables-per-restaurant' => 6,
        '--menu-items-per-restaurant' => 5,
    ])->assertSuccessful();

    expect(Organization::query()->count())->toBe(2);
    expect(Restaurant::query()->count())->toBe(6);
    expect(Restaurant::query()->where('is_featured', true)->count())->toBe(4);
    expect(Restaurant::query()->where('number_of_tables', 6)->count())->toBe(6);
    expect(Restaurant::query()->where('total_seating_capacity', 24)->count())->toBe(6);

    $ownerCount = User::query()
        ->whereHas('roleAssignments.role', fn ($query) => $query->where('name', Role::OrganizationOwner))
        ->count();

    $principalAdminCount = User::query()
        ->whereHas('roleAssignments.role', fn ($query) => $query->where('name', Role::PrincipalAdmin))
        ->count();

    $operationsCount = User::query()
        ->whereHas('roleAssignments.role', fn ($query) => $query->where('name', Role::Operations))
        ->count();

    $analyticsCount = User::query()
        ->whereHas('roleAssignments.role', fn ($query) => $query->where('name', Role::AnalyticsReporting))
        ->count();

    $marketingCount = User::query()
        ->whereHas('roleAssignments.role', fn ($query) => $query->where('name', Role::MarketingGrowth))
        ->count();

    $guestRelationsCount = User::query()
        ->whereHas('roleAssignments.role', fn ($query) => $query->where('name', Role::GuestRelations))
        ->count();

    $customerCount = User::query()
        ->whereHas('roleAssignments.role', fn ($query) => $query->where('name', Role::Customer))
        ->count();

    expect($ownerCount)->toBe(2);
    expect($principalAdminCount)->toBe(2);
    expect($operationsCount)->toBe(12);
    expect($analyticsCount)->toBe(12);
    expect($marketingCount)->toBe(6);
    expect($guestRelationsCount)->toBe(6);
    expect($customerCount)->toBe(5);
    expect(Restaurant::query()->withCount('tables')->firstOrFail()->tables_count)->toBe(6);
    expect(Restaurant::query()->withCount('menuItems')->firstOrFail()->menu_items_count)->toBe(5);
});
