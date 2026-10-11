<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>Reset your Platform Admin password</title>
</head>
<body style="margin: 0; padding: 0; background: #f5f5f3; color: #161616; font-family: Arial, Helvetica, sans-serif;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0;">Securely reset your Volymoly Platform Super Admin password.</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f5f5f3">
        <tr>
            <td align="center" style="padding: 48px 20px;">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width: 100%; max-width: 560px; border: 1px solid #dededb; border-radius: 16px;">
                    <tr>
                        <td style="padding: 44px;">
                            <img src="{{ $message->embed(resource_path('images/mail/volymoly-logo.png')) }}" alt="Volymoly" width="137" style="display: block; width: 137px; height: auto; border: 0;">
                            <p style="margin: 36px 0 0; color: #666666; font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase;">Platform control · Restricted area</p>
                            <h1 style="margin: 12px 0 0; color: #111111; font-size: 28px; line-height: 36px;">Reset your admin password</h1>
                            <p style="margin: 18px 0 0; color: #555555; font-size: 15px; line-height: 24px;">
                                We received a password reset request for<br>
                                <a href="mailto:{{ $email }}" style="color: #111111; font-weight: 700; overflow-wrap: anywhere;">{{ $email }}</a>
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top: 28px;">
                                <tr>
                                    <td bgcolor="#111111" style="border-radius: 9px;">
                                        <a href="{{ $resetUrl }}" style="display: block; padding: 14px 24px; color: #ffffff; font-size: 14px; font-weight: 700; text-decoration: none;">Reset admin password</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 28px 0 0; color: #6b6b6b; font-size: 13px; line-height: 21px;">
                                This single-use link expires in {{ $expiresInMinutes }} minutes. Only the latest reset link can be used.
                            </p>
                            <p style="margin: 18px 0 0; padding-top: 18px; border-top: 1px solid #ececea; color: #777777; font-size: 12px; line-height: 19px;">
                                If you did not request this reset, ignore this email and review access to your administrator account.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
