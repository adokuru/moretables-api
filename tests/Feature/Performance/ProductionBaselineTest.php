<?php

use App\Models\CuisineOption;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\RestaurantTable;
use App\Models\RestaurantView;
use App\Models\Role;
use App\Models\SavedRestaurant;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\ReservationService;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

it('keeps availability query count stable as the slot count grows', function () {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $tomorrow = Carbon::tomorrow($restaurant->timezone);

    RestaurantHour::query()
        ->where('restaurant_id', $restaurant->id)
        ->where('day_of_week', $tomorrow->dayOfWeek)
        ->update(['opens_at' => '12:00', 'closes_at' => '16:00', 'is_closed' => false]);

    $restaurant->load(['hours', 'availabilitySchedules', 'policy']);

    DB::enableQueryLog();
    app(AvailabilityService::class)->listAvailableSlots($restaurant, $tomorrow->toDateString(), 2);
    $shortWindowQueryCount = count(DB::getQueryLog());

    RestaurantHour::query()
        ->where('restaurant_id', $restaurant->id)
        ->where('day_of_week', $tomorrow->dayOfWeek)
        ->update(['closes_at' => '23:00']);

    $restaurant->load('hours');
    DB::flushQueryLog();

    app(AvailabilityService::class)->listAvailableSlots($restaurant, $tomorrow->toDateString(), 2);
    $longWindowQueryCount = count(DB::getQueryLog());

    expect($shortWindowQueryCount)->toBe(2)
        ->and($longWindowQueryCount)->toBe($shortWindowQueryCount);
});

it('assigns the requested available table instead of the first sorted table', function () {
    $data = createBookableRestaurant();
    $actor = User::factory()->create();
    $data['table']->update(['name' => 'A-1']);
    $requestedTable = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'dining_area_id' => null,
        'name' => 'B-1',
        'max_capacity' => 4,
    ]);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 2,
        'starts_at' => now()->addDays(2)->setTime(18, 0),
        'ends_at' => now()->addDays(2)->setTime(20, 0),
    ]);

    app(ReservationService::class)->assignTable($reservation, $requestedTable, $actor);

    expect($reservation->refresh()->restaurant_table_id)->toBe($requestedTable->id);
});

it('rejects an overlapping requested table assignment', function () {
    $data = createBookableRestaurant();
    $actor = User::factory()->create();
    $startsAt = now()->addDays(2)->setTime(18, 0);
    $requestedTable = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'dining_area_id' => null,
        'max_capacity' => 4,
    ]);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 2,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHours(2),
    ]);

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $requestedTable->id,
        'party_size' => 2,
        'starts_at' => $startsAt->copy()->addMinutes(30),
        'ends_at' => $startsAt->copy()->addHours(2),
    ]);

    expect(fn () => app(ReservationService::class)->assignTable($reservation, $requestedTable, $actor))
        ->toThrow(ValidationException::class);
});

it('returns retryable validation when reservation lock contention persists', function () {
    config()->set('performance.locks.reservation_wait_seconds', 0);

    $data = createBookableRestaurant();
    $customer = User::factory()->create();
    $lock = Cache::lock("restaurant:{$data['restaurant']->id}:reservations", 10);
    $lock->get();

    try {
        expect(fn () => app(ReservationService::class)->createCustomerReservation($customer, $data['restaurant'], [
            'starts_at' => now()->addDays(2)->setTime(18, 0)->toDateTimeString(),
            'party_size' => 2,
        ]))->toThrow(ValidationException::class, 'Reservation availability changed');
    } finally {
        $lock->release();
    }
});

it('deduplicates restaurant views for the same fingerprint for thirty minutes', function () {
    $restaurant = createBookableRestaurant()['restaurant'];
    $url = "/api/v1/restaurants/{$restaurant->slug}/views";

    $this->postJson($url, ['session_id' => 'same-session'])->assertCreated()
        ->assertJsonPath('deduplicated', false);

    $this->postJson($url, ['session_id' => 'same-session'])->assertOk()
        ->assertJsonPath('deduplicated', true);

    expect(RestaurantView::query()->where('restaurant_id', $restaurant->id)->count())->toBe(1);
});

it('serves cached cuisine arrays and invalidates them after catalog changes', function () {
    CuisineOption::query()->create(['name' => 'Cache Test One', 'slug' => 'cache-test-one']);

    $this->getJson('/api/v1/cuisine-options')->assertOk();

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/v1/cuisine-options')->assertOk();
    expect(DB::getQueryLog())->toBeEmpty();

    CuisineOption::query()->create(['name' => 'Cache Test Two', 'slug' => 'cache-test-two']);

    $this->getJson('/api/v1/cuisine-options')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Cache Test Two']);
});

it('overlays saved state per user without caching personalized or internal fields', function () {
    $restaurant = createBookableRestaurant()['restaurant'];
    $restaurant->update(['internal_notes' => 'merchant only']);
    $savedBy = User::factory()->create();
    $notSavedBy = User::factory()->create();

    SavedRestaurant::query()->create([
        'user_id' => $savedBy->id,
        'restaurant_id' => $restaurant->id,
    ]);

    Sanctum::actingAs($savedBy);
    $this->getJson('/api/v1/restaurants')
        ->assertOk()
        ->assertJsonPath('0.has_saved', true)
        ->assertJsonMissing(['internal_notes' => 'merchant only']);

    Sanctum::actingAs($notSavedBy);
    $this->getJson('/api/v1/restaurants')
        ->assertOk()
        ->assertJsonPath('0.has_saved', false)
        ->assertJsonMissing(['internal_notes' => 'merchant only']);
});

it('keeps cached public restaurant pages isolated', function () {
    Restaurant::factory()->count(2)->create();

    $firstPageId = $this->getJson('/api/v1/restaurants?per_page=1&page=1')
        ->assertOk()
        ->json('0.id');

    $secondPageId = $this->getJson('/api/v1/restaurants?per_page=1&page=2')
        ->assertOk()
        ->json('0.id');

    expect($secondPageId)->not->toBe($firstPageId);
});

it('applies the public write rate limit', function () {
    config()->set('performance.rate_limits.public_writes', 1);

    $restaurant = createBookableRestaurant()['restaurant'];
    $url = "/api/v1/restaurants/{$restaurant->slug}/views";

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson($url, ['session_id' => 'one'])
        ->assertCreated();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->postJson($url, ['session_id' => 'two'])
        ->assertTooManyRequests();
});

it('configures redis queues horizon supervisors and scheduled monitors', function () {
    expect(config('queue.connections.redis.after_commit'))->toBeTrue()
        ->and(config('horizon.defaults.supervisor-default.queue'))->toBe(['default'])
        ->and(config('horizon.defaults.supervisor-notifications.queue'))->toBe(['notifications'])
        ->and(config('horizon.defaults.supervisor-realtime.queue'))->toBe(['realtime']);

    Artisan::call('schedule:list', ['--json' => true]);
    $commands = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->pluck('command');

    expect($commands)->toContain(
        'php artisan horizon:snapshot',
        'php artisan queue:monitor redis:default,redis:notifications,redis:realtime --max=100',
        'php artisan db:monitor --databases=sqlite --max=100',
    );
});

it('restricts horizon to privileged admins outside local environments', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $regularUser = User::factory()->create();
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    expect(Gate::forUser($regularUser)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
});

it('creates the additive hot query indexes', function () {
    $indexes = collect(DB::select("pragma index_list('reservations')"))->pluck('name');

    expect($indexes)->toContain(
        'reservations_rest_status_starts_idx',
        'reservations_table_status_starts_ends_idx',
        'reservations_user_rest_starts_status_idx',
        'reservations_status_starts_idx',
    );
});
