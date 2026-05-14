@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display:inline-block;">
<img src="{{ asset('logo.png') }}" class="logo" width="142" alt="{{ trim($slot) !== '' ? trim($slot) : config('app.name') }}" style="display:block;width:142px;height:auto;max-width:142px;">
</a>
</td>
</tr>
