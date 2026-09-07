<?php

namespace App\Notifications;

use App\Models\OnboardingRequest;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Notifications\Concerns\UsesNotificationQueues;
use App\Notifications\Contracts\Unsubscribable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingDemoInvitationNotification extends Notification implements ShouldQueue, Unsubscribable
{
    use BuildsFrontendUrls, Queueable, UsesNotificationQueues;

    public function __construct(
        protected OnboardingRequest $onboardingRequest,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $this->onboardingRequest->first_name
            ?: (explode(' ', (string) $this->onboardingRequest->owner_name)[0] ?: 'there');
        $restaurantName = $this->onboardingRequest->restaurant_name ?: 'your restaurant';
        $subject = 'Book your MoreTables demo';
        $bookingUrl = static::bookingUrl();

        $message = (new MailMessage)
            ->subject($subject)
            ->view('emails.moretables-tabular-layout', [
                'subject' => $subject,
                'recipientName' => $firstName,
                'greeting' => "Hi {$firstName},",
                'bodyPrimary' => "Thanks for requesting a MoreTables demo.\n\n"
                    ."We’d love to show you how MoreTables can help {$restaurantName} simplify reservations, optimize your tables, reduce no-shows, and turn more diners into regulars.",
                'bodySecondary' => "In a 30-45 minute demo, we’ll cover:\n\n"
                    ."The Diner Experience — see how your guests can easily discover your restaurant, check availability, book a table, and manage their reservation from start to finish.\n"
                    ."Reservations — real-time availability, booking management & waitlists\n"
                    ."Guest Management — guest profiles, preferences & dining history\n"
                    ."No-Show Protection — reminders, reservation holds & smarter cancellation management\n"
                    ."Guest Loyalty — rewards and tools to encourage repeat visits\n"
                    ."Analytics & Reporting — actionable insights into bookings, covers, revenue, guest behavior & restaurant performance\n\n"
                    .'Pick a time that works for you, and we’ll take care of the rest.',
                'ctaUrl' => $bookingUrl,
                'ctaLabel' => 'Book a demo',
                'ctaButton' => true,
                'showCta' => true,
                'signOff' => 'See you soon',
                'signature' => 'The MoreTables Team',
                'footerLine1' => 'MoreTables',
                'footerLine2' => 'Lagos, Nigeria.',
                'footerLink1Url' => $bookingUrl,
                'footerLink1Label' => 'Book a demo',
                'footerLink2Url' => $this->unsubscribeUrl($this->onboardingRequest->email) ?? $this->frontendBaseUrl(),
                'footerLink2Label' => 'Unsubscribe',
            ]);

        return $this->withUnsubscribeHeaders($message, $this->onboardingRequest->email);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_demo_invitation',
            'onboarding_request_id' => $this->onboardingRequest->id,
            'email' => $this->onboardingRequest->email,
        ];
    }

    /**
     * Calendar link behind the "Book a demo" button. Static so bulk senders can
     * show and validate the link before mailing anyone.
     */
    public static function bookingUrl(): string
    {
        return config('services.demo_booking.url')
            ?: rtrim((string) (config('app.frontend_urls.main') ?: config('app.url')), '/').'/book-a-demo';
    }
}
