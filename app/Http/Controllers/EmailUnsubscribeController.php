<?php

namespace App\Http\Controllers;

use App\Models\EmailUnsubscribe;
use App\Models\GuestContact;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailUnsubscribeController extends Controller
{
    public function show(Request $request): View
    {
        $email = $this->unsubscribe($request);

        return view('emails.unsubscribed', [
            'email' => $email,
            'frontendBaseUrl' => rtrim(config('app.frontend_urls.main') ?: config('app.url'), '/'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->unsubscribe($request);

        return response()->json(['status' => 'unsubscribed']);
    }

    protected function unsubscribe(Request $request): string
    {
        $email = (string) $request->query('email', '');

        EmailUnsubscribe::suppress($email);

        $normalized = EmailUnsubscribe::normalizeEmail($email);

        User::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->update(['notify_marketing_emails' => false]);

        GuestContact::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->update(['marketing_opt_in' => false]);

        return $email;
    }
}
