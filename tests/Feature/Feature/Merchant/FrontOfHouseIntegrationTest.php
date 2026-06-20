<?php

use App\Events\ReservationUpdated;
use App\Events\RestaurantShiftNoteUpdated;
use App\Models\DiningArea;
use App\Models\Reservation;
use App\Models\RestaurantShiftNote;
use App\Models\Role;
use App\Models\User;
use App\Models\WaitlistEntry;
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

it('returns the configured dining-area layout contract through the front-of-house floor endpoint', function () {
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
        ->assertJsonPath('tables.0.chair_color', '#112233');
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
        ->assertJsonPath('data.0.source', 'restaurant_hours');

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
