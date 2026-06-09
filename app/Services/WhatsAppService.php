<?php

namespace App\Services;

use App\Notifications\WhatsAppMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function isConfigured(): bool
    {
        return is_string(config('services.whatsapp.token'))
            && config('services.whatsapp.token') !== ''
            && is_string(config('services.whatsapp.phone_number_id'))
            && config('services.whatsapp.phone_number_id') !== '';
    }

    public function send(string $recipient, WhatsAppMessage $message): void
    {
        $recipient = $this->normalizeRecipient($recipient);

        if ($recipient === '' || ! $this->isConfigured()) {
            return;
        }

        $response = Http::withToken((string) config('services.whatsapp.token'))
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post($this->endpoint(), $message->toPayload($recipient));

        if ($response->failed()) {
            Log::warning('WhatsApp notification request failed.', [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);
        }
    }

    protected function endpoint(): string
    {
        return sprintf(
            '%s/%s/%s/messages',
            rtrim((string) config('services.whatsapp.base_url'), '/'),
            (string) config('services.whatsapp.api_version'),
            (string) config('services.whatsapp.phone_number_id'),
        );
    }

    protected function normalizeRecipient(string $recipient): string
    {
        return preg_replace('/[^0-9]/', '', $recipient) ?? '';
    }
}
