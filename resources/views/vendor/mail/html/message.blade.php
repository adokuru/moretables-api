@props(['message' => null])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')" :message="$message">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
MoreTables &middot; Lagos, Nigeria.

[Earn rewards]({{ config('app.url') }}) &middot; [Unsubscribe]({{ config('app.url') }}/unsubscribe)
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
