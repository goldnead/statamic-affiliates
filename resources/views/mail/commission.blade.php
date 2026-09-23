<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('affiliates::mail.commission_heading') }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1c1917;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:32px;">
                <p style="margin:0 0 16px;font-size:16px;">{{ __('affiliates::mail.greeting', ['name' => $partner->name]) }}</p>
                <p style="margin:0 0 24px;font-size:16px;line-height:1.5;">{{ __('affiliates::mail.commission_intro') }}</p>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e7e5e4;border-bottom:1px solid #e7e5e4;margin:0 0 24px;">
                    <tr>
                        <td style="padding:12px 0;color:#57534e;font-size:14px;">{{ __('affiliates::mail.commission_amount') }}</td>
                        <td style="padding:12px 0;text-align:right;font-size:20px;font-weight:600;">{{ $amount }}</td>
                    </tr>
                    <tr>
                        <td style="padding:12px 0;color:#57534e;font-size:14px;border-top:1px solid #e7e5e4;">{{ __('affiliates::mail.commission_kind') }}</td>
                        <td style="padding:12px 0;text-align:right;font-size:14px;border-top:1px solid #e7e5e4;">{{ $kind }}</td>
                    </tr>
                    @if ($pending && $available)
                        <tr>
                            <td style="padding:12px 0;color:#57534e;font-size:14px;border-top:1px solid #e7e5e4;">{{ __('affiliates::mail.commission_available') }}</td>
                            <td style="padding:12px 0;text-align:right;font-size:14px;border-top:1px solid #e7e5e4;">{{ $available }}</td>
                        </tr>
                    @endif
                </table>

                <p style="margin:0;font-size:14px;line-height:1.5;color:#57534e;">{{ $pending ? __('affiliates::mail.commission_hold') : __('affiliates::mail.commission_payable') }}</p>
            </td>
        </tr>
    </table>
    <p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#78716c;text-align:center;">{{ __('affiliates::mail.footer') }}</p>
</body>
</html>
