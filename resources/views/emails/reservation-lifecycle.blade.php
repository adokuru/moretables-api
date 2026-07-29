{{--
    Reservation lifecycle email (confirmed / changed / cancelled / reminder / guest added).
    Centered card layout. Fonts: Nantes (heading); Avenir (body/details/footer).
--}}
@php
    $logoUrl = asset('logo.png');
    $brandColor = '#A8442A';
    $restaurantImageUrl = $restaurantImageUrl ?? null;
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
body { min-width: 100%; Margin: 0px; padding: 0px; background-color: #EEF0F2; }
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
.t-card { padding-left: 28px !important; padding-right: 28px !important; }
.t-heading { font-size: 28px !important; }
}
</style>
</head>
<body id="body" style="min-width:100%;Margin:0px;padding:0px;background-color:#EEF0F2;">
<div style="background-color:#EEF0F2;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center">
<tr><td style="background-color:#EEF0F2;" valign="top" align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center" id="innerTable">
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;">
<tr><td width="600" style="width:600px;">

{{-- Logo (on gray, centered) --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;">
<tr><td align="center" style="padding:32px 0 24px 0;">
<img style="display:inline-block;border:0;height:auto;width:142px;max-width:142px;Margin:0;" width="142" alt="MoreTables" src="{{ $logoUrl }}" />
</td></tr>
</table>

{{-- White card --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;background-color:#FFFFFF;">
<tr><td class="t-card" style="background-color:#FFFFFF;padding:48px 50px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;">

{{-- Heading --}}
<tr><td align="center">
<p class="t-heading" style="margin:0;Margin:0;font-family:'Nantes','Iowan Old Style','Palatino Linotype','Book Antiqua',Georgia,serif;line-height:42px;font-weight:700;font-size:34px;color:#1a1a1a;text-align:center;mso-line-height-rule:exactly;">{{ $title }}</p>
</td></tr>

{{-- Subtitle --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:12px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-size:16px;color:#555555;text-align:center;mso-line-height-rule:exactly;">{{ $subtitle }}</p>
</td></tr>

{{-- Restaurant image (circular) --}}
@if(filled($restaurantImageUrl))
<tr><td><div style="mso-line-height-rule:exactly;line-height:32px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<img src="{{ $restaurantImageUrl }}" width="150" height="150" alt="{{ $restaurantName }}" style="display:inline-block;border:0;width:150px;height:150px;max-width:150px;border-radius:50%;object-fit:cover;Margin:0;" />
</td></tr>
@endif

{{-- Restaurant name (brand) --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:28px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:28px;font-weight:700;font-size:20px;color:{{ $brandColor }};text-align:center;mso-line-height-rule:exactly;">{{ $restaurantName }}</p>
</td></tr>

{{-- Table / datetime --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:14px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:700;font-size:17px;color:#1a1a1a;text-align:center;mso-line-height-rule:exactly;">{{ $tableInfo }}</p>
</td></tr>

{{-- Name + confirmation --}}
<tr><td><div style="mso-line-height-rule:exactly;line-height:16px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:24px;font-size:15px;color:#333333;text-align:center;mso-line-height-rule:exactly;">Name: {{ $guestName }}</p>
</td></tr>
<tr><td><div style="mso-line-height-rule:exactly;line-height:4px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:24px;font-size:15px;color:#333333;text-align:center;mso-line-height-rule:exactly;">Confirmation #: {{ $confirmationNumber }}</p>
</td></tr>

{{-- Extra body lines (optional) --}}
@if(filled($extraBody ?? ''))
<tr><td><div style="mso-line-height-rule:exactly;line-height:24px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:24px;font-size:15px;color:#333333;text-align:center;mso-line-height-rule:exactly;">{!! nl2br(e($extraBody)) !!}</p>
</td></tr>
@endif

{{-- See menu | Get directions --}}
@if((filled($menuUrl) || filled($directionsUrl)) && ! $showNewReservationButton)
<tr><td><div style="mso-line-height-rule:exactly;line-height:28px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:24px;font-weight:700;font-size:15px;text-align:center;mso-line-height-rule:exactly;">
@if(filled($menuUrl))<a href="{{ $menuUrl }}" style="color:{{ $brandColor }};text-decoration:none;" target="_blank">See menu</a>@endif
@if(filled($menuUrl) && filled($directionsUrl))<span style="color:{{ $brandColor }};">&nbsp;|&nbsp;</span>@endif
@if(filled($directionsUrl))<a href="{{ $directionsUrl }}" style="color:{{ $brandColor }};text-decoration:none;" target="_blank">Get directions</a>@endif
</p>
</td></tr>
@endif

{{-- Address / phone --}}
@if($showRestaurantContactDetails)
@if(filled($addressLineOne))
<tr><td><div style="mso-line-height-rule:exactly;line-height:24px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:24px;font-size:14px;color:#555555;text-align:center;mso-line-height-rule:exactly;">{{ $addressLineOne }}</p>
</td></tr>
@endif
@if(filled($addressLineTwo))
<tr><td><div style="mso-line-height-rule:exactly;line-height:4px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:24px;font-size:14px;color:#555555;text-align:center;mso-line-height-rule:exactly;">{{ $addressLineTwo }}</p>
</td></tr>
@endif
@if(filled($restaurantPhone))
<tr><td><div style="mso-line-height-rule:exactly;line-height:4px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:24px;font-size:14px;color:#555555;text-align:center;mso-line-height-rule:exactly;">{{ $restaurantPhone }}</p>
</td></tr>
@endif
@endif

{{-- Calendar / Modify / Cancel actions (confirmed & changed) --}}
@if($showReservationActions)
<tr><td><div style="mso-line-height-rule:exactly;line-height:32px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:24px;font-weight:700;font-size:15px;text-align:center;mso-line-height-rule:exactly;">
<a href="{{ $calendarUrl }}" style="color:{{ $brandColor }};text-decoration:none;" target="_blank">Add to calendar</a>
<span style="color:#CCCCCC;">&nbsp;&nbsp;&middot;&nbsp;&nbsp;</span>
<a href="{{ $modifyUrl }}" style="color:{{ $brandColor }};text-decoration:none;" target="_blank">Modify</a>
<span style="color:#CCCCCC;">&nbsp;&nbsp;&middot;&nbsp;&nbsp;</span>
<a href="{{ $cancelUrl }}" style="color:{{ $brandColor }};text-decoration:none;" target="_blank">Cancel</a>
</p>
</td></tr>
@endif

{{-- Make a new reservation (cancelled) --}}
@if($showNewReservationButton)
<tr><td><div style="mso-line-height-rule:exactly;line-height:36px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;">
<tr><td align="center" style="background-color:{{ $brandColor }};border-radius:4px;">
<a href="{{ $newReservationUrl }}" style="display:inline-block;padding:16px 40px;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;color:#FFFFFF;text-decoration:none;" target="_blank">Make a new reservation</a>
</td></tr>
</table>
</td></tr>
@endif

{{-- Leave a review (review_request) --}}
@if($showReviewButton)
<tr><td><div style="mso-line-height-rule:exactly;line-height:36px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;">
<tr><td align="center" style="background-color:{{ $brandColor }};border-radius:4px;">
<a href="{{ $reviewUrl }}" style="display:inline-block;padding:16px 40px;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;color:#FFFFFF;text-decoration:none;" target="_blank">Leave a review</a>
</td></tr>
</table>
</td></tr>
@endif

</table>
</td></tr>
</table>

{{-- Footer (on gray, centered) --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;">
<tr><td align="center" style="padding:28px 50px 40px 50px;">
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-size:13px;color:#888A8C;text-align:center;mso-line-height-rule:exactly;">MoreTables Ltd. &middot; Lagos, Nigeria</p>
<div style="mso-line-height-rule:exactly;line-height:10px;font-size:1px;display:block;">&nbsp;</div>
<p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-size:13px;text-align:center;mso-line-height-rule:exactly;">
<a href="{{ $footerLink1Url }}" style="font-size:13px;text-decoration:underline;color:#888A8C;" target="_blank">{{ $footerLink1Label }}</a>
<span style="color:#888A8C;">&nbsp;&middot;&nbsp;</span>
<a href="{{ $footerLink2Url }}" style="font-size:13px;text-decoration:underline;color:#888A8C;" target="_blank">{{ $footerLink2Label }}</a>
</p>
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
