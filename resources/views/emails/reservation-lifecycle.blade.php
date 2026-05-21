{{--
    Reservation lifecycle email (confirmed / changed / cancelled / reminder / guest added).
    Fonts: Nantes (greeting); Avenir (body/details/footer).
--}}
@php
    $logoUrl = asset('logo.png');
    $footerLink1Url = $footerLink1Url ?? config('app.url');
    $footerLink1Label = $footerLink1Label ?? 'Earn rewards';
    $footerLink2Url = $footerLink2Url ?? config('app.url');
    $footerLink2Label = $footerLink2Label ?? 'Unsubscribe';
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
<title>{{ $subject }}</title>
<meta charset="UTF-8" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<meta name="x-apple-disable-message-reformatting" content="" />
<meta content="width=device-width" name="viewport" />
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no" />
<style type="text/css">
table { border-collapse: separate; table-layout: fixed; mso-table-lspace: 0pt; mso-table-rspace: 0pt }
table td { border-collapse: collapse }
.ExternalClass { width: 100% }
.ExternalClass, .ExternalClass p, .ExternalClass span, .ExternalClass font, .ExternalClass td, .ExternalClass div { line-height: 100% }
body, a, li, p, h1, h2, h3 { -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; }
html { -webkit-text-size-adjust: none !important }
body { min-width: 100%; Margin: 0px; padding: 0px; background-color: #FFFFFF; }
body, #innerTable { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale }
img { Margin: 0; padding: 0; -ms-interpolation-mode: bicubic }
h1, h2, h3, p, a { overflow-wrap: normal; white-space: normal; word-break: break-word }
a { text-decoration: none }
h1, h2, h3, p { min-width: 100%!important; width: 100%!important; max-width: 100%!important; display: inline-block!important; border: 0; padding: 0; margin: 0 }
a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important }
@font-face {
    font-family: 'Nantes';
    src: url('https://your-cdn.example.com/nantes.woff2') format('woff2');
    font-weight: 400 700;
    font-display: swap;
}
@media (max-width: 480px) {
.t-outer { padding-left: 24px !important; padding-right: 24px !important; }
.t-greeting { font-size: 26px !important; }
}
</style>
</head>
<body id="body" style="min-width:100%;Margin:0px;padding:0px;background-color:#FFFFFF;">
<div style="background-color:#FFFFFF;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center">
<tr><td style="background-color:#FFFFFF;" valign="top" align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center" id="innerTable">
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;">
<tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;">
<tr><td class="t-outer" style="background-color:#FFFFFF;padding:40px 50px 35px 50px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;">

{{-- Logo --}}
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="142" style="width:142px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td>
<div style="font-size:0px;"><img style="display:block;border:0;height:auto;width:100%;Margin:0;max-width:100%;" width="142" alt="MoreTables" src="{{ $logoUrl }}" /></div>
</td></tr></table>
</td></tr></table>
</td></tr>

{{-- Spacer --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:32px;font-size:1px;display:block;">&nbsp;</div></td></tr>

{{-- Greeting --}}
<tr><td align="left">
<p class="t-greeting" style="margin:0;Margin:0;font-family:'Nantes','Iowan Old Style','Palatino Linotype','Book Antiqua',Georgia,serif;line-height:38px;font-weight:700;font-size:32px;color:#1a1a1a;text-align:left;mso-line-height-rule:exactly;">Hi {{ $guestName }},</p>
</td></tr>

{{-- Spacer --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:20px;font-size:1px;display:block;">&nbsp;</div></td></tr>

{{-- Subtitle --}}
<tr><td align="left">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:700;font-size:16px;color:#1a1a1a;text-align:left;mso-line-height-rule:exactly;">{{ $title }}</p>
</td></tr>
<tr><td><div style="mso-line-height-rule:exactly;line-height:8px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">{{ $subtitle }}</p>
</td></tr>

{{-- Spacer --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:24px;font-size:1px;display:block;">&nbsp;</div></td></tr>

{{-- Reservation details --}}
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;">
<tr><td style="padding-bottom:6px;">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;"><strong>{{ $restaurantName }}</strong></p>
</td></tr>
<tr><td style="padding-bottom:6px;">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">{{ $tableInfo }}</p>
</td></tr>
<tr><td style="padding-bottom:6px;">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">Name: <strong>{{ $guestName }}</strong></p>
</td></tr>
<tr><td>
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">Confirmation #: <strong>{{ $confirmationNumber }}</strong></p>
</td></tr>
@if($showRestaurantContactDetails)
@if(filled($addressLineOne))
<tr><td style="padding-top:6px;">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">{{ $addressLineOne }}</p>
</td></tr>
@endif
@if(filled($addressLineTwo))
<tr><td style="padding-top:6px;">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">{{ $addressLineTwo }}</p>
</td></tr>
@endif
@if(filled($restaurantPhone))
<tr><td style="padding-top:6px;">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">{{ $restaurantPhone }}</p>
</td></tr>
@endif
@endif
</table>
</td></tr>

{{-- Extra body lines (optional) --}}
@if(filled($extraBody ?? ''))
<tr><td><div style="mso-line-height-rule:exactly;line-height:20px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:400;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">{!! nl2br(e($extraBody)) !!}</p>
</td></tr>
@endif

{{-- Spacer --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:24px;font-size:1px;display:block;">&nbsp;</div></td></tr>

{{-- CTA link --}}
<tr><td align="left">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:700;font-size:16px;color:#1a1a1a;text-align:left;mso-line-height-rule:exactly;">Need to make changes?</p>
</td></tr>
<tr><td><div style="mso-line-height-rule:exactly;line-height:4px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<a href="{{ $ctaUrl }}" style="font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:16px;line-height:26px;font-weight:400;color:#333333;text-decoration:underline;" target="_blank">{{ $ctaLabel }}</a>
</td></tr>
@if(filled($menuUrl))
<tr><td><div style="mso-line-height-rule:exactly;line-height:8px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<a href="{{ $menuUrl }}" style="font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:16px;line-height:26px;font-weight:400;color:#333333;text-decoration:underline;" target="_blank">See Menu</a>
</td></tr>
@endif
@if(filled($directionsUrl))
<tr><td><div style="mso-line-height-rule:exactly;line-height:8px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<a href="{{ $directionsUrl }}" style="font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:16px;line-height:26px;font-weight:400;color:#333333;text-decoration:underline;" target="_blank">Get Directions</a>
</td></tr>
@endif

{{-- Spacer --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:24px;font-size:1px;display:block;">&nbsp;</div></td></tr>

{{-- Closing message --}}
<tr><td align="left">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:400;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">If you ever need help, we're always here for you.</p>
</td></tr>

{{-- Spacer --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:28px;font-size:1px;display:block;">&nbsp;</div></td></tr>

{{-- Sign-off --}}
<tr><td align="left">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:400;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">{{ $signOff }}</p>
</td></tr>
<tr><td align="left">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:400;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;">The MoreTables Team</p>
</td></tr>

{{-- Spacer --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:24px;font-size:1px;display:block;">&nbsp;</div></td></tr>

{{-- Footer --}}
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-top:1px solid #E0E0E0;padding-top:20px;">
<tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-weight:400;font-size:14px;color:#888A8C;text-align:left;mso-line-height-rule:exactly;">Sent from MoreTables</p></td></tr>
<tr><td><div style="mso-line-height-rule:exactly;line-height:4px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-weight:400;font-size:14px;color:#888A8C;text-align:left;mso-line-height-rule:exactly;">MoreTables Ltd., Lagos Nigeria</p></td></tr>
<tr><td><div style="mso-line-height-rule:exactly;line-height:8px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-weight:400;font-size:14px;text-align:left;mso-line-height-rule:exactly;"><a href="{{ $footerLink1Url }}" style="font-size:14px;text-decoration:underline;color:#888A8C;" target="_blank">{{ $footerLink1Label }}</a></p></td></tr>
<tr><td><div style="mso-line-height-rule:exactly;line-height:8px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-weight:400;font-size:14px;text-align:left;mso-line-height-rule:exactly;"><a href="{{ $footerLink2Url }}" style="font-size:14px;text-decoration:underline;color:#888A8C;" target="_blank">{{ $footerLink2Label }}</a></p></td></tr>
</table>
</td></tr>

</table>
</td></tr>
</table>
</td></tr>
</table>
</td></tr>
</table>
</td></tr>
</table>
</div>
<div class="gmail-fix" style="display: none; white-space: nowrap; font: 15px courier; line-height: 0;">&nbsp;</div>
</body>
</html>
