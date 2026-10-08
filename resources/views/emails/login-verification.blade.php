<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Your verification code</title>
    <style>
        @font-face {
            font-family: 'Plus Jakarta Sans';
            font-style: normal;
            font-weight: 400;
            src: url('{{ rtrim($frontendUrl, '/') }}/fonts/plus-jakarta-400.ttf') format('truetype');
        }
        @font-face {
            font-family: 'Plus Jakarta Sans';
            font-style: normal;
            font-weight: 500;
            src: url('{{ rtrim($frontendUrl, '/') }}/fonts/plus-jakarta-500.ttf') format('truetype');
        }
        @font-face {
            font-family: 'Plus Jakarta Sans';
            font-style: normal;
            font-weight: 700;
            src: url('{{ rtrim($frontendUrl, '/') }}/fonts/plus-jakarta-700.ttf') format('truetype');
        }
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        table { border-collapse: collapse; }
        @media only screen and (max-width: 600px) {
            .email-card { height: auto !important; }
            .email-shell { height: auto !important; padding: 64px 24px !important; }
            .email-content { width: 100% !important; height: auto !important; }
            .section-space { height: 64px !important; line-height: 64px !important; }
            .content-space { height: 56px !important; line-height: 56px !important; }
            .email-heading { font-size: 26px !important; line-height: 34px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #f5f5f5; color: #141414; font-family: 'Plus Jakarta Sans', Arial, Helvetica, sans-serif;">
    <div style="display: none; font-size: 1px; line-height: 1px; color: #ffffff; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all;">Use your one-time verification code to finish signing in to Volymoly.</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f5f5f5" style="width: 100%; background-color: #f5f5f5;">
        <tr>
            <td align="center" style="padding: 0;">
                <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
                <table class="email-card" role="presentation" width="600" height="1024" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width: 100%; max-width: 600px; height: 1024px; background-color: #ffffff;">
                    <tr>
                        <td class="email-shell" align="center" valign="middle" style="height: 960px; padding: 32px 96px;">
                            <!--[if mso]><table role="presentation" width="403" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
                            <table class="email-content" role="presentation" width="403" height="600" cellpadding="0" cellspacing="0" border="0" style="width: 403px; max-width: 403px; height: 600px;">
                                <tr>
                                    <td align="left" valign="top">
                                        <img src="{{ $message->embed(resource_path('images/mail/volymoly-logo.png')) }}" alt="volymoly" width="158" height="46" style="display: block; width: 158px; height: 46px; border: 0; color: #d43022; font-size: 28px;">
                                        <div style="height: 96px; line-height: 96px; font-size: 1px;" aria-hidden="true">&nbsp;</div>
                                        <h1 class="email-heading" style="margin: 0; font-family: 'Plus Jakarta Sans', Arial, Helvetica, sans-serif; font-size: 28px; line-height: 36px; font-weight: 700; color: #141414;">Your verification code</h1>
                                        <p style="margin: 24px 0 0; font-family: 'Plus Jakarta Sans', Arial, Helvetica, sans-serif; font-size: 16px; line-height: 24px; font-weight: 400; color: #141414;">We received a request to verify your account. Use this one time code to continue:</p>
                                        <p dir="ltr" style="margin: 24px 0 0; font-family: 'Plus Jakarta Sans', Arial, Helvetica, sans-serif; font-size: 18px; line-height: 24px; font-weight: 600; color: #141414;">{{ $code }}</p>
                                    </td>
                                </tr>
                                <tr><td class="section-space" height="118" style="height: 118px; font-size: 1px; line-height: 118px;" aria-hidden="true">&nbsp;</td></tr>
                                <tr>
                                    <td align="left" valign="top" style="font-family: 'Plus Jakarta Sans', Arial, Helvetica, sans-serif; font-size: 16px; line-height: 24px; font-weight: 400; color: #141414;">
                                        <p style="margin: 0;">This code expires in {{ $expiryText }}. Do not share this verification code with anyone.</p>
                                    </td>
                                </tr>
                                <tr><td class="content-space" height="118" style="height: 118px; font-size: 1px; line-height: 118px;" aria-hidden="true">&nbsp;</td></tr>
                                <tr>
                                    <td align="left" valign="bottom" style="font-family: 'Plus Jakarta Sans', Arial, Helvetica, sans-serif; font-size: 12px; line-height: 18px; font-weight: 400; color: #141414;">
                                        <a href="{{ $frontendUrl }}" style="color: #141414; text-decoration: underline;">volymoly studio</a>, Indore 452001, India. All rights reserved.
                                    </td>
                                </tr>
                            </table>
                            <!--[if mso]></td></tr></table><![endif]-->
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
