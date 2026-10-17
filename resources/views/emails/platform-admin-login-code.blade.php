<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Platform Admin verification code</title>
</head>
<body style="margin:0;background:#f4f4f5;color:#18181b;font-family:Arial,sans-serif">
    <div style="max-width:560px;margin:0 auto;padding:32px 16px">
        <div style="background:#fff;border:1px solid #e4e4e7;border-radius:12px;padding:32px">
            <p style="margin:0 0 8px;color:#71717a;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Volymoly Platform Administration</p>
            <h1 style="margin:0 0 14px;font-size:24px">Verify your sign-in</h1>
            <p style="margin:0 0 22px;color:#52525b;line-height:1.6">Use this one-time code in the browser where you entered your password.</p>
            <div style="padding:18px;border-radius:10px;background:#f4f4f5;font-size:30px;font-weight:700;letter-spacing:.22em;text-align:center">{{ $code }}</div>
            <p style="margin:22px 0 0;color:#71717a;font-size:13px;line-height:1.6">This code expires in {{ $expiresInMinutes }} {{ $expiresInMinutes === 1 ? 'minute' : 'minutes' }}. If you did not attempt to sign in, do not share this code and reset your password.</p>
        </div>
    </div>
</body>
</html>
