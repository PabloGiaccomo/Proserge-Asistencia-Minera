@php
    $personal = $ficha->personal;
    $nombre = $personal?->nombre_completo ?: 'colaborador';
    $documento = trim($ficha->tipo_documento . ' ' . $ficha->numero_documento);
    $vence = optional($ficha->link?->expires_at)->format('d/m/Y H:i');
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
                <h1 style="margin:0 0 12px; font-size:24px; line-height:1.25;">Completa tu ficha de colaborador</h1>
                <p style="margin:0 0 16px; font-size:15px; line-height:1.6;">
                    Hola {{ $nombre }}, se ha habilitado un enlace temporal para que completes o regularices tu ficha de colaborador.
                </p>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px; background:#f8fafc; border:1px solid #e2e8f0;">
                    <tr>
                        <td style="padding:16px 18px; font-size:14px; line-height:1.7;">
                            <strong>Documento:</strong> {{ $documento }}<br>
                            @if($vence)
                                <strong>Vence:</strong> {{ $vence }}<br>
                            @endif
                            <strong>Importante:</strong> El enlace deja de estar disponible cuando vence o cuando el proceso se cierre.
                        </td>
                    </tr>
                </table>

                <div style="margin:0 0 20px; text-align:center;">
                    <a href="{{ $url }}" style="display:inline-block; padding:14px 22px; background:#0f62fe; color:#ffffff; text-decoration:none; font-size:15px; font-weight:700;">
                        Abrir ficha
                    </a>
                </div>

                <p style="margin:0 0 8px; font-size:14px; line-height:1.6;">
                    Si el boton no funciona, copia y pega este enlace en tu navegador:
                </p>
                <p style="margin:0 0 18px; font-size:13px; line-height:1.7; word-break:break-all; color:#1d4ed8;">
                    {{ $url }}
                </p>

                <p style="margin:0; font-size:14px; line-height:1.6; color:#475569;">
                    Este mensaje fue enviado desde <strong>sistemas@proserge.com</strong> para el proceso interno de Proserge.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
