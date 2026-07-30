{{--
    Tabular-style transactional layout: logo, greeting, body, CTA link, footer.
    Fonts: Nantes (greeting); Avenir (body/footer). Logo: public/logo.png via absolute URL.
--}}
@php
    $configuredLogoUrl = config('mail.logo_url');
    $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
    $isLocalAppUrl = in_array($appHost, ['localhost', '127.0.0.1', '::1'], true);
    $logoPath = public_path('logo.png');

    if (filled($configuredLogoUrl)) {
        $logoUrl = $configuredLogoUrl;
    } elseif ($isLocalAppUrl && is_file($logoPath)) {
        $logoUrl = 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath));
    } else {
        $logoUrl = asset('logo.png');
    }

    $recipientName = $recipientName ?? 'there';
    $greeting = $greeting ?? "Hi {$recipientName},";
    $bodyPrimary = $bodyPrimary ?? '';
    $bodySecondary = $bodySecondary ?? '';
    $bodyRaw = $bodyRaw ?? null;
    $ctaUrl = $ctaUrl ?? config('app.url');
    $ctaLabel = $ctaLabel ?? 'Continue';
    $signOff = $signOff ?? 'Thanks,';
    $signature = $signature ?? 'The MoreTables Team';
    $footerLine1 = $footerLine1 ?? 'MoreTables Ltd.';
    $footerLine2 = $footerLine2 ?? 'Lagos, Nigeria';
    $footerLink1Url = $footerLink1Url ?? config('app.url');
    $footerLink1Label = $footerLink1Label ?? 'Earn rewards';
    $footerLink2Url = $footerLink2Url ?? config('app.url');
    $footerLink2Label = $footerLink2Label ?? 'Unsubscribe';
    $showCta = $showCta ?? true;
    $closingBlock = $closingBlock ?? null;
    $footerNote = $footerNote ?? '';
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en">
<head>
<title>{{ $subject ?? config('app.name') }}</title>
<meta charset="UTF-8" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<!--[if !mso]>-->
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<!--<![endif]-->
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
body { min-width: 100%; Margin: 0px; padding: 0px; }
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
.t78{padding-left:24px!important;padding-right:24px!important}.t5{font-size:26px!important}.t12,.t18,.t35,.t40,.t45,.t52,.t60,.t68{font-size:14px!important}
}
</style>
<!--[if mso]>
<xml>
<o:OfficeDocumentSettings>
<o:AllowPNG/>
<o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings>
</xml>
<![endif]-->
</head>
<body id="body" style="min-width:100%;Margin:0px;padding:0px;background-color:#FFFFFF;">
<div style="background-color:#FFFFFF;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td style="font-size:0;line-height:0;mso-line-height-rule:exactly;background-color:#FFFFFF;" valign="top" align="center">
<!--[if mso]>
<v:background xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false">
<v:fill color="#FFFFFF"/>
</v:background>
<![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center" id="innerTable"><tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-left:auto;Margin-right:auto;"><tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td class="t78" style="background-color:#FFFFFF;padding:40px 50px 35px 50px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;"><tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="142" style="width:142px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td><div style="font-size:0px;"><img style="display:block;border:0;height:auto;width:100%;Margin:0;max-width:100%;" width="142" alt="{{ config('app.name') }}" src="{{ $logoUrl }}"/></div></td></tr></table>
</td></tr></table>
</td></tr>
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:32px;line-height:32px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td><p class="t5" style="margin:0;Margin:0;font-family:'Nantes','Iowan Old Style','Palatino Linotype','Book Antiqua',Georgia,serif;line-height:38px;font-weight:700;font-style:normal;font-size:32px;text-decoration:none;text-transform:none;direction:ltr;color:#1a1a1a;text-align:left;mso-line-height-rule:exactly;mso-text-raise:-2px;">{{ $greeting }}</p></td></tr></table>
</td></tr></table>
</td></tr>
@if($bodyRaw !== null)
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:20px;line-height:20px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td style="font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:16px;line-height:26px;color:#333333;text-align:left;">{!! $bodyRaw !!}</td></tr></table>
</td></tr></table>
</td></tr>
@endif
@if(filled($bodyPrimary))
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:20px;line-height:20px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:400;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">{!! nl2br(e($bodyPrimary)) !!}</p></td></tr></table>
</td></tr></table>
</td></tr>
@endif
@if(filled($bodySecondary))
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:20px;line-height:20px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:400;font-style:normal;font-size:16px;text-decoration:none;text-transform:none;direction:ltr;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">{!! nl2br(e($bodySecondary)) !!}</p></td></tr></table>
</td></tr></table>
</td></tr>
@endif
@if($showCta)
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:28px;line-height:28px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td>
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td><a href="{{ $ctaUrl }}" style="font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:16px;line-height:26px;font-weight:400;color:#333333;text-decoration:underline;" target="_blank">{{ $ctaLabel }}</a></td></tr></table>
</td></tr></table>
</td></tr>
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:28px;line-height:28px;font-size:1px;display:block;">&nbsp;</div></td></tr>
@else
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:25px;line-height:25px;font-size:1px;display:block;">&nbsp;</div></td></tr>
@endif
@if(filled($closingBlock))
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:400;font-style:normal;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">{!! nl2br(e($closingBlock)) !!}</p></td></tr></table>
</td></tr></table>
</td></tr>
@else
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:400;font-style:normal;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">{{ $signOff }}</p></td></tr></table>
</td></tr></table>
</td></tr>
@if(filled($signature))
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:26px;font-weight:400;font-style:normal;font-size:16px;color:#333333;text-align:left;mso-line-height-rule:exactly;mso-text-raise:2px;">{{ $signature }}</p></td></tr></table>
</td></tr></table>
</td></tr>
@endif
@endif
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:24px;line-height:24px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td align="left">
<table role="presentation" cellpadding="0" cellspacing="0" style="Margin-right:auto;"><tr><td width="600" style="width:600px;">
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;"><tr><td style="border-top:1px solid #E0E0E0;padding:20px 0 0 0;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100% !important;">
<tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-weight:400;font-size:14px;color:#888A8C;text-align:left;mso-line-height-rule:exactly;">{{ $footerLine1 }}</p></td></tr>
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:4px;line-height:4px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-weight:400;font-size:14px;color:#888A8C;text-align:left;mso-line-height-rule:exactly;">{{ $footerLine2 }}</p></td></tr>
@if(filled($footerNote))
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:6px;line-height:6px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:20px;font-weight:400;font-size:13px;color:#AAAAAA;text-align:left;mso-line-height-rule:exactly;">{{ $footerNote }}</p></td></tr>
@endif
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:8px;line-height:8px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-weight:400;font-size:14px;text-align:left;mso-line-height-rule:exactly;"><a href="{{ $footerLink1Url }}" style="font-size:14px;text-decoration:underline;color:#888A8C;" target="_blank">{{ $footerLink1Label }}</a></p></td></tr>
<tr><td><div style="mso-line-height-rule:exactly;mso-line-height-alt:8px;line-height:8px;font-size:1px;display:block;">&nbsp;</div></td></tr>
<tr><td><p style="margin:0;Margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;line-height:22px;font-weight:400;font-size:14px;text-align:left;mso-line-height-rule:exactly;"><a href="{{ $footerLink2Url }}" style="font-size:14px;text-decoration:underline;color:#888A8C;" target="_blank">{{ $footerLink2Label }}</a></p></td></tr>
</table></td></tr></table>
</td></tr></table>
</td></tr></table>
</td></tr></table>
</td></tr></table>
</td></tr></table>
</td></tr></table></div>
<div class="gmail-fix" style="display: none; white-space: nowrap; font: 15px courier; line-height: 0;">&nbsp;</div>
</body>
</html>
