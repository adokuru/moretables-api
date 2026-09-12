<?php

use App\BillingPlanSlug;
use App\Events\ReservationUpdated;
use App\Events\RestaurantShiftNoteUpdated;
use App\Models\DiningArea;
use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantShiftNote;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\TableCombination;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\AvailabilityAlertNotification;
use App\Notifications\ReservationLifecycleNotification;
use App\ReservationServiceStage;
use App\ReservationStatus;
use App\TableStatus;
use App\WaitlistStatus;
use Carbon\Carbon;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function actingAsFrontOfHouse(array $data, string $role = Role::Operations): User
{
    $user = User::factory()->create();
    assignScopedRole($user, $role, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($user);

    return $user;
}

function frontOfHouseUrl(array $data, string $path): string
{
    return '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/'.$path;
}

it('tracks and exposes reservation arrival, seating, and finished times', function () {
    Carbon::setTestNow('2026-07-14 10:15:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Confirmed,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/partially-arrive'))
        ->assertOk()
        ->assertJsonPath('reservation.arrived_at', '2026-07-14T10:15:00+00:00');

    Carbon::setTestNow('2026-07-14 10:20:00');
    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/arrive'))
        ->assertOk()
        ->assertJsonPath('reservation.arrived_at', '2026-07-14T10:15:00+00:00');

    Carbon::setTestNow('2026-07-14 10:30:00');
    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/seat'))
        ->assertOk()
        ->assertJsonPath('reservation.seated_at', '2026-07-14T10:30:00+00:00');

    Carbon::setTestNow('2026-07-14 11:45:00');
    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/complete'))
        ->assertOk()
        ->assertJsonPath('reservation.completed_at', '2026-07-14T11:45:00+00:00')
        ->assertJsonPath('reservation.finished_at', '2026-07-14T11:45:00+00:00');
});

it('returns the configured dining-area layout contract through the front-of-house floor endpoint', function () {
    Carbon::setTestNow('2026-07-14 12:00:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $diningArea = DiningArea::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Main Floor',
        'is_active' => true,
    ]);
    $data['table']->update([
        'dining_area_id' => $diningArea->id,
        'layout_type' => 'rect-8-tb-2-lr',
        'x_position' => 1,
        'y_position' => 2,
        'width' => 2,
        'height' => 1,
        'rotation' => 90,
        'color' => '#AABBCC',
        'chair_color' => '#112233',
    ]);
    $seated = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Seated,
        'service_stage' => ReservationServiceStage::Appetizer,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'seated_at' => now()->subMinutes(30),
    ]);
    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Booked,
        'starts_at' => now()->addHours(2),
        'ends_at' => now()->addHours(4),
    ]);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/floors/'.$diningArea->id))
        ->assertOk()
        ->assertJsonPath('floor.id', $diningArea->id)
        ->assertJsonPath('tables.0.layout_type', 'rect-8-tb-2-lr')
        ->assertJsonPath('tables.0.x_position', 1)
        ->assertJsonPath('tables.0.y_position', 2)
        ->assertJsonPath('tables.0.width', 2)
        ->assertJsonPath('tables.0.height', 1)
        ->assertJsonPath('tables.0.rotation', 90)
        ->assertJsonPath('tables.0.rotate', 'r2')
        ->assertJsonPath('tables.0.table_color', '#AABBCC')
        ->assertJsonPath('tables.0.chair_color', '#112233')
        ->assertJsonPath('tables.0.live_status', 'occupied')
        ->assertJsonPath('tables.0.current_reservation.id', $seated->id)
        ->assertJsonPath('tables.0.current_reservation.service_stage', ReservationServiceStage::Appetizer->value);
});

it('keeps seated floor and list state inside the requested service day', function () {
    Carbon::setTestNow('2026-07-14 12:00:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $diningArea = DiningArea::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'is_active' => true,
    ]);
    $data['table']->update(['dining_area_id' => $diningArea->id]);
    $seated = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Seated,
        'starts_at' => Carbon::parse('2026-07-14 12:00:00'),
        'ends_at' => Carbon::parse('2026-07-14 14:00:00'),
        'seated_at' => Carbon::parse('2026-07-14 12:00:00'),
    ]);
    $future = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Booked,
        'starts_at' => Carbon::parse('2026-07-15 12:00:00'),
        'ends_at' => Carbon::parse('2026-07-15 14:00:00'),
    ]);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/floors/'.$diningArea->id.'?date=2026-07-14&starts_at=18:00&ends_at=23:00'))
        ->assertOk()
        ->assertJsonPath('tables.0.live_status', 'occupied')
        ->assertJsonPath('tables.0.current_reservation.id', $seated->id);
    $this->getJson(frontOfHouseUrl($data, 'front-of-house/seated?date=2026-07-14&starts_at=18:00&ends_at=23:00'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $seated->id);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/floors/'.$diningArea->id.'?date=2026-07-15'))
        ->assertOk()
        ->assertJsonPath('tables.0.live_status', 'reserved')
        ->assertJsonPath('tables.0.current_reservation.id', $future->id);
    $this->getJson(frontOfHouseUrl($data, 'front-of-house/seated?date=2026-07-15'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns chronological cross-midnight service periods and enforces the 31 day range', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    $date = Carbon::parse('2026-06-20', $data['restaurant']->timezone ?: config('app.timezone'));
    $data['restaurant']->hours()->update(['is_closed' => true]);
    $data['restaurant']->hours()->where('day_of_week', $date->dayOfWeek)->update([
        'opens_at' => '18:00',
        'closes_at' => '02:00',
        'is_closed' => false,
    ]);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/service-periods?from=2026-06-20&to=2026-06-21'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.starts_at', '18:00')
        ->assertJsonPath('data.0.ends_at', '02:00')
        ->assertJsonPath('data.0.default_turn_time_minutes', 120)
        ->assertJsonPath('data.0.turn_times', [])
        ->assertJsonPath('data.0.source', 'restaurant_hours');

    $shift = $data['restaurant']->shifts()->create([
        'name' => 'Saturday Dinner',
        'day_of_week' => $date->dayOfWeek,
        'starts_at' => '18:00',
        'ends_at' => '23:00',
        'is_active' => true,
    ]);
    $shift->turnTimes()->create(['party_size' => 2, 'duration_minutes' => 90]);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/service-periods?from=2026-06-20&to=2026-06-20'))
        ->assertOk()
        ->assertJsonPath('data.0.source', 'weekly_shift')
        ->assertJsonPath('data.0.turn_times.0.party_size', 2)
        ->assertJsonPath('data.0.turn_times.0.duration_minutes', 90);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/service-periods?from=2026-06-01&to=2026-07-02'))
        ->assertUnprocessable();
});

it('requires a table before seating and moves completed tables through cleaning to ready', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $staff = actingAsFrontOfHouse($data);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => null,
        'user_id' => User::factory(),
        'status' => ReservationStatus::Arrived,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/seat'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('restaurant_table_id');

    $reservation->update(['restaurant_table_id' => $data['table']->id]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/seat'))
        ->assertOk()
        ->assertJsonPath('reservation.status', ReservationStatus::Seated->value)
        ->assertJsonPath('reservation.service_stage', ReservationServiceStage::Seated->value);

    $this->patchJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/service-stage'), [
        'service_stage' => ReservationServiceStage::Entree->value,
    ])->assertOk()->assertJsonPath('reservation.service_stage', ReservationServiceStage::Entree->value);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/complete'))
        ->assertOk()
        ->assertJsonPath('reservation.status', ReservationStatus::Completed->value);

    expect($data['table']->refresh()->status)->toBe(TableStatus::Cleaning);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/available-tables?starts_at='.urlencode(now()->addDay()->toIso8601String()).'&party_size=2'))
        ->assertOk()
        ->assertJsonMissing(['id' => $data['table']->id]);

    $this->patchJson(frontOfHouseUrl($data, 'tables/'.$data['table']->id.'/status'), [
        'status' => TableStatus::Available->value,
    ])->assertOk()->assertJsonPath('table.status', TableStatus::Available->value);

    $event = new ReservationUpdated($reservation->refresh(), 'service_stage_updated');
    expect($event->broadcastWith()['service_stage'])->toBe(ReservationServiceStage::Entree->value)
        ->and($event->broadcastOn()[0]->name)->toBe('private-restaurant.'.$data['restaurant']->id)
        ->and($staff->canAccessRestaurant($data['restaurant']))->toBeTrue();
});

it('rejects seating at a table occupied by another party and requests reassignment', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $seatedGuest = User::factory()->create(['first_name' => 'Seated', 'last_name' => 'Guest']);
    $blockingReservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => $seatedGuest->id,
        'status' => ReservationStatus::Seated,
        'service_stage' => ReservationServiceStage::Appetizer,
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHour(),
        'seated_at' => now()->subHours(3),
    ]);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Arrived,
        'starts_at' => now(),
        'ends_at' => now()->addHours(2),
    ]);

    $when = $blockingReservation->starts_at->copy()->timezone($data['restaurant']->timezone)->format('D, M j \a\t g:i A');

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/seat'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('restaurant_table_id')
        ->assertJsonPath(
            'errors.restaurant_table_id.0',
            "Table {$data['table']->name} is still seated with Seated Guest's reservation from {$when}. Mark that reservation finished to free the table, or assign a different one.",
        );

    expect($reservation->refresh())
        ->status->toBe(ReservationStatus::Arrived)
        ->seated_at->toBeNull();
});

it('preassigns a reservation table without seating or changing its status', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Arrived,
        'starts_at' => now()->addDay()->setTime(18, 0),
        'ends_at' => now()->addDay()->setTime(20, 0),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/assign-table'), [
        'restaurant_table_id' => $data['table']->id,
    ])
        ->assertOk()
        ->assertJsonPath('reservation.table.id', $data['table']->id)
        ->assertJsonPath('reservation.status', ReservationStatus::Arrived->value)
        ->assertJsonPath('reservation.seated_at', null);

    expect($reservation->refresh())
        ->restaurant_table_id->toBe($data['table']->id)
        ->status->toBe(ReservationStatus::Arrived)
        ->seated_at->toBeNull();
});

it('moves a seated reservation to another table and updates both table states', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $data['table']->update(['status' => TableStatus::Occupied]);
    $newTable = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'dining_area_id' => $data['table']->dining_area_id,
        'name' => 'New table',
        'status' => TableStatus::Available,
    ]);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Seated,
        'service_stage' => ReservationServiceStage::Seated,
        'starts_at' => now(),
        'ends_at' => now()->addHours(2),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/assign-table'), [
        'restaurant_table_id' => $newTable->id,
    ])
        ->assertOk()
        ->assertJsonPath('reservation.table.id', $newTable->id)
        ->assertJsonPath('reservation.status', ReservationStatus::Seated->value)
        ->assertJsonPath('reservation.service_stage', ReservationServiceStage::Seated->value);

    expect($reservation->refresh()->restaurant_table_id)->toBe($newTable->id)
        ->and($data['table']->refresh()->status)->toBe(TableStatus::Available)
        ->and($newTable->refresh()->status)->toBe(TableStatus::Occupied);
});

it('validates seated table moves against live occupancy and upcoming bookings from now', function () {
    Carbon::setTestNow('2026-07-14 20:00:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Seated,
        'starts_at' => now()->subHours(4),
        'ends_at' => now()->subHours(2),
        'seated_at' => now()->subHours(4),
    ]);
    $upcomingTable = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => TableStatus::Available,
    ]);
    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $upcomingTable->id,
        'status' => ReservationStatus::Booked,
        'starts_at' => now()->addMinutes(30),
        'ends_at' => now()->addHours(2),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/assign-table'), [
        'restaurant_table_id' => $upcomingTable->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');

    $occupiedTable = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => TableStatus::Occupied,
    ]);
    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $occupiedTable->id,
        'status' => ReservationStatus::Seated,
        'starts_at' => now()->subHours(5),
        'ends_at' => now()->subHours(3),
        'seated_at' => now()->subHours(5),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/assign-table'), [
        'restaurant_table_id' => $occupiedTable->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_id');
    expect($reservation->refresh()->restaurant_table_id)->toBe($data['table']->id);
});

it('sends a review request to the guest when a reservation is completed', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $guest = User::factory()->create();
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => $guest->id,
        'status' => ReservationStatus::Seated,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/complete'))
        ->assertOk();

    Notification::assertSentTo(
        $guest,
        ReservationLifecycleNotification::class,
        fn (ReservationLifecycleNotification $notification): bool => (new ReflectionProperty($notification, 'action'))->getValue($notification) === 'review_request',
    );
});

it('rejects service stages and completion until the reservation is seated', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Confirmed,
    ]);

    $this->patchJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/service-stage'), [
        'service_stage' => ReservationServiceStage::Appetizer->value,
    ])->assertUnprocessable()->assertJsonValidationErrors('service_stage');

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/complete'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

// Covers the ReservationService::updateServiceStage carve-out added for the
// front-of-house "Bussing Needed" quick action: a completed reservation may
// still toggle its service stage between bussing_needed and finished (so
// staff know whether a table still needs clearing), but no other service
// stage change is allowed once the guest has left.
it('allows toggling a completed reservation between bussing needed and finished but rejects other stage changes', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Seated,
        'service_stage' => ReservationServiceStage::Seated,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/complete'))
        ->assertOk()
        ->assertJsonPath('reservation.status', ReservationStatus::Completed->value);

    $this->patchJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/service-stage'), [
        'service_stage' => ReservationServiceStage::Entree->value,
    ])->assertUnprocessable()->assertJsonValidationErrors('service_stage');

    $this->patchJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/service-stage'), [
        'service_stage' => ReservationServiceStage::BussingNeeded->value,
    ])->assertOk()->assertJsonPath('reservation.service_stage', ReservationServiceStage::BussingNeeded->value);

    $this->patchJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/service-stage'), [
        'service_stage' => ReservationServiceStage::Finished->value,
    ])->assertOk()->assertJsonPath('reservation.service_stage', ReservationServiceStage::Finished->value);

    $this->patchJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/service-stage'), [
        'service_stage' => ReservationServiceStage::BussingNeeded->value,
    ])->assertOk()->assertJsonPath('reservation.service_stage', ReservationServiceStage::BussingNeeded->value);
});

it('groups operational statuses and cancellation records correctly', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $startsAt = now($data['restaurant']->timezone ?: config('app.timezone'))->addDay()->setTime(19, 0);

    foreach ([
        ReservationStatus::RunningLate,
        ReservationStatus::LeftMessage,
        ReservationStatus::PartiallyArrived,
        ReservationStatus::Cancelled,
        ReservationStatus::NoShow,
    ] as $status) {
        Reservation::factory()->create([
            'restaurant_id' => $data['restaurant']->id,
            'restaurant_table_id' => null,
            'status' => $status,
            'party_size' => 2,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
        ]);
    }

    $query = '?date='.$startsAt->toDateString();
    $this->getJson(frontOfHouseUrl($data, 'front-of-house/summary'.$query))
        ->assertOk()
        ->assertJsonPath('summary.reservation_count', 2)
        ->assertJsonPath('summary.arrived_count', 1)
        ->assertJsonPath('summary.no_show_count', 1);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/removed'.$query))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('seats a waitlist party into the Seated bucket when a table is assigned', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => WaitlistStatus::Waiting,
        'party_size' => 2,
        'preferred_starts_at' => now()->addDay()->setTime(18, 0),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/assign-table'), [
        'restaurant_table_id' => $data['table']->id,
    ])
        ->assertOk()
        ->assertJsonPath('reservation.status', ReservationStatus::Seated->value)
        ->assertJsonPath('reservation.service_stage', ReservationServiceStage::Seated->value);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Seated);
});

it('preassigns a waitlist table without seating or moving the entry', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => WaitlistStatus::Arrived,
        'party_size' => 2,
        'preferred_starts_at' => now()->addDay()->setTime(18, 0),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/preassign-table'), [
        'restaurant_table_id' => $data['table']->id,
    ])
        ->assertOk()
        ->assertJsonPath('waitlist_entry.table.id', $data['table']->id)
        ->assertJsonPath('waitlist_entry.status', WaitlistStatus::Arrived->value)
        ->assertJsonPath('waitlist_entry.seated_at', null);

    expect($entry->refresh())
        ->restaurant_table_id->toBe($data['table']->id)
        ->status->toBe(WaitlistStatus::Arrived)
        ->seated_at->toBeNull();
});

it('allows preassigning a waitlist table that another party is currently seated at', function () {
    // Preassignment is tentative and doesn't check occupancy at all — the
    // real "can't double-seat a table" guard only runs later, when the
    // waitlist entry is actually converted to a seated reservation via
    // assign-table (see the "still allows seating it" test above).
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Seated,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
    ]);

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => WaitlistStatus::Waiting,
        'party_size' => 2,
        'preferred_starts_at' => now()->addMinutes(10),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/preassign-table'), [
        'restaurant_table_id' => $data['table']->id,
    ])
        ->assertOk()
        ->assertJsonPath('waitlist_entry.table.id', $data['table']->id);

    expect($entry->refresh()->restaurant_table_id)->toBe($data['table']->id);
});

it('marks a waitlist entry arrived without moving it out of the waitlist bucket, then still allows seating it', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => WaitlistStatus::Waiting,
        'party_size' => 2,
        'preferred_starts_at' => now()->addDay()->setTime(18, 0),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/arrive'))
        ->assertOk()
        ->assertJsonPath('waitlist_entry.status', WaitlistStatus::Arrived->value);

    expect($entry->refresh())
        ->status->toBe(WaitlistStatus::Arrived)
        ->arrived_at->not->toBeNull();

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/waitlist'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $entry->id);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/assign-table'), [
        'restaurant_table_id' => $data['table']->id,
    ])
        ->assertOk()
        ->assertJsonPath('reservation.status', ReservationStatus::Seated->value);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Seated);
});

it('separates availability alerts from the seating waitlist', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    $seatingEntry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'preferred_starts_at' => now()->addHour(),
    ]);
    $alertDate = now()->addDay()->toDateString();
    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'preferred_starts_at' => now()->addDay()->setTime(12, 0),
        'preferred_ends_at' => now()->addDay()->setTime(13, 0),
    ]);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/waitlist'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $seatingEntry->id)
        ->assertJsonPath('data.0.type', 'seating');

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/availability-alerts?date='.$alertDate))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $alert->id)
        ->assertJsonPath('data.0.type', 'availability_alert');

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$alert->id.'/notify'))
        ->assertOk()
        ->assertJsonPath('waitlist_entry.status', WaitlistStatus::Notified->value);
    Notification::assertSentTo($alert->user, AvailabilityAlertNotification::class);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$alert->id.'/cancel'))
        ->assertOk()
        ->assertJsonPath('waitlist_entry.status', WaitlistStatus::Cancelled->value);
});

it('rejects arriving a waitlist entry that is not waiting or notified', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => WaitlistStatus::Cancelled,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/arrive'))
        ->assertUnprocessable();
});

it('marks a waitlist entry partially arrived without moving it out of the waitlist bucket, then still allows seating it', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => WaitlistStatus::Waiting,
        'party_size' => 2,
        'preferred_starts_at' => now()->addDay()->setTime(18, 0),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/partially-arrive'))
        ->assertOk()
        ->assertJsonPath('waitlist_entry.status', WaitlistStatus::PartiallyArrived->value);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::PartiallyArrived);

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/waitlist'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $entry->id);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/assign-table'), [
        'restaurant_table_id' => $data['table']->id,
    ])
        ->assertOk()
        ->assertJsonPath('reservation.status', ReservationStatus::Seated->value);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Seated);
});

it('rejects partially-arriving a waitlist entry that is not waiting, notified, or arrived', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => WaitlistStatus::Cancelled,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/partially-arrive'))
        ->assertUnprocessable();
});

it('allows toggling a waitlist entry between arrived and partially arrived', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => WaitlistStatus::Waiting,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/arrive'))
        ->assertOk();
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Arrived);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/partially-arrive'))
        ->assertOk();
    expect($entry->refresh()->status)->toBe(WaitlistStatus::PartiallyArrived);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/arrive'))
        ->assertOk();
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Arrived);
});

it('cancels waitlist entries and enforces author-or-manager shift-note mutation rights', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    // Pre-shift Report (shift notes) is Premium-only (docs/PLAN_PERMISSIONS.md) — this
    // test predates that gate and expects creating a note to just work.
    setRestaurantBillingPlan($data['restaurant'], BillingPlanSlug::Premium);
    $author = actingAsFrontOfHouse($data);
    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => WaitlistStatus::Waiting,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/cancel'))
        ->assertOk()
        ->assertJsonPath('waitlist_entry.status', WaitlistStatus::Cancelled->value);

    $service = [
        'service_starts_at' => now()->addDay()->startOfHour()->utc()->toIso8601String(),
        'service_ends_at' => now()->addDay()->startOfHour()->addHours(5)->utc()->toIso8601String(),
    ];
    $noteId = $this->postJson(frontOfHouseUrl($data, 'front-of-house/shift-notes'), [
        ...$service,
        'body' => 'VIP arrival at 8pm.',
    ])->assertCreated()->json('note.id');

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/shift-notes?'.http_build_query($service)))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.author.id', $author->id);

    $otherStaff = User::factory()->create();
    assignScopedRole($otherStaff, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($otherStaff);
    $this->deleteJson(frontOfHouseUrl($data, 'front-of-house/shift-notes/'.$noteId))->assertForbidden();

    $manager = User::factory()->create();
    assignScopedRole($manager, Role::PrincipalAdmin, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($manager);
    $note = RestaurantShiftNote::query()->findOrFail($noteId);
    $event = new RestaurantShiftNoteUpdated($note, 'deleted');
    $this->deleteJson(frontOfHouseUrl($data, 'front-of-house/shift-notes/'.$noteId))->assertOk();

    expect($event->broadcastWith())->toMatchArray([
        'id' => $noteId,
        'restaurant_id' => $data['restaurant']->id,
        'action' => 'deleted',
    ])->and($event->broadcastOn()[0]->name)->toBe('private-restaurant.'.$data['restaurant']->id);
});

it('rejects creating or updating a shift note for a restaurant below Premium, but still allows reading and deleting', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    setRestaurantBillingPlan($data['restaurant'], BillingPlanSlug::Premium);
    actingAsFrontOfHouse($data);

    $service = [
        'service_starts_at' => now()->addDay()->startOfHour()->utc()->toIso8601String(),
        'service_ends_at' => now()->addDay()->startOfHour()->addHours(5)->utc()->toIso8601String(),
    ];
    $noteId = $this->postJson(frontOfHouseUrl($data, 'front-of-house/shift-notes'), [
        ...$service,
        'body' => 'Written while still on Premium.',
    ])->assertCreated()->json('note.id');

    setRestaurantBillingPlan($data['restaurant'], BillingPlanSlug::Core);

    $this->postJson(frontOfHouseUrl($data, 'front-of-house/shift-notes'), [
        ...$service,
        'body' => 'Should not save.',
    ])->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Premium to access the Pre-shift Report.');

    $this->patchJson(frontOfHouseUrl($data, 'front-of-house/shift-notes/'.$noteId), [
        'body' => 'Should not save either.',
    ])->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Premium to access the Pre-shift Report.');

    $this->getJson(frontOfHouseUrl($data, 'front-of-house/shift-notes?'.http_build_query($service)))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.body', 'Written while still on Premium.');

    $this->deleteJson(frontOfHouseUrl($data, 'front-of-house/shift-notes/'.$noteId))
        ->assertOk();
});

it('assigns and partially seats a reservation atomically', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Arrived,
        'starts_at' => now(),
        'ends_at' => now()->addHours(2),
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/seat'), [
        'restaurant_table_id' => $data['table']->id,
        'service_stage' => ReservationServiceStage::PartiallySeated->value,
    ])
        ->assertOk()
        ->assertJsonPath('reservation.table.id', $data['table']->id)
        ->assertJsonPath('reservation.status', ReservationStatus::Seated->value)
        ->assertJsonPath('reservation.service_stage', ReservationServiceStage::PartiallySeated->value);

    expect($reservation->refresh())
        ->restaurant_table_id->toBe($data['table']->id)
        ->service_stage->toBe(ReservationServiceStage::PartiallySeated);
});

it('moves a reservation time and table in one request', function () {
    Carbon::setTestNow('2026-07-14 12:00:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $newTable = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'dining_area_id' => $data['table']->dining_area_id,
    ]);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => ReservationStatus::Confirmed,
        'starts_at' => Carbon::parse('2026-07-14 18:00:00'),
        'ends_at' => Carbon::parse('2026-07-14 20:00:00'),
    ]);

    $this->patchJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/move'), [
        'starts_at' => '2026-07-14T19:00:00Z',
        'restaurant_table_id' => $newTable->id,
    ])
        ->assertOk()
        ->assertJsonPath('reservation.table.id', $newTable->id)
        ->assertJsonPath('reservation.starts_at', '2026-07-14T19:00:00+00:00');

    expect($reservation->refresh())
        ->restaurant_table_id->toBe($newTable->id)
        ->starts_at->toIso8601String()->toBe('2026-07-14T19:00:00+00:00');
});

it('updates waitlist details and persists the guest seating preference', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $guest = GuestContact::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'first_name' => 'Ada',
    ]);
    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'guest_contact_id' => $guest->id,
        'party_size' => 2,
    ]);

    $this->patchJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id), [
        'party_size' => 4,
        'guest_contact' => [
            'first_name' => 'Adanna',
            'seating_preference' => 'window',
        ],
    ])
        ->assertOk()
        ->assertJsonPath('waitlist_entry.party_size', 4);

    expect($entry->refresh()->party_size)->toBe(4)
        ->and($guest->refresh()->first_name)->toBe('Adanna')
        ->and($guest->preferences['seating_preference'])->toBe('window');
});

it('assigns a saved table combination and moves every table through the service lifecycle', function () {
    Carbon::setTestNow('2026-08-26 12:00:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $main = DiningArea::factory()->create(['restaurant_id' => $data['restaurant']->id, 'name' => 'Main']);
    $first = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'dining_area_id' => $main->id,
        'name' => 'M1',
        'max_capacity' => 8,
        'layout_type' => 'round',
        'status' => TableStatus::Available,
    ]);
    $second = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'dining_area_id' => $main->id,
        'name' => 'T1',
        'max_capacity' => 8,
        'layout_type' => 'round',
        'status' => TableStatus::Available,
    ]);
    $combination = TableCombination::query()->create([
        'restaurant_id' => $data['restaurant']->id,
        'dining_area_id' => $main->id,
        'table_ids' => [$first->id, $second->id],
        'min_capacity' => 1,
        'max_capacity' => 22,
    ]);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $first->id,
        'party_size' => 20,
        'status' => ReservationStatus::Arrived,
        'starts_at' => now()->addDay()->setTime(18, 0),
        'ends_at' => now()->addDay()->setTime(20, 0),
    ]);

    $query = http_build_query([
        'starts_at' => $reservation->starts_at->toIso8601String(),
        'party_size' => 20,
        'excluding_reservation_id' => $reservation->id,
    ]);
    $this->getJson(frontOfHouseUrl($data, 'front-of-house/available-tables?'.$query))
        ->assertOk()
        ->assertJsonPath('available_combinations.0.id', $combination->id)
        ->assertJsonCount(2, 'available_combinations.0.tables');

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/assign-table'), [
        'table_combination_id' => $combination->id,
    ])
        ->assertOk()
        ->assertJsonCount(2, 'reservation.tables');

    expect($reservation->refresh()->assignedTables()->pluck('restaurant_tables.id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/seat'))
        ->assertOk();

    expect($first->refresh()->status)->toBe(TableStatus::Occupied)
        ->and($second->refresh()->status)->toBe(TableStatus::Occupied);

    $date = $reservation->starts_at->timezone($data['restaurant']->timezone)->toDateString();
    $this->getJson(frontOfHouseUrl($data, "front-of-house/floors/{$main->id}?date={$date}"))
        ->assertOk()
        ->assertJsonPath('tables.0.current_reservation.id', $reservation->id)
        ->assertJsonPath('tables.1.current_reservation.id', $reservation->id);
    $timeline = $this->getJson(frontOfHouseUrl($data, "front-of-house/timelines?date={$date}"))->assertOk();
    expect(collect($timeline->json('data'))->sum(
        fn (array $table): int => collect($table['reservations'])->where('id', $reservation->id)->count(),
    ))->toBe(2);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/complete'))->assertOk();
    expect($first->refresh()->status)->toBe(TableStatus::Cleaning)
        ->and($second->refresh()->status)->toBe(TableStatus::Cleaning);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/clear-tables'))
        ->assertOk();
    expect($first->refresh()->status)->toBe(TableStatus::Available)
        ->and($second->refresh()->status)->toBe(TableStatus::Available);
});

it('hides combinations with a seated member and validates temporary waitlist combinations', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $second = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'max_capacity' => 4,
        'status' => TableStatus::Available,
    ]);
    $combination = TableCombination::query()->create([
        'restaurant_id' => $data['restaurant']->id,
        'table_ids' => [$data['table']->id, $second->id],
        'min_capacity' => 5,
        'max_capacity' => 8,
    ]);
    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $second->id,
        'status' => ReservationStatus::Seated,
        'starts_at' => now()->subHours(4),
        'ends_at' => now()->subHours(2),
    ]);

    $startsAt = now()->addDay()->setTime(18, 0);
    $query = http_build_query(['starts_at' => $startsAt->toIso8601String(), 'party_size' => 6]);
    $this->getJson(frontOfHouseUrl($data, 'front-of-house/available-tables?'.$query))
        ->assertOk()
        ->assertJsonCount(0, 'available_combinations');

    $third = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'max_capacity' => 4,
        'status' => TableStatus::Available,
    ]);
    $main = DiningArea::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    $terrace = DiningArea::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    $data['table']->update(['dining_area_id' => $main->id]);
    $third->update(['dining_area_id' => $terrace->id]);
    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'party_size' => 7,
        'preferred_starts_at' => $startsAt,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/preassign-table'), [
        'restaurant_table_ids' => [$data['table']->id, $third->id],
    ])
        ->assertOk()
        ->assertJsonCount(2, 'waitlist_entry.tables');

    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/preassign-table'), [
        'restaurant_table_ids' => [$data['table']->id, $data['table']->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_ids.1');

    $small = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'max_capacity' => 1,
    ]);
    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/preassign-table'), [
        'restaurant_table_ids' => [$small->id, $data['table']->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_ids');

    $foreignRestaurant = Restaurant::factory()->create();
    $foreignTable = RestaurantTable::factory()->create(['restaurant_id' => $foreignRestaurant->id]);
    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/preassign-table'), [
        'restaurant_table_ids' => [$data['table']->id, $foreignTable->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('restaurant_table_ids');

    $seatedReservationId = $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/assign-table'), [
        'restaurant_table_ids' => [$data['table']->id, $third->id],
    ])
        ->assertOk()
        ->assertJsonCount(2, 'reservation.tables')
        ->json('reservation.id');

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Seated)
        ->and(Reservation::query()->findOrFail($seatedReservationId)->assignedTables()->count())->toBe(2)
        ->and($data['table']->refresh()->status)->toBe(TableStatus::Occupied)
        ->and($third->refresh()->status)->toBe(TableStatus::Occupied);
});

it('uses the configured combination capacity when preassigning and seating waitlist parties', function (int $partySize, bool $useTableIds) {
    Carbon::setTestNow('2026-08-26 12:00:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $data['table']->update(['max_capacity' => 8]);
    $second = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'max_capacity' => 8,
        'status' => TableStatus::Available,
    ]);
    $combination = TableCombination::query()->create([
        'restaurant_id' => $data['restaurant']->id,
        'table_ids' => [$data['table']->id, $second->id],
        'min_capacity' => 1,
        'max_capacity' => 22,
    ]);
    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'party_size' => $partySize,
        'preferred_starts_at' => now()->addDay()->setTime(18, 0),
    ]);
    $base = 'waitlist-entries/'.$entry->id;
    $this->postJson(frontOfHouseUrl($data, $base.'/preassign-table'), [
        'table_combination_id' => $combination->id,
    ])->assertOk()->assertJsonCount(2, 'waitlist_entry.tables');

    $selection = $useTableIds
        ? ['restaurant_table_ids' => [$second->id, $data['table']->id]]
        : ['table_combination_id' => $combination->id];
    $this->postJson(frontOfHouseUrl($data, $base.'/assign-table'), $selection)
        ->assertOk()->assertJsonCount(2, 'reservation.tables');

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Seated)
        ->and($data['table']->refresh()->status)->toBe(TableStatus::Occupied)
        ->and($second->refresh()->status)->toBe(TableStatus::Occupied);
})->with([[20, false], [22, true]]);

it('rejects parties above the configured combination capacity', function (bool $useTableIds) {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $data['table']->update(['max_capacity' => 8]);
    $second = RestaurantTable::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'max_capacity' => 8,
    ]);
    $combination = TableCombination::query()->create([
        'restaurant_id' => $data['restaurant']->id,
        'table_ids' => [$data['table']->id, $second->id],
        'min_capacity' => 1,
        'max_capacity' => 22,
    ]);
    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'party_size' => 23,
        'preferred_starts_at' => now()->addDay()->setTime(18, 0),
    ]);
    $selection = $useTableIds
        ? ['restaurant_table_ids' => [$data['table']->id, $second->id]]
        : ['table_combination_id' => $combination->id];
    $this->postJson(frontOfHouseUrl($data, 'waitlist-entries/'.$entry->id.'/preassign-table'), $selection)
        ->assertUnprocessable()->assertJsonValidationErrors(array_key_first($selection));
    expect($entry->refresh()->assignedTables()->count())->toBe(0);
})->with([false, true]);

it('returns an arrival to pending and records a fresh subsequent arrival', function (ReservationStatus $status) {
    Carbon::setTestNow('2026-07-14 10:15:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'status' => $status,
        'arrived_at' => now()->subMinutes(10),
        'service_stage' => null,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
    ]);
    $reservation->assignedTables()->sync([$data['table']->id]);
    $before = $reservation->fresh()->getAttributes();
    $tableStatus = $data['table']->fresh()->status;

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/pending'))
        ->assertOk()
        ->assertJsonPath('reservation.status', 'confirmed')
        ->assertJsonPath('reservation.arrived_at', null)
        ->assertJsonPath('reservation.service_stage', null)
        ->assertJsonPath('reservation.tables.0.id', $data['table']->id);

    $reservation->refresh();
    foreach (['restaurant_table_id', 'party_size', 'starts_at', 'ends_at', 'notes', 'reservation_reference'] as $field) {
        expect($reservation->getAttributes()[$field])->toBe($before[$field]);
    }
    expect($data['table']->fresh()->status)->toBe($tableStatus);
    $this->getJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id))
        ->assertOk()->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.arrived_at', null);
    $this->getJson(frontOfHouseUrl($data, 'front-of-house/reservations?date=2026-07-14'))
        ->assertOk()->assertJsonPath('data.0.id', $reservation->id)
        ->assertJsonPath('data.0.status', 'confirmed');
    $this->getJson(frontOfHouseUrl($data, 'front-of-house/arrived?date=2026-07-14'))
        ->assertOk()->assertJsonCount(0, 'data');

    Carbon::setTestNow('2026-07-14 10:30:00');
    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/arrive'))
        ->assertOk()->assertJsonPath('reservation.arrived_at', '2026-07-14T10:30:00+00:00');
})->with([ReservationStatus::Arrived, ReservationStatus::PartiallyArrived]);

it('rejects returning other reservation states to pending', function (ReservationStatus $status) {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => $status,
    ]);

    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/pending'))
        ->assertUnprocessable()->assertJsonValidationErrors('reservation');
    expect($reservation->fresh()->status)->toBe($status);
})->with(array_values(array_filter(ReservationStatus::cases(), fn (ReservationStatus $status) => ! in_array($status, [ReservationStatus::Arrived, ReservationStatus::PartiallyArrived], true))));

it('requires restaurant permission and ownership to return a reservation to pending', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'status' => ReservationStatus::Arrived,
    ]);
    Sanctum::actingAs(User::factory()->create());
    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$reservation->id.'/pending'))->assertForbidden();

    actingAsFrontOfHouse($data);
    $other = Reservation::factory()->create(['status' => ReservationStatus::Arrived]);
    $this->postJson(frontOfHouseUrl($data, 'reservations/'.$other->id.'/pending'))->assertNotFound();
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Arrived)
        ->and($other->fresh()->status)->toBe(ReservationStatus::Arrived);
});

it('lists merchant booking availability in quarter hours and excludes occupied or undersized tables', function () {
    Carbon::setTestNow('2026-07-14 08:00:00');
    $data = createBookableRestaurant();
    $data['restaurant']->update(['timezone' => 'Africa/Lagos', 'is_profile_published' => false]);
    $data['restaurant']->hours()->update(['opens_at' => '12:00:00', 'closes_at' => '16:00:00', 'is_closed' => false]);
    $data['restaurant']->policy()->update(['reservation_duration_minutes' => 60]);
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $url = frontOfHouseUrl($data, 'front-of-house/availability');
    $slots = $this->getJson($url.'?date=2026-07-14&party_size=2')
        ->assertOk()->assertJsonPath('timezone', 'Africa/Lagos')->json('slots');
    expect($slots)->toHaveCount(13);
    expect($slots[0]['local_starts_at'])->toBe('2026-07-14T12:00:00+01:00');
    expect($slots[1]['local_starts_at'])->toBe('2026-07-14T12:15:00+01:00');
    expect($slots[12]['local_starts_at'])->toBe('2026-07-14T15:00:00+01:00');

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => '2026-07-14 11:00:00',
        'ends_at' => '2026-07-14 15:00:00',
        'status' => ReservationStatus::Confirmed,
    ]);
    $this->getJson($url.'?date=2026-07-14&party_size=2')->assertOk()->assertJsonPath('slots', []);
    $this->getJson($url.'?date=2026-07-14&party_size=5')->assertOk()->assertJsonPath('slots', []);
    $this->getJson($url.'?date=invalid&party_size=0')->assertUnprocessable()->assertJsonValidationErrors(['date', 'party_size']);

    $other = createBookableRestaurant();
    activateMerchantBilling($other['restaurant']);
    $this->getJson(frontOfHouseUrl($other, 'front-of-house/availability').'?date=2026-07-14&party_size=2')->assertForbidden();
});

it('links walk-in reservations to matching email accounts and reuses selected restaurant guests', function () {
    Carbon::setTestNow('2026-07-14 08:00:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $customer = User::factory()->create(['email' => 'diner@example.com']);
    $payload = [
        'starts_at' => '2026-07-14T12:00:00Z', 'party_size' => 2, 'source' => 'walk_in',
        'guest_contact' => ['first_name' => 'Diner', 'email' => 'DINER@example.com', 'phone' => '+2348012345678'],
    ];
    $id = $this->postJson(frontOfHouseUrl($data, 'reservations'), $payload)->assertCreated()->json('reservation.id');
    $reservation = Reservation::findOrFail($id);
    expect($reservation->user_id)->toBe($customer->id);
    expect($reservation->source->value)->toBe('walk_in');
    expect($reservation->guestContact->email)->toBe('diner@example.com');

    unset($payload['guest_contact']);
    $payload['guest_contact_id'] = $reservation->guest_contact_id;
    $payload['starts_at'] = '2026-07-14T16:00:00Z';
    $secondId = $this->postJson(frontOfHouseUrl($data, 'reservations'), $payload)->assertCreated()->json('reservation.id');
    expect(Reservation::findOrFail($secondId)->guest_contact_id)->toBe($reservation->guest_contact_id);
    expect($data['restaurant']->guestContacts()->count())->toBe(1);
    $foreignGuest = GuestContact::factory()->create();
    $payload['guest_contact_id'] = $foreignGuest->id;
    $this->postJson(frontOfHouseUrl($data, 'reservations'), $payload)->assertUnprocessable()->assertJsonValidationErrors(['guest_contact_id']);
});

it('keeps email optional for walk-in bookings and does not link by unverified email or phone alone', function () {
    Carbon::setTestNow('2026-07-14 08:00:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    User::factory()->create(['phone' => '+2348012345678']);
    User::factory()->unverified()->create(['email' => 'unverified@example.com']);
    foreach ([null, 'unverified@example.com'] as $index => $email) {
        $id = $this->postJson(frontOfHouseUrl($data, 'reservations'), [
            'starts_at' => $index === 0 ? '2026-07-14T12:00:00Z' : '2026-07-14T16:00:00Z',
            'party_size' => 2, 'source' => 'walk_in',
            'guest_contact' => ['first_name' => 'Guest', 'phone' => '+2348012345678', 'email' => $email],
        ])->assertCreated()->json('reservation.id');
        expect(Reservation::findOrFail($id)->user_id)->toBeNull();
        expect(Reservation::findOrFail($id)->source->value)->toBe('walk_in');
    }
});

it('keeps shared-phone guests separate and claims only the email recorded on each booking', function () {
    Carbon::setTestNow('2026-07-14 08:00:00');
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $alice = User::factory()->create(['email' => 'alice@example.com']);
    $aliceContact = GuestContact::factory()->create(['restaurant_id' => $data['restaurant']->id, 'first_name' => 'Alice', 'email' => $alice->email, 'phone' => '+2348012345678', 'is_temporary' => false]);
    $id = $this->postJson(frontOfHouseUrl($data, 'reservations'), [
        'starts_at' => '2026-07-14T12:00:00Z', 'party_size' => 2, 'source' => 'walk_in',
        'guest_contact' => ['first_name' => 'Bob', 'phone' => $aliceContact->phone],
    ])->assertCreated()->json('reservation.id');
    $booking = Reservation::findOrFail($id);
    expect($booking->user_id)->toBeNull();
    expect($booking->booking_email)->toBeNull();
    expect($booking->guest_contact_id)->not->toBe($aliceContact->id);
    expect($aliceContact->fresh()->first_name)->toBe('Alice');
    $booking->guestContact->update(['email' => $alice->email]);
    $alice->claimGuestReservations();
    expect($booking->fresh()->user_id)->toBeNull();

    $id = $this->postJson(frontOfHouseUrl($data, 'reservations'), [
        'starts_at' => '2026-07-14T16:00:00Z', 'party_size' => 2, 'source' => 'walk_in',
        'guest_contact' => ['first_name' => 'Future', 'phone' => $aliceContact->phone, 'email' => 'future@example.com'],
    ])->assertCreated()->json('reservation.id');
    $futureBooking = Reservation::findOrFail($id);
    expect($futureBooking->booking_email)->toBe('future@example.com');
    $futureBooking->guestContact->update(['email' => $alice->email]);
    $alice->claimGuestReservations();
    expect($futureBooking->fresh()->user_id)->toBeNull();
    $future = User::factory()->create(['email' => 'future@example.com']);
    $future->claimGuestReservations();
    expect($futureBooking->fresh()->user_id)->toBe($future->id);
    expect($futureBooking->fresh()->source->value)->toBe('walk_in');
});

it('filters booking times by dining area and rejects another restaurants dining area', function () {
    Carbon::setTestNow('2026-07-14 08:00:00');
    $data = createBookableRestaurant();
    $data['restaurant']->update(['timezone' => 'UTC']);
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $main = DiningArea::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    $terrace = DiningArea::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    $data['table']->update(['dining_area_id' => $main->id]);
    RestaurantTable::factory()->create(['restaurant_id' => $data['restaurant']->id, 'dining_area_id' => $terrace->id, 'min_capacity' => 1, 'max_capacity' => 4]);
    Reservation::factory()->create(['restaurant_id' => $data['restaurant']->id, 'restaurant_table_id' => $data['table']->id, 'starts_at' => '2026-07-14T09:00:00Z', 'ends_at' => '2026-07-14T22:00:00Z', 'status' => ReservationStatus::Confirmed]);
    $url = frontOfHouseUrl($data, 'front-of-house/availability').'?date=2026-07-14&party_size=2&dining_area_id=';
    $this->getJson($url.$main->id)->assertOk()->assertJsonPath('slots', []);
    expect($this->getJson($url.$terrace->id)->assertOk()->json('slots'))->not->toBeEmpty();
    $this->getJson($url.DiningArea::factory()->create()->id)->assertUnprocessable()->assertJsonValidationErrors(['dining_area_id']);
});

it('finds restaurant guests by local or international phone formats and email', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $guest = GuestContact::factory()->create(['restaurant_id' => $data['restaurant']->id, 'phone' => '+234 801 234 5678', 'email' => 'find@example.com', 'is_temporary' => false]);
    foreach (['08012345678', '+2348012345678', '+234 (801) 234-5678', 'find@example.com'] as $term) {
        $this->getJson(frontOfHouseUrl($data, 'guests').'?'.http_build_query(['search_term' => $term, 'contact_only' => true]))
            ->assertOk()->assertJsonPath('data.0.id', $guest->id);
    }
});

it('adds selected or new waitlist guests without changing the requested time', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);
    $guest = GuestContact::factory()->create(['restaurant_id' => $data['restaurant']->id, 'phone' => null, 'email' => null, 'is_temporary' => false]);
    $url = frontOfHouseUrl($data, 'waitlist-entries');
    $payload = ['preferred_starts_at' => '2099-01-01T12:15:00Z', 'party_size' => 2, 'guest_contact_id' => $guest->id];
    $id = $this->postJson($url, $payload)->assertCreated()->json('waitlist_entry.id');
    $entry = WaitlistEntry::findOrFail($id);
    expect($entry->guest_contact_id)->toBe($guest->id);
    expect($entry->preferred_starts_at->toIso8601String())->toBe('2099-01-01T12:15:00+00:00');
    expect($data['restaurant']->guestContacts()->count())->toBe(1);
    $payload['guest_contact_id'] = GuestContact::factory()->create(['is_temporary' => false])->id;
    $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors(['guest_contact_id']);
    unset($payload['guest_contact_id']);
    $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors(['guest_contact.first_name']);
    $payload['guest_contact'] = ['first_name' => 'New Guest', 'phone' => '08012345678'];
    $id = $this->postJson($url, $payload)->assertCreated()->json('waitlist_entry.id');
    $entry = WaitlistEntry::findOrFail($id);
    expect($entry->guestContact->first_name)->toBe('New Guest');
    expect($entry->guestContact->email)->toBeNull();
    expect($entry->preferred_starts_at->toIso8601String())->toBe('2099-01-01T12:15:00+00:00');
});

it('checks table availability without querying shifts once per table', function (): void {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    actingAsFrontOfHouse($data);

    // Names are pinned, not faked: table labels are unique per restaurant, and the
    // factory's random 'T1'-'T99' can collide with itself or the seeded table.
    foreach (range(1, 24) as $n) {
        \App\Models\RestaurantTable::factory()->create([
            'restaurant_id' => $data['restaurant']->id,
            'dining_area_id' => $data['table']->dining_area_id,
            'name' => 'QueryProbe-'.$n,
            'min_capacity' => 1,
            'max_capacity' => 8,
            'is_active' => true,
        ]);
    }

    $url = frontOfHouseUrl($data, 'front-of-house/available-tables?starts_at='
        .urlencode(now()->addDay()->setTime(18, 0)->toIso8601String()).'&party_size=2');

    \Illuminate\Support\Facades\DB::flushQueryLog();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $this->getJson($url)->assertOk();
    $log = collect(\Illuminate\Support\Facades\DB::getQueryLog());
    \Illuminate\Support\Facades\DB::disableQueryLog();

    // Shifts are loaded once up front, so evaluating 25 candidate tables must not go
    // back to the database for them. Was 78 shift queries for this same request.
    $shiftQueries = $log->filter(
        fn (array $query): bool => str_contains($query['query'], 'restaurant_shifts')
    )->count();

    expect($shiftQueries)->toBeLessThanOrEqual(6);
});
