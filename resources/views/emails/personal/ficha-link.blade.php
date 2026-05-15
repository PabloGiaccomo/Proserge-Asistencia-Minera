@php
    $bodyHtml = $bodyHtml ?? app(\App\Modules\Personal\Services\PersonalFichaEmailTemplateService::class)->renderBodyHtml($ficha, $url, $isResend ?? false);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha de colaborador</title>
</head>
<body style="margin:0; padding:24px; background:#f4f7fb; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px; margin:0 auto; background:#ffffff; border:1px solid #dbe3ef;">
        <tr>
            <td style="padding:24px 28px; border-bottom:1px solid #e2e8f0;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                    <tr>
                        <td style="vertical-align:middle;">
                            <img src="{{ asset('img/LogoProserge.jpg') }}" alt="Proserge" style="height:48px; display:block;">
                        </td>
                        <td style="text-align:right; vertical-align:middle; color:#475569; font-size:13px;">
                            Sistemas Proserge
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding:28px;">
                <div style="margin:0 0 20px; font-size:15px; line-height:1.7;">
                    {!! $bodyHtml !!}
                </div>
                <p style="margin:0; font-size:14px; line-height:1.6; color:#475569;">
                    Este mensaje fue enviado desde <strong>sistemas@proserge.com</strong> para el proceso interno de Proserge.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
