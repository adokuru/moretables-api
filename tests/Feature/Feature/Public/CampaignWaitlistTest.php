<?php

use App\Notifications\CampaignWaitlistConfirmationNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    Carbon::setTestNow('2026-07-14 12:30:00 Africa/Lagos');

    $privateKey = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIICdgIBADANBgkqhkiG9w0BAQEFAASCAmAwggJcAgEAAoGBANw4TnA5lX1QO5pA
QzugwOoQ1s1r2PoIpq4T+uPiTa97Byr6YvNivz12OwKLnL5NWe4/xNrjlO4V4e5Z
sfAFL1WapgvkHnGWMNZS7/KAnE4B7MHwX9VZR3+eC3MBXXiDokqqikyBRAcbkOTm
hyQ5qshAVnitd60Lvhpq2eDa9+VfAgMBAAECgYBXrb9lJTgknYYtgvqByh4k0zGC
tja8Tid21ZOlmZuOi3AuJ2SwPJehs+oIuGdeyVNN7WvGLqhEJsg+as3Sn23ZbVXM
rUcnivzuKS79Lbh66q8jbBGxEc+zckEP/Bi1qegCADJSixv5nFVmJdeuf7WA5OoG
Ix4AUhorHf3Cypb68QJBAO/kXCzWsLDomd+rliTv6jOSkAFI9HEL5np9Lwhx8FEJ
UeVLm7l7T+VfhdjvEmJgCH3/eff5vYCm6RKRD61ZAmcCQQDrAcqvj4XuBOlC25g/
ZrmfLiHuGOr6ajpGtCnN/+l1XXLvkQ6UaILbWiayLR2wXJbm234pvejK1AitK8JF
klpJAkEA1hUfRUybBmWt3HQOXAxXH4suRFdM/g22s50/+fNkmY0NrulYoaCwXmxu
0HgaGfzF11vFB02yljteSJl4OiTzBQJAIWjWzNClpKn0E3oukczj1Lp1PmkydrlF
YanZS5z3LqVDYsWHghe9irutRqVdVCZFmbpYnEyQXM16EkxnSQa+aQJAL9XiUQMA
SaWNOIzeaUiAy4aESk377o3DgTsURH76aGrdyDuPKLuSV6U8YsXE05k8oiw0c2nb
1wNhSaBCFvlBUQ==
-----END PRIVATE KEY-----
PEM;

    config()->set([
        'services.campaign_waitlist.service_account_base64' => base64_encode(json_encode([
            'client_email' => 'waitlist-service@example-project.iam.gserviceaccount.com',
            'private_key' => $privateKey,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR)),
        'services.campaign_waitlist.spreadsheet_id' => 'spreadsheet-id',
        'services.campaign_waitlist.sheet_range' => 'Waitlist!A:B',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('adds an email to google sheets and queues a confirmation email', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'expires_in' => 3600,
        ]),
        'sheets.googleapis.com/*' => Http::response([
            'updates' => ['updatedRows' => 1],
        ]),
    ]);

    $response = $this->postJson('/api/v1/campaign-waitlist', [
        'email' => '  Subscriber@Example.com ',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'You have joined the waitlist. Please check your email for confirmation.');

    $recordedSheetRequest = Http::recorded()
        ->first(fn (array $recorded): bool => str_contains(
            $recorded[0]->url(),
            'sheets.googleapis.com/v4/spreadsheets/spreadsheet-id',
        ))[0];

    expect($recordedSheetRequest)
        ->toBeInstanceOf(Request::class)
        ->and($recordedSheetRequest->hasHeader('Authorization', 'Bearer google-access-token'))->toBeTrue()
        ->and($recordedSheetRequest['majorDimension'])->toBe('ROWS')
        ->and($recordedSheetRequest['values'][0][0])->toBe('subscriber@example.com')
        ->and(Carbon::parse($recordedSheetRequest['values'][0][1])->equalTo(now()))->toBeTrue()
        ->and($recordedSheetRequest->url())->toContain(
            'valueInputOption=RAW',
            'insertDataOption=INSERT_ROWS',
        );

    Notification::assertSentOnDemand(
        CampaignWaitlistConfirmationNotification::class,
        function (
            CampaignWaitlistConfirmationNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable,
        ): bool {
            return $channels === ['mail']
                && $notifiable->routes['mail'] === 'subscriber@example.com';
        },
    );
});

it('validates the email before calling google', function (mixed $email) {
    Http::fake();

    $this->postJson('/api/v1/campaign-waitlist', ['email' => $email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    Http::assertNothingSent();
    Notification::assertNothingSent();
})->with([
    'missing' => null,
    'invalid' => 'not-an-email',
]);

it('returns service unavailable and does not send email when google rejects the append', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access-token',
            'expires_in' => 3600,
        ]),
        'sheets.googleapis.com/*' => Http::response([
            'error' => ['message' => 'Permission denied'],
        ], 403),
    ]);

    $this->postJson('/api/v1/campaign-waitlist', [
        'email' => 'subscriber@example.com',
    ])->assertServiceUnavailable()
        ->assertJsonPath('message', 'We could not add you to the waitlist right now. Please try again shortly.');

    Notification::assertNothingSent();
});

it('renders the confirmation email content', function () {
    $mail = (new CampaignWaitlistConfirmationNotification)
        ->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe("You're on the MoreTables waitlist")
        ->and((string) $mail->render())->toContain('Thanks for joining the MoreTables waitlist.');
});
