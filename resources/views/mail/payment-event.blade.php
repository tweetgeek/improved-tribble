<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>{{ $notification->subject }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; margin: 0; padding: 24px; background: #f9fafb;">
    @if ($notification->isTest)
        <p style="background: #fef3c7; border: 1px solid #f59e0b; color: #92400e; padding: 12px; font-weight: bold; margin: 0 0 16px;">
            ZDARZENIE TESTOWE — nie dotyczy rzeczywistej płatności.
        </p>
    @endif

    <h1 style="font-size: 18px; margin: 0 0 16px;">{{ $notification->subject }}</h1>

    <table cellpadding="8" cellspacing="0" style="border-collapse: collapse; background: #ffffff; border: 1px solid #e5e7eb;">
        @foreach ($notification->fields as $field)
            <tr>
                <th align="left" style="border-bottom: 1px solid #e5e7eb; background: #f3f4f6; font-weight: 600;">{{ $field['label'] }}</th>
                <td style="border-bottom: 1px solid #e5e7eb;">{{ $field['value'] }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
