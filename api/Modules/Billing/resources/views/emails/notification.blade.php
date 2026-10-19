<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:24px;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:24px;">
                <h1 style="margin:0 0 12px;font-size:18px;">{{ $title }}</h1>
                <p style="margin:0 0 20px;font-size:14px;line-height:1.5;white-space:pre-line;">{{ $text }}</p>
                @if ($actionUrl)
                    <p style="margin:0;">
                        <a href="{{ $actionUrl }}" style="display:inline-block;padding:10px 16px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;">{{ __('billing.notify.open') }}</a>
                    </p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
