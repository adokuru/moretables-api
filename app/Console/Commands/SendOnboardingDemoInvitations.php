<?php

namespace App\Console\Commands;

use App\Models\OnboardingRequest;
use App\Notifications\OnboardingDemoInvitationNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

#[Signature('onboarding-requests:send-demo-invites {--dry-run : List the recipients without sending anything} {--force : Skip the confirmation prompt}')]
#[Description('Email the demo booking invitation to every past onboarding requester who has not been sent one yet.')]
class SendOnboardingDemoInvitations extends Command
{
    public function handle(): int
    {
        $recipients = $this->pendingRecipients();

        if ($recipients->isEmpty()) {
            $this->info('No onboarding requesters are awaiting a demo invitation.');

            return self::SUCCESS;
        }

        $bookingUrl = OnboardingDemoInvitationNotification::bookingUrl();
        $this->line("Booking button links to: {$bookingUrl}");

        if ($this->isUnreachableUrl($bookingUrl)) {
            $this->error("Refusing to send: {$bookingUrl} is not reachable by a recipient. Set DEMO_BOOKING_URL (or FRONTEND_URL) for this environment first.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            foreach ($recipients as $requests) {
                $this->line("  {$requests->first()->email} ({$requests->count()} request(s))");
            }

            $this->info("Dry run: {$recipients->count()} requester(s) would be emailed.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Send the demo invitation to {$recipients->count()} requester(s)?")) {
            $this->warn('Aborted. Nothing was sent.');

            return self::SUCCESS;
        }

        foreach ($recipients as $requests) {
            $request = $requests->first();

            Notification::route('mail', $request->email)
                ->notify(new OnboardingDemoInvitationNotification($request));

            OnboardingRequest::query()
                ->whereIn('id', $requests->pluck('id'))
                ->update(['demo_invitation_sent_at' => now()]);
        }

        $this->info("Queued {$recipients->count()} demo invitation(s).");

        return self::SUCCESS;
    }

    /**
     * A localhost or unset host means the environment is misconfigured, and a blast
     * of dead links cannot be taken back.
     */
    protected function isUnreachableUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host === null || $host === false || in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Pending requests grouped by lower-cased email so a requester who submitted
     * several times is emailed once and every one of their rows is stamped.
     *
     * ponytail: loads all pending rows; leads are a small table. Chunk it if that changes.
     *
     * @return Collection<string, Collection<int, OnboardingRequest>>
     */
    protected function pendingRecipients(): Collection
    {
        return OnboardingRequest::query()
            ->whereNull('demo_invitation_sent_at')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (OnboardingRequest $request): string => Str::lower($request->email));
    }
}
