<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 0; background: #f1f5f9; font-family: Arial, Helvetica, sans-serif; color: #1e293b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #f1f5f9; padding: 24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 560px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden;">
                    <tr>
                        <td style="background: #084586; padding: 18px 28px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    @if ($logoUrl)
                                        <td style="padding-right: 12px;">
                                            <img src="{{ $logoUrl }}" alt="" width="40" height="40" style="display: block; width: 40px; height: 40px; border-radius: 10px; background: #ffffff; object-fit: contain;">
                                        </td>
                                    @endif
                                    <td style="font-size: 17px; font-weight: bold; color: #ffffff;">{{ $organization }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px; font-size: 14px; line-height: 1.6;">
                            <h1 style="margin: 0 0 16px; font-size: 19px; line-height: 1.3; color: #0f172a;">{{ $title }}</h1>
                            @if ($greeting)
                                <p style="margin: 0 0 12px;">{{ $greeting }}</p>
                            @endif
                            @foreach ($lines as $line)
                                <p style="margin: 0 0 12px;">{{ $line }}</p>
                            @endforeach
                            @if ($action)
                                <p style="margin: 20px 0 8px;">
                                    <a href="{{ $action['url'] }}" style="display: inline-block; padding: 11px 20px; background: #084586; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: bold;">{{ $action['label'] }}</a>
                                </p>
                                <p style="margin: 12px 0 0; color: #64748b; font-size: 12px;">Si el botón no funciona, copia esta dirección en tu navegador:<br>{{ $action['url'] }}</p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px; background: #f8fafc; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 12px; line-height: 1.5;">
                            Este mensaje lo envía {{ $organization }} de forma automática; no hace falta responderlo.
                            @if (! empty($unsubscribe))
                                <br><a href="{{ $unsubscribe['url'] }}" style="color: #475569;">{{ $unsubscribe['label'] }}</a>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
