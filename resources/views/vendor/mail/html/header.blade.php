@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ asset('logo.png') }}" class="logo" width="220" height="40" style="width: 220px; height: auto; max-width: 220px; margin-top: 15px; margin-bottom: 10px;" alt="{{ trim($slot) !== '' ? trim($slot) : config('app.name') }}">
</a>
</td>
</tr>
