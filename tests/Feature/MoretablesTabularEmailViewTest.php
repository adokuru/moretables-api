<?php

declare(strict_types=1);

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
        ->toContain('Dear Max,')
        ->toContain('Primary copy.')
        ->toContain(parse_url(asset('logo.png'), PHP_URL_PATH) ?? '/logo.png');
});
