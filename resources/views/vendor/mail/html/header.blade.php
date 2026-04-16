@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('logo.png') }}" class="logo" alt="{{ trim($slot) !== '' ? trim($slot) : config('app.name') }}">
</a>
</td>
</tr>
