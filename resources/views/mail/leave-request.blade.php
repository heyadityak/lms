<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{{ $brand->name }}</title></head>
<body style="margin:0;padding:24px;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#1f1f1f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;">
    <tr><td style="background:{{ $brand->primary }};color:{{ $brand->contrast }};padding:16px 24px;font-size:18px;font-weight:bold;">{{ $brand->name }}</td></tr>
    <tr><td style="padding:24px;font-size:15px;line-height:1.5;">
        <p style="margin:0 0 16px;">Hello {{ $name }},</p>
        @foreach ($lines as $line)
            <p style="margin:0 0 12px;">{{ $line }}</p>
        @endforeach
        <p style="margin:24px 0;"><a href="{{ $url }}" style="background:{{ $brand->primary }};color:{{ $brand->contrast }};padding:10px 18px;text-decoration:none;border-radius:4px;display:inline-block;">{{ $action }}</a></p>
        <p style="margin:0;font-size:13px;color:#595959;">Or open {{ $url }}</p>
    </td></tr>
</table>
</body>
</html>
