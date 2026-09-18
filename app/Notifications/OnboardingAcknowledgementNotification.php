<?php

namespace App\Notifications;

use App\Models\OnboardingRequest;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Notifications\Concerns\UsesNotificationQueues;
use App\Notifications\Contracts\Unsubscribable;
use App\OnboardingContactReason;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reply sent to a lead whose contact reason is not "book a demo" — the demo
 * pitch in OnboardingDemoInvitationNotification would be the wrong answer to a
 * pricing, support or partnership enquiry.
 */
class OnboardingAcknowledgementNotification extends Notification implements ShouldQueue, Unsubscribable
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
        $copy = $this->copy($restaurantName);

        $message = (new MailMessage)
            ->subject($copy['subject'])
            ->view('emails.moretables-tabular-layout', [
                'subject' => $copy['subject'],
                'recipientName' => $firstName,
                'greeting' => "Hi {$firstName},",
                'bodyPrimary' => $copy['bodyPrimary'],
                'bodySecondary' => $copy['bodySecondary'],
                'showCta' => false,
                'signOff' => 'Thanks,',
                'signature' => 'The MoreTables Team',
                'footerLine1' => 'MoreTables',
                'footerLine2' => 'Lagos, Nigeria.',
                'footerLink1Url' => $this->frontendBaseUrl(),
                'footerLink1Label' => 'Visit MoreTables',
                'footerLink2Url' => $this->unsubscribeUrl($this->onboardingRequest->email) ?? $this->frontendBaseUrl(),
                'footerLink2Label' => 'Unsubscribe',
            ]);

        return $this->withUnsubscribeHeaders($message, $this->onboardingRequest->email);
    }

    /**
     * @return array{subject: string, bodyPrimary: string, bodySecondary: string}
     */
    protected function copy(string $restaurantName): array
    {
        return match ($this->onboardingRequest->contact_reason) {
            OnboardingContactReason::Pricing => [
                'subject' => 'Thanks for your MoreTables pricing enquiry',
                'bodyPrimary' => "Thanks for reaching out to MoreTables!\n\n"
                    .'We’ve received your pricing enquiry. A member of our Sales Team will be in touch shortly to share more '
                    ."information about our plans, pricing, and the solutions available for {$restaurantName}.",
                'bodySecondary' => 'We look forward to helping you find the right fit for your restaurant.',
            ],
            OnboardingContactReason::Support,
            OnboardingContactReason::RestaurantOnboarding,
            OnboardingContactReason::GeneralInquiry => [
                'subject' => 'We’ve received your MoreTables support request',
                'bodyPrimary' => "Thanks for reaching out to MoreTables!\n\n"
                    .'We’ve received your support request, and a member of our Support Team will be in touch shortly to assist you.',
                'bodySecondary' => 'We’re here to make sure you get the help you need.',
            ],
            OnboardingContactReason::Partnership => [
                'subject' => 'Thanks for your interest in partnering with MoreTables',
                'bodyPrimary' => "Thanks for your interest in partnering with MoreTables!\n\n"
                    .'We’ve received your partnership enquiry. A member of our Sales Team will be in touch shortly to learn '
                    .'more about your proposal and explore how we can work together.',
                'bodySecondary' => 'We look forward to connecting and exploring what’s possible.',
            ],
            default => [
                'subject' => 'Thanks for reaching out to MoreTables',
                'bodyPrimary' => "Thanks for reaching out to MoreTables!\n\n"
                    .'We’ve received your enquiry and will make sure it gets to the right team. A member of our team will be '
                    .'in touch shortly to learn more about how we can help.',
                'bodySecondary' => 'We look forward to connecting.',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_acknowledgement',
            'onboarding_request_id' => $this->onboardingRequest->id,
            'contact_reason' => $this->onboardingRequest->contact_reason?->value,
            'email' => $this->onboardingRequest->email,
        ];
    }
}
