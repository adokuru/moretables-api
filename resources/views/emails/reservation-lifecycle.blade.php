<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
<title>{{ $subject }}</title>
<meta charset="UTF-8" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="x-apple-disable-message-reformatting" content="" />
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no" />
<style type="text/css">
body {
    margin: 0;
    padding: 0;
    background-color: #f2f3f5;
}

table {
    border-collapse: collapse;
}

img {
    border: 0;
    display: block;
    line-height: 100%;
    outline: none;
    text-decoration: none;
}

@media only screen and (max-width: 640px) {
    .email-shell {
        width: 100% !important;
    }

    .card-body {
        padding: 40px 24px !important;
    }

    .restaurant-image {
        width: 120px !important;
        height: 120px !important;
    }

    .heading {
        font-size: 36px !important;
        line-height: 42px !important;
    }
}
</style>
</head>
<body>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#f2f3f5;">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" class="email-shell" style="width:100%;max-width:640px;">
<tr>
<td align="center" style="padding-bottom:24px;">
<img src="{{ $logoUrl }}" width="168" alt="MoreTables" style="width:168px;max-width:168px;height:auto;" />
</td>
</tr>
<tr>
<td>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#ffffff;border-radius:28px;">
<tr>
<td align="center" class="card-body" style="padding:48px 40px;">
<h1 class="heading" style="margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:42px;line-height:48px;font-weight:500;color:#26313d;">{{ $title }}</h1>
<p style="margin:16px 0 0 0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:22px;line-height:30px;color:#4f5965;">{{ $subtitle }}</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:32px auto 0 auto;">
<tr>
<td align="center">
@if($restaurantImageUrl)
<img src="{{ $restaurantImageUrl }}" width="136" height="136" alt="{{ $restaurantName }}" class="restaurant-image" style="width:136px;height:136px;border-radius:68px;object-fit:cover;" />
@else
<table role="presentation" width="136" height="136" cellpadding="0" cellspacing="0" border="0" class="restaurant-image" style="width:136px;height:136px;background-color:#f4dad5;border-radius:68px;">
<tr>
<td align="center" valign="middle" style="font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:48px;line-height:48px;font-weight:600;color:#c64d3c;">{{ $restaurantInitial }}</td>
</tr>
</table>
@endif
</td>
</tr>
</table>

<p style="margin:24px 0 0 0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:34px;line-height:40px;font-weight:500;color:#d14e43;">{{ $restaurantName }}</p>
<p style="margin:20px 0 0 0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:24px;line-height:34px;font-weight:600;color:#26313d;">{{ $tableInfo }}</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:28px auto 0 auto;">
<tr>
<td align="center">
<p style="margin:0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:22px;line-height:32px;color:#3d4650;"><span style="font-weight:600;">Name:</span> {{ $guestName }}</p>
<p style="margin:8px 0 0 0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:22px;line-height:32px;color:#3d4650;"><span style="font-weight:600;">Confirmation #:</span> {{ $confirmationNumber }}</p>
@if($showRestaurantContactDetails)
@if($addressLineOne)
<p style="margin:28px 0 0 0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:18px;line-height:28px;color:#4f5965;">{{ $addressLineOne }}</p>
@endif
@if($addressLineTwo)
<p style="margin:6px 0 0 0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:18px;line-height:28px;color:#4f5965;">{{ $addressLineTwo }}</p>
@endif
@if($restaurantPhone)
<p style="margin:6px 0 0 0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:18px;line-height:28px;color:#4f5965;">{{ $restaurantPhone }}</p>
@endif
@endif
@if($menuUrl || $directionsUrl)
<p style="margin:28px 0 0 0;font-family:'Avenir Next','Avenir','Helvetica Neue',Helvetica,Arial,sans-serif;font-size:20px;line-height:30px;font-weight:600;color:#d14e43;">
@if($menuUrl)
<a href="{{ $menuUrl }}" style="color:#d14e43;text-decoration:none;">See Menu</a>
@endif
@if($menuUrl && $directionsUrl)
<span style="color:#d14e43;">&nbsp;|&nbsp;</span>
@endif
@if($directionsUrl)
<a href="{{ $directionsUrl }}" style="color:#d14e43;text-decoration:none;">Get Directions</a>
@endif
</p>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
