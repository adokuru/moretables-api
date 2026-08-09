<?php

use App\Events\ReservationUpdated;
use App\Events\RestaurantShiftNoteUpdated;
use App\Models\DiningArea;
use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\RestaurantShiftNote;
use App\Models\RestaurantTable;
use App\Models\Role;
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

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Arrived);

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
