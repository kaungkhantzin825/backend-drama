<!doctype html>
<html>
<body style="margin:0;padding:24px;background:#f4f4f6;font-family:Arial,Helvetica,sans-serif;color:#1d1d24;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;">
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 4px;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:1px;">DramaLoop feedback</p>
                <h1 style="margin:0 0 16px;font-size:20px;">{{ $feedback->categoryLabel() }}</h1>

                <p style="margin:0 0 20px;font-size:15px;line-height:1.5;white-space:pre-wrap;">{{ $feedback->message }}</p>

                <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:13px;color:#555;">
                    <tr><td style="padding:2px 12px 2px 0;">From</td><td>{{ $feedback->user?->name ?? 'Guest' }} (user #{{ $feedback->user_id ?? '—' }})</td></tr>
                    <tr><td style="padding:2px 12px 2px 0;">Contact</td><td>{{ $feedback->contact ?: '—' }}</td></tr>
                    <tr><td style="padding:2px 12px 2px 0;">App</td><td>{{ $feedback->platform ?: '—' }} {{ $feedback->app_version }}</td></tr>
                    <tr><td style="padding:2px 12px 2px 0;">Sent</td><td>{{ $feedback->created_at?->toDayDateTimeString() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
