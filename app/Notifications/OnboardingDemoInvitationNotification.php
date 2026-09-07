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
                'bodyPrimary' => "Thanks for reaching out about {$restaurantName} — we'd love to show you around. Pick a time that suits your service hours and we'll walk you through MoreTables in 20 minutes. No slides, just your restaurant.",
                'bodySecondary' => "What we'll cover:\n"
                    ."• Taking reservations from Google, Instagram and your own site\n"
                    ."• Cutting no-shows with automatic reminders and deposits\n"
                    ."• Turning tables faster with a live floor view your team will actually use\n\n"
                    .'Prefer to talk it through by email instead? Just reply to this message.',
                'ctaUrl' => $bookingUrl,
                'ctaLabel' => 'Book a demo',
                'ctaButton' => true,
                'showCta' => true,
                'signOff' => 'Talk soon,',
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
