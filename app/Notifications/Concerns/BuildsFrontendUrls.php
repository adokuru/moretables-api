<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

trait BuildsFrontendUrls
{
    protected function frontendBaseUrl(): string
    {
        return rtrim(config('app.frontend_urls.main') ?: config('app.url'), '/');
    }

    protected function restaurantUrl(?string $slug): string
    {
        return $slug !== null && $slug !== ''
            ? $this->frontendBaseUrl().'/restaurants/'.$slug
            : $this->frontendBaseUrl();
    }

    protected function rewardsUrl(): string
    {
        return $this->frontendBaseUrl().'/rewards';
    }

    protected function unsubscribeUrl(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        return URL::signedRoute('email.unsubscribe', ['email' => $email]);
    }

    protected function withUnsubscribeHeaders(MailMessage $message, ?string $email): MailMessage
    {
        $unsubscribeUrl = $this->unsubscribeUrl($email);

        if ($unsubscribeUrl === null) {
            return $message;
        }

        return $message->withSymfonyMessage(function ($symfonyMessage) use ($unsubscribeUrl): void {
            $headers = $symfonyMessage->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }
}
