<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppCancelRequest;
use Dedoc\Scramble\Attributes\ExcludeAllRoutesFromDocs;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[ExcludeAllRoutesFromDocs]
class WhatsAppWebhookController extends Controller
{
    /**
     * Meta's one-time endpoint verification handshake. PHP rewrites the
     * `hub.mode` style query keys to `hub_mode`.
     */
    public function verify(Request $request): Response
    {
        $verifyToken = (string) config('services.whatsapp.webhook_verify_token');

        if ($verifyToken !== ''
            && $request->query('hub_mode') === 'subscribe'
            && hash_equals($verifyToken, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'));
        }

        abort(403);
    }

    public function handle(Request $request): Response
    {
        abort_unless($this->hasValidSignature($request), 403);

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    $this->handleInboundMessage($message);
                }
            }
        }

        return response('');
    }

    /**
     * Meta signs the raw request body with the app secret and sends the HMAC
     * in the X-Hub-Signature-256 header. Reject everything when no app secret
     * is configured rather than accepting unsigned payloads.
     */
    protected function hasValidSignature(Request $request): bool
    {
        $appSecret = (string) config('services.whatsapp.app_secret');

        if ($appSecret === '') {
            return false;
        }

        $signature = (string) $request->header('X-Hub-Signature-256');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, Str::after($signature, 'sha256='));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function handleInboundMessage(array $message): void
    {
        if (($message['type'] ?? null) !== 'button') {
            return;
        }

        $payload = (string) ($message['button']['payload'] ?? '');
        $from = (string) ($message['from'] ?? '');

        if ($from === '' || ! str_starts_with($payload, 'cancel_reservation:')) {
            return;
        }

        $reservationId = (int) Str::after($payload, 'cancel_reservation:');

        if ($reservationId <= 0) {
            Log::warning('WhatsApp webhook received a malformed cancel payload.', ['payload' => $payload]);

            return;
        }

        ProcessWhatsAppCancelRequest::dispatch($reservationId, $from);
    }
}
