<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('affiliates::mail.'.$type.'_heading') }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1c1917;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:32px;">
                <p style="margin:0 0 16px;font-size:16px;">{{ __('affiliates::mail.greeting', ['name' => $partner->name]) }}</p>
                <p style="margin:0 0 24px;font-size:16px;line-height:1.5;">{{ __('affiliates::mail.'.$type.'_intro', ['site' => config('app.name')]) }}</p>
                <p style="margin:0 0 24px;">
                    <a href="{{ $url }}" style="display:inline-block;padding:12px 20px;background:#1c1917;color:#ffffff;text-decoration:none;border-radius:6px;font-size:15px;">{{ __('affiliates::mail.'.$type.'_button') }}</a>
                </p>
                <p style="margin:0;font-size:13px;line-height:1.5;color:#78716c;word-break:break-all;">{{ $url }}</p>
            </td>
        </tr>
    </table>
    <p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#78716c;text-align:center;">{{ __('affiliates::mail.footer') }}</p>
</body>
</html>
