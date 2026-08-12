@props(['url', 'message' => null])
@php
    $configuredLogoUrl = config('mail.logo_url');
    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
    $isLocalAppUrl = in_array($appHost, ['localhost', '127.0.0.1', '::1'], true);
    $logoPath = public_path('logo.png');

    if ($message !== null && is_file($logoPath)) {
        $logoSrc = $message->embed($logoPath);
    } elseif (filled($configuredLogoUrl)) {
        $logoSrc = $configuredLogoUrl;
    } elseif ($isLocalAppUrl && is_file($logoPath)) {
        $logoSrc = 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath));
    } else {
        $logoSrc = asset('logo.png');
    }
@endphp
<tr>
<td align="center">
<table class="header-frame" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header" align="center">
<a href="{{ $url }}" style="display:inline-block;">
<img src="{{ $logoSrc }}" class="logo" width="142" alt="{{ trim($slot) !== '' ? trim($slot) : config('app.name') }}" style="display:inline-block;width:142px;height:auto;max-width:142px;">
</a>
</td>
</tr>
</table>
</td>
</tr>
