<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Notifications\ReservationLifecycleNotification;
use App\ReservationStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:send-upcoming-reservation-reminders')]
#[Description('Send upcoming reservation reminder emails for configured cadences')]
class SendUpcomingReservationReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cadenceDays = collect(config('reservations.upcoming_reminder_days_before', []))
            ->map(fn (mixed $days): int => (int) $days)
            ->filter(fn (int $days): bool => $days > 0)
            ->unique()
            ->sortDesc()
            ->values();

        if ($cadenceDays->isEmpty()) {
            $this->info('No upcoming reservation reminder cadences configured.');

            return self::SUCCESS;
        }

        $windowMinutes = max(1, (int) config('reservations.upcoming_reminder_window_minutes', 60));
        $sentReminderCount = 0;

        foreach ($cadenceDays as $daysBefore) {
            $sentReminderCount += $this->sendRemindersForCadence($daysBefore, $windowMinutes);
        }

        $this->info("Sent {$sentReminderCount} upcoming reservation reminder notification(s).");

        return self::SUCCESS;
    }

    protected function sendRemindersForCadence(int $daysBefore, int $windowMinutes): int
    {
        $windowStart = now()->addDays($daysBefore);
        $windowEnd = $windowStart->copy()->addMinutes($windowMinutes);

        $reservations = Reservation::query()
            ->whereIn('status', [ReservationStatus::Booked->value, ReservationStatus::Confirmed->value])
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->with(['restaurant.media', 'user', 'guestContact', 'reservationGuests'])
            ->get();

        $sentReminderCount = 0;
        $hoursUntilReservation = $daysBefore * 24;

        foreach ($reservations as $reservation) {
            if ($reservation->hasUpcomingReminderSent($daysBefore)) {
                continue;
            }

            $participants = $reservation->notifiableParticipants();

            if ($participants->isEmpty()) {
                continue;
            }

            $notification = new ReservationLifecycleNotification(
                $reservation,
                'upcoming_reminder',
                $hoursUntilReservation,
            );

            foreach ($participants as $participant) {
                $participant->notify($notification);
                $sentReminderCount++;
            }

            $reservation->markUpcomingReminderSent($daysBefore, now());
        }

        return $sentReminderCount;
    }
}
