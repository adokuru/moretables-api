<?php

declare(strict_types=1);

it('uses Nantes for headings and Avenir for body in the mail theme', function (): void {
    $path = resource_path('views/vendor/mail/html/themes/default.css');
    expect(file_exists($path))->toBeTrue();

    $css = file_get_contents($path);
    expect($css)->toContain("'Nantes'");
    expect($css)->toContain("'Avenir Next'");
    expect($css)->toContain("'Avenir'");
});
