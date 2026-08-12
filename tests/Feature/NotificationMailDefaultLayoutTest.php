<?php

declare(strict_types=1);

use App\Notifications\AuthChallengeCodeNotification;
use App\Notifications\Channels\MoreTablesMailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

it('renders notification html with moretables tabular layout', function (): void {
    config(['app.url' => 'https://moretables.test']);

    $html = (string) (new MailMessage)
        ->greeting('Hello!')
        ->line('First paragraph.')
        ->action('Continue', 'https://example.com/cta')
        ->line('Outro line.')
        ->render();

    expect($html)->toContain('#A8442A')
        ->and($html)->toContain('Nantes')
        ->and($html)->toContain('Avenir')
        ->and($html)->toContain('logo.png')
        ->and($html)->toContain('First paragraph.')
        ->and($html)->toContain('Continue')
        ->and($html)->toContain('https://example.com/cta')
        ->and($html)->toMatch('/<table[^>]*align="center"[^>]*border="0"[^>]*>/');
});

it('renders plain text notification without html document boilerplate', function (): void {
    $channel = app(MoreTablesMailChannel::class);
    $message = (new MailMessage)
        ->greeting('Hi there')
        ->line('Body content.')
        ->action('Go', 'https://example.com/go');

    $ref = new ReflectionClass($channel);
    $method = $ref->getMethod('buildMarkdownText');
    $method->setAccessible(true);
    $closure = $method->invoke($channel, $message);
    $text = (string) $closure([]);

    expect($text)->not->toContain('<html')
        ->and($text)->not->toContain('<!DOCTYPE')
        ->and($text)->toContain('Hi there')
        ->and($text)->toContain('Body content.')
        ->and($text)->toContain('Go')
        ->and($text)->toContain('https://example.com/go');
});

it('embeds the logo in sent markdown notification emails', function (): void {
    config([
        'mail.default' => 'array',
        'mail.logo_url' => 'https://cdn.example.com/logo.png',
    ]);

    $transport = Mail::mailer('array')->getSymfonyTransport();
    $transport->flush();

    Notification::route('mail', 'recipient@example.com')
        ->notifyNow(new AuthChallengeCodeNotification('123456', 'log in'));

    $email = $transport->messages()[0]->getOriginalMessage();

    expect($email->getHtmlBody())
        ->toContain('src="cid:')
        ->not->toContain('https://cdn.example.com/logo.png')
        ->and($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getFilename())->toBe('logo.png');
});
