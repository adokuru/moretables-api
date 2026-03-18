@php
    use Illuminate\Contracts\Support\Htmlable;

    $lineToPlain = static function (mixed $line): string {
        if ($line instanceof Htmlable) {
            return trim(html_entity_decode(strip_tags($line->toHtml()), ENT_QUOTES, 'UTF-8'));
        }

        return trim(html_entity_decode(strip_tags((string) $line), ENT_QUOTES, 'UTF-8'));
    };

    $greetingText = filled($greeting ?? null)
        ? $lineToPlain($greeting)
        : (($level ?? 'info') === 'error' ? __('Whoops!') : __('Hello!'));

    $bodyPrimary = collect($introLines ?? [])->map($lineToPlain)->filter()->implode("\n\n");
    $bodySecondary = collect($outroLines ?? [])->map($lineToPlain)->filter()->implode("\n\n");

    $hasAction = filled($actionText ?? null) && filled($actionUrl ?? null);

    $footerNote = $hasAction
        ? __('If you\'re having trouble clicking the ":actionText" button, copy and paste this URL into your web browser:', ['actionText' => $actionText]).' '.($displayableActionUrl ?? '')
        : '';

    if (filled($salutation ?? null)) {
        $closingBlock = trim(html_entity_decode(
            strip_tags(preg_replace('#<br\s*/?>#i', "\n", (string) $salutation)),
            ENT_QUOTES,
            'UTF-8'
        ));
        $signOff = 'Thanks,';
        $signature = 'The MoreTables Team';
    } else {
        $closingBlock = null;
        $signOff = __('Regards,');
        $signature = config('app.name');
    }
@endphp
@include('emails.moretables-tabular-layout', [
    'subject' => $subject ?? config('app.name'),
    'greeting' => $greetingText,
    'bodyPrimary' => $bodyPrimary,
    'bodySecondary' => $bodySecondary,
    'showCta' => $hasAction,
    'ctaLabel' => $actionText ?? 'Continue',
    'ctaUrl' => $actionUrl ?? config('app.url'),
    'closingBlock' => $closingBlock,
    'signOff' => $signOff,
    'signature' => $signature,
    'footerNote' => $footerNote,
])
