<?php

namespace App\Console\Commands;

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\WaitlistEntry;
use App\ReservationServiceStage;
use App\ReservationSource;
use App\ReservationStatus;
use App\TableStatus;
use App\WaitlistStatus;
use App\WaitlistType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps the store-review demo restaurant's board populated with bookings dated
 * today. Reservations are anchored to a real date, so a board seeded once at
 * deploy is empty by the time a reviewer opens the app — this runs daily.
 *
 * Only touches rows it created itself (metadata.demo_seed), so a reviewer's own
 * bookings survive the refresh.
 */
class RefreshDemoReservations extends Command
{
    protected $signature = 'app:refresh-demo-reservations';

    protected $description = 'Re-seed today\'s bookings on the App Store / Play Store demo restaurant.';

    /** Every board column, so no section of either app reads as broken/empty. */
    private const BOOKINGS = [
        // [bucket, guest, party, hours from now, status, service stage]
        ['waitlist', 'Amara Nwosu', 2, 0.25, WaitlistStatus::Waiting, null],
        ['waitlist', 'Tunde Bakare', 4, 0.5, WaitlistStatus::Notified, null],
        ['waitlist', 'Zainab Yusuf', 3, 0.75, WaitlistStatus::Arrived, null],

        ['reservation', 'Chidi Okonkwo', 2, 2, ReservationStatus::Booked, null],
        ['reservation', 'Fatima Bello', 6, 3, ReservationStatus::Confirmed, null],
        ['reservation', 'Emeka Obi', 4, -0.25, ReservationStatus::RunningLate, null],
        ['reservation', 'Ngozi Eze', 2, -0.15, ReservationStatus::Arrived, null],

        ['seated', 'Ibrahim Musa', 4, -1.5, ReservationStatus::Seated, ReservationServiceStage::Entree],
        ['seated', 'Adaeze Nnamdi', 2, -0.75, ReservationStatus::Seated, ReservationServiceStage::Appetizer],
        ['seated', 'Kelechi Umeh', 5, -2, ReservationStatus::Seated, ReservationServiceStage::Paid],

        ['finished', 'Yemi Adeyemi', 3, -3.5, ReservationStatus::Completed, ReservationServiceStage::BussingNeeded],
        ['finished', 'Hauwa Sani', 2, -4, ReservationStatus::Completed, ReservationServiceStage::Finished],

        ['removed', 'Segun Fashola', 4, 1.5, ReservationStatus::Cancelled, null],
        ['removed', 'Bisi Alabi', 2, 2.5, ReservationStatus::NoShow, null],
    ];

    public function handle(): int
    {
        $restaurant = Restaurant::query()
            ->where('slug', ProvisionDemoAccounts::RESTAURANT_SLUG)
            ->first();

        if (! $restaurant) {
            $this->warn('No demo restaurant — run php artisan app:provision-demo first. Nothing to do.');

            return self::SUCCESS;
        }

        $tables = $restaurant->tables()->orderBy('id')->get();

        if ($tables->isEmpty()) {
            $this->warn('Demo restaurant has no tables — run php artisan app:provision-demo first.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($restaurant, $tables): void {
            $this->clearPreviousSeed($restaurant);
            $this->seed($restaurant, $tables);
        });

        $this->info('Demo board refreshed: '.count(self::BOOKINGS).' bookings dated today.');

        return self::SUCCESS;
    }

    /**
     * Scoped to this restaurant *and* to rows this command created, so anything
     * a reviewer booked by hand is left alone.
     */
    protected function clearPreviousSeed(Restaurant $restaurant): void
    {
        foreach ([Reservation::class, WaitlistEntry::class] as $model) {
            $model::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereJsonContains('metadata->demo_seed', true)
                ->delete();
        }
    }

    protected function seed(Restaurant $restaurant, Collection $tables): void
    {
        // "Today" as the restaurant reads it, not as the server does.
        $now = Carbon::now($restaurant->timezone ?: config('app.timezone'));

        foreach (self::BOOKINGS as $index => [$bucket, $name, $partySize, $hoursFromNow, $status, $stage]) {
            $guest = $this->guestContact($restaurant, $name);
            $startsAt = $now->copy()->addMinutes((int) round($hoursFromNow * 60))->utc();

            if ($bucket === 'waitlist') {
                WaitlistEntry::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'guest_contact_id' => $guest->id,
                    'type' => WaitlistType::Seating->value,
                    'status' => $status->value,
                    'party_size' => $partySize,
                    'preferred_starts_at' => $startsAt,
                    'preferred_ends_at' => $startsAt->copy()->addMinutes(90),
                    'notified_at' => $status === WaitlistStatus::Notified ? $now->copy()->utc() : null,
                    'arrived_at' => $status === WaitlistStatus::Arrived ? $now->copy()->utc() : null,
                    'metadata' => ['demo_seed' => true],
                ]);

                continue;
            }

            // Seated and finished parties must hold a table or the floor plan
            // stays blank; upcoming and removed ones deliberately do not.
            $table = in_array($bucket, ['seated', 'finished'], true)
                ? $tables[$index % $tables->count()]
                : null;

            Reservation::query()->create([
                'restaurant_id' => $restaurant->id,
                'guest_contact_id' => $guest->id,
                'restaurant_table_id' => $table?->id,
                'reservation_reference' => 'MT-DEMO'.Str::upper(Str::random(4)),
                'source' => $index % 2 === 0 ? ReservationSource::Customer->value : ReservationSource::WalkIn->value,
                'status' => $status->value,
                'service_stage' => $stage?->value,
                'party_size' => $partySize,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(90),
                'arrived_at' => $this->arrivedAt($status, $startsAt),
                'seated_at' => in_array($bucket, ['seated', 'finished'], true) ? $startsAt : null,
                'completed_at' => $bucket === 'finished' ? $startsAt->copy()->addMinutes(80) : null,
                'canceled_at' => $status === ReservationStatus::Cancelled ? $now->copy()->utc() : null,
                'metadata' => ['demo_seed' => true],
            ]);
        }

        $this->resetTableStatuses($restaurant);
    }

    protected function arrivedAt(ReservationStatus $status, Carbon $startsAt): ?Carbon
    {
        return in_array($status, [
            ReservationStatus::Arrived,
            ReservationStatus::Seated,
            ReservationStatus::Completed,
        ], true) ? $startsAt : null;
    }

    /**
     * A previous run's completed parties leave their tables in Cleaning, which
     * would show as dirty tables with no booking behind them. Occupied/reserved
     * are derived live from reservations, so Available is the correct reset.
     */
    protected function resetTableStatuses(Restaurant $restaurant): void
    {
        RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->update(['status' => TableStatus::Available->value]);
    }

    protected function guestContact(Restaurant $restaurant, string $name): GuestContact
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        return GuestContact::query()->firstOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'email' => Str::slug($name).'@demo.moretables.com',
            ],
            [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => '+23480'.str_pad((string) (crc32($name) % 100000000), 8, '0', STR_PAD_LEFT),
                'is_temporary' => false,
            ],
        );
    }
}
