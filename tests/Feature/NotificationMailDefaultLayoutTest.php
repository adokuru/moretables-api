<?php

declare(strict_types=1);

use App\Notifications\Channels\MoreTablesMailChannel;
use Illuminate\Notifications\Messages\MailMessage;

it('renders notification html with moretables tabular layout', function (): void {
    config(['app.url' => 'https://moretables.test']);

    $html = (string) (new MailMessage)
        ->greeting('Hello!')
        ->line('First paragraph.')
        ->action('Continue', 'https://example.com/cta')
        ->line('Outro line.')
        ->render();

    expect($html)->toContain('#FA0F00')
        ->and($html)->toContain('Nantes')
        ->and($html)->toContain('Avenir')
        ->and($html)->toContain('logo.png')
        ->and($html)->toContain('First paragraph.')
        ->and($html)->toContain('Continue')
        ->and($html)->toContain('https://example.com/cta');
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
