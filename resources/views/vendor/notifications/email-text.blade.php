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
@endphp
{{ $greetingText }}

@foreach ($introLines ?? [] as $line)
{{ $lineToPlain($line) }}

@endforeach
@isset($actionText, $actionUrl)
{{ $actionText }}: {{ $displayableActionUrl ?? $actionUrl }}

@endisset
@foreach ($outroLines ?? [] as $line)
{{ $lineToPlain($line) }}

@endforeach
@if (! empty($salutation))
{{ trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', "\n", (string) $salutation)), ENT_QUOTES, 'UTF-8')) }}
@else
{{ __('Regards,') }}

{{ config('app.name') }}
@endif
