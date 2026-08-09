<?php

namespace App\Services;

use App\Events\WaitlistEntryUpdated;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\AvailabilityAlertNotification;
use App\WaitlistStatus;
use App\WaitlistType;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AvailabilityAlertService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Restaurant $restaurant, User $customer, array $attributes): WaitlistEntry
    {
        $windowStart = Carbon::parse($attributes['window_start']);
        $windowEnd = Carbon::parse($attributes['window_end']);

        $alert = WaitlistEntry::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $customer->id,
            'status' => WaitlistStatus::Waiting,
            'type' => WaitlistType::AvailabilityAlert,
            'party_size' => (int) $attributes['party_size'],
            'preferred_starts_at' => $windowStart,
            'preferred_ends_at' => $windowEnd,
            'whatsapp_updates' => (bool) ($attributes['whatsapp_updates'] ?? false),
            'expires_at' => $windowEnd,
        ]);

        $this->auditLogService->log(
            action: 'availability_alert.created',
            actor: $customer,
            auditable: $alert,
            restaurant: $restaurant,
            organization: $restaurant->organization,
            description: 'Table availability alert created',
        );

        $alert->load('restaurant');
        event(new WaitlistEntryUpdated($alert, 'created'));

        return $alert;
    }

    public function cancel(WaitlistEntry $alert, User $actor): WaitlistEntry
    {
        $alert->forceFill(['status' => WaitlistStatus::Cancelled])->save();

        $this->auditLogService->log(
            action: 'availability_alert.cancelled',
            actor: $actor,
            auditable: $alert,
            restaurant: $alert->restaurant,
            organization: $alert->restaurant?->organization,
            description: 'Table availability alert cancelled',
        );

        return $alert;
    }

    public function notifyNow(WaitlistEntry $alert, User $actor): WaitlistEntry
    {
        if (! $alert->isAvailabilityAlert() || $alert->status !== WaitlistStatus::Waiting) {
            throw ValidationException::withMessages([
                'availability_alert' => ['Only waiting availability alerts can be notified.'],
            ]);
        }

        $alert->loadMissing(['restaurant', 'user']);
        $this->notify($alert);
        $alert->refresh()->load(['restaurant', 'user', 'guestContact']);

        $this->auditLogService->log(
            action: 'availability_alert.notified',
            actor: $actor,
            auditable: $alert,
            restaurant: $alert->restaurant,
            organization: $alert->restaurant?->organization,
            description: 'Table availability alert manually notified',
        );

        return $alert;
    }

    /**
     * Evaluate active availability alerts for a restaurant and notify any whose
     * window now has a bookable table. Returns the number of alerts notified.
     */
    public function processForRestaurant(Restaurant $restaurant, ?CarbonInterface $onDate = null): int
    {
        $cooldownMinutes = max(0, (int) config('reservations.availability_alerts.renotify_cooldown_minutes', 30));
        $maxNotifications = max(1, (int) config('reservations.availability_alerts.max_notifications', 2));
        $notifiedCount = 0;

        $this->activeAlertsQuery($restaurant, $onDate)
            ->with(['restaurant', 'user'])
            ->chunkById(100, function (Collection $alerts) use ($cooldownMinutes, $maxNotifications, &$notifiedCount): void {
                foreach ($alerts as $alert) {
                    if ($alert->notification_count >= $maxNotifications) {
                        continue;
                    }

                    if ($this->isWithinCooldown($alert, $cooldownMinutes)) {
                        continue;
                    }

                    if (! $this->hasMatchingSlot($alert)) {
                        continue;
                    }

                    $this->notify($alert);
                    $notifiedCount++;
                }
            });

        return $notifiedCount;
    }

    /**
     * Expire availability alerts whose desired window has elapsed.
     */
    public function expireElapsed(): int
    {
        return WaitlistEntry::query()
            ->availabilityAlerts()
            ->whereIn('status', [WaitlistStatus::Waiting->value, WaitlistStatus::Notified->value])
            ->where('preferred_ends_at', '<', now())
            ->update(['status' => WaitlistStatus::Expired->value]);
    }

    /**
     * @return Builder<WaitlistEntry>
     */
    protected function activeAlertsQuery(Restaurant $restaurant, ?CarbonInterface $onDate): Builder
    {
        $query = WaitlistEntry::query()
            ->availabilityAlerts()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', [WaitlistStatus::Waiting->value, WaitlistStatus::Notified->value])
            ->where('preferred_ends_at', '>', now());

        if ($onDate !== null) {
            $query->whereBetween('preferred_starts_at', [
                $onDate->copy()->startOfDay(),
                $onDate->copy()->endOfDay(),
            ]);
        }

        return $query;
    }

    protected function isWithinCooldown(WaitlistEntry $alert, int $cooldownMinutes): bool
    {
        if ($alert->notified_at === null || $cooldownMinutes === 0) {
            return false;
        }

        return $alert->notified_at->greaterThan(now()->subMinutes($cooldownMinutes));
    }

    protected function hasMatchingSlot(WaitlistEntry $alert): bool
    {
        $restaurant = $alert->restaurant;

        if ($restaurant === null) {
            return false;
        }

        $timezone = $restaurant->timezone ?: config('app.timezone');
        $date = $alert->preferred_starts_at->copy()->setTimezone($timezone)->toDateString();

        $slots = $this->availabilityService->listAvailableSlots(
            $restaurant,
            $date,
            (int) $alert->party_size,
        );

        foreach ($slots as $slot) {
            $startsAt = Carbon::parse($slot['starts_at']);

            if ($startsAt->betweenIncluded($alert->preferred_starts_at, $alert->preferred_ends_at)) {
                return true;
            }
        }

        return false;
    }

    protected function notify(WaitlistEntry $alert): void
    {
        DB::transaction(function () use ($alert): void {
            $alert->forceFill([
                'status' => WaitlistStatus::Notified,
                'notified_at' => now(),
                'notification_count' => $alert->notification_count + 1,
            ])->save();

            $alert->user?->notify(new AvailabilityAlertNotification($alert));
        });

        // Shared by both the manual "Notify" button (notifyNow()) and the
        // automatic table-opened-up path (processForRestaurant()) — putting
        // the broadcast here means both get it for free, instead of only the
        // manual path (which is how this worked before).
        event(new WaitlistEntryUpdated($alert, 'notified'));
    }
}
