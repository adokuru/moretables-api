<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;

it('renders tabular layout with Nantes greeting, Avenir body, and logo asset url', function () {
    $html = view('emails.moretables-tabular-layout', [
        'recipientName' => 'Max',
        'bodyPrimary' => 'Primary copy.',
        'bodySecondary' => 'Secondary copy.',
        'ctaLabel' => 'Reactivate now',
    ])->render();

    expect($html)
        ->toContain("'Nantes'")
        ->toContain("'Avenir Next'")
        ->toContain('Hi Max,')
        ->toContain('Primary copy.')
        ->toContain('data:image/png;base64,');
});

it('uses MAIL_LOGO_URL when configured for tabular emails', function () {
    config(['mail.logo_url' => 'https://cdn.example.com/logo.png']);

    $html = view('emails.moretables-tabular-layout', [
        'recipientName' => 'Max',
        'bodyPrimary' => 'Primary copy.',
    ])->render();

    expect($html)->toContain('https://cdn.example.com/logo.png');
});

it('embeds the logo in sent tabular emails', function (): void {
    config([
        'mail.default' => 'array',
        'mail.logo_url' => 'https://cdn.example.com/logo.png',
    ]);

    $sentMessage = Mail::mailer('array')->send(
        'emails.moretables-tabular-layout',
        [
            'recipientName' => 'Max',
            'bodyPrimary' => 'Primary copy.',
        ],
        fn ($message) => $message
            ->to('max@example.com')
            ->subject('Logo test'),
    );

    $email = $sentMessage->getOriginalMessage();

    expect($email->getHtmlBody())
        ->toContain('src="cid:')
        ->not->toContain('https://cdn.example.com/logo.png')
        ->and($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getFilename())->toBe('logo.png');
});
