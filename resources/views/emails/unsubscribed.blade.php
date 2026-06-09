<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Unsubscribed</title>
<style>
    body { margin: 0; background-color: #EEF0F2; font-family: 'Avenir Next', 'Avenir', 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1a1a1a; }
    .wrap { max-width: 480px; margin: 64px auto; padding: 0 24px; }
    .card { background-color: #FFFFFF; border-radius: 8px; padding: 40px 32px; text-align: center; }
    h1 { font-size: 24px; margin: 0 0 12px; }
    p { font-size: 15px; line-height: 24px; color: #555555; margin: 0 0 8px; }
    .email { font-weight: 700; color: #1a1a1a; }
    .btn { display: inline-block; margin-top: 24px; padding: 14px 32px; background-color: #A8442A; color: #FFFFFF; border-radius: 4px; text-decoration: none; font-weight: 700; }
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>You've been unsubscribed</h1>
        @if($email !== '')
            <p>We won't send any more emails to <span class="email">{{ $email }}</span>.</p>
        @else
            <p>We won't send any more emails to this address.</p>
        @endif
        <p>Changed your mind? You can update your email preferences from your account.</p>
        <a class="btn" href="{{ $frontendBaseUrl }}">Back to MoreTables</a>
    </div>
</div>
</body>
</html>
