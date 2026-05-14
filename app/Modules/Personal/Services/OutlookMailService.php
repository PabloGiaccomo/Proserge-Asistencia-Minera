<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalFicha;
use Illuminate\Validation\ValidationException;

class OutlookMailService
{
    private readonly string $scriptPath;

    public function __construct(
        private readonly PersonalFichaService $fichaService,
    ) {
        $this->scriptPath = storage_path('scripts/send-outlook-email.ps1');
    }

    public function send(PersonalFicha $ficha): array
    {
        $ficha->loadMissing(['personal', 'link']);
        $link = $ficha->link;

        if (!$link) {
            throw ValidationException::withMessages([
                'ficha' => 'La ficha no tiene un link disponible.',
            ]);
        }

        $email = $this->fichaService->resolvedFichaEmail($ficha);
        if (!$email) {
            throw ValidationException::withMessages([
                'correo' => 'No se encontro un correo valido para este trabajador.',
            ]);
        }

        $url = $this->fichaService->publicUrlForLink($link);
        if (!$url) {
            throw ValidationException::withMessages([
                'ficha' => 'No se pudo reconstruir el link temporal.',
            ]);
        }

        $wasResent = $link->emailed_at !== null;

        $documento = trim($ficha->tipo_documento . ' ' . $ficha->numero_documento);
        $nombre = $ficha->personal?->nombre_completo ?? 'Trabajador';

        $subject = ($wasResent ? 'Reenvio' : 'Envio') . ' de link para completar ficha - ' . $documento;

        $body = "Hola " . $nombre . ",\r\n\r\n"
            . "Se te ha generado un enlace para completar tu ficha de personal en el sistema Proserge.\r\n\r\n"
            . "Documento: " . $documento . "\r\n"
            . "Enlace: " . $url . "\r\n\r\n"
            . "** IMPORTANTE: Para una mejor experiencia, abre este enlace desde una computadora o PC. **\r\n\r\n"
            . "Ingresa al enlace para completar tus datos.\r\n\r\n"
            . "Saludos,\r\n"
            . "Equipo Proserge";

        $htmlBody = $this->buildHtmlBody($nombre, $documento, $url, $wasResent);

        $delivery = $this->deliver($email, $subject, $body, $htmlBody);

        $link->forceFill([
            'emailed_at' => now(),
            'emailed_to' => $email,
        ])->save();

        return [
            'email' => $email,
            'resent' => $wasResent,
            'link' => $link->fresh(),
            'delivery' => $delivery['mode'],
            'mailto_url' => $delivery['mailto_url'] ?? null,
        ];
    }

    private function deliver(string $to, string $subject, string $body, ?string $htmlBody = null): array
    {
        if ($this->canUseWindowsOutlook()) {
            $this->sendViaOutlook($to, $subject, $body, $htmlBody);

            return [
                'mode' => 'outlook',
            ];
        }

        return [
            'mode' => 'mailto',
            'mailto_url' => $this->buildMailtoUrl($to, $subject, $body),
        ];
    }

    private function canUseWindowsOutlook(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        return file_exists($this->scriptPath);
    }

    private function sendViaOutlook(string $to, string $subject, string $body, ?string $htmlBody = null): void
    {
        $escapedTo = escapeshellarg($to);
        $subjectBase64 = base64_encode($subject);
        $bodyBase64 = base64_encode($body);
        $htmlBodyBase64 = $htmlBody !== null ? base64_encode($htmlBody) : '';
        $powershell = $this->powershellBinary();

        $command = sprintf(
            '%s -ExecutionPolicy Bypass -File %s -To %s -SubjectBase64 %s -BodyBase64 %s -HtmlBodyBase64 %s 2>&1',
            $powershell,
            escapeshellarg($this->scriptPath),
            $escapedTo,
            $subjectBase64,
            $bodyBase64,
            escapeshellarg($htmlBodyBase64),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $errorMsg = !empty($output) ? implode('; ', array_slice($output, 0, 3)) : 'Error desconocido';
            throw ValidationException::withMessages([
                'correo' => $errorMsg,
            ]);
        }
    }

    private function powershellBinary(): string
    {
        foreach (['powershell.exe', 'powershell', 'pwsh.exe', 'pwsh'] as $candidate) {
            $result = shell_exec('where ' . escapeshellarg($candidate) . ' 2>NUL');
            if (is_string($result) && trim($result) !== '') {
                return $candidate;
            }
        }

        throw ValidationException::withMessages([
            'correo' => 'No se encontro PowerShell en esta computadora para abrir Outlook.',
        ]);
    }

    private function buildMailtoUrl(string $to, string $subject, string $body): string
    {
        $query = http_build_query([
            'subject' => $subject,
            'body' => $body,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'mailto:' . rawurlencode($to) . '?' . $query;
    }

    private function buildHtmlBody(string $nombre, string $documento, string $url, bool $wasResent): string
    {
        $titulo = $wasResent ? 'Reenvio de enlace temporal' : 'Enlace temporal para completar ficha';
        $saludo = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $documentoHtml = htmlspecialchars($documento, ENT_QUOTES, 'UTF-8');
        $urlHtml = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{$titulo}</title>
</head>
<body style="margin:0; padding:24px; background:#f4f7fb; font-family:Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px; margin:0 auto; background:#ffffff; border:1px solid #dbe3ef;">
        <tr>
            <td style="padding:18px 24px; background:#0f172a; color:#ffffff;">
                <div style="font-size:12px; letter-spacing:.08em; text-transform:uppercase; opacity:.9;">Proserge</div>
                <div style="margin-top:6px; font-size:24px; font-weight:700;">{$titulo}</div>
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 14px; font-size:15px; line-height:1.7;">Hola {$saludo},</p>
                <p style="margin:0 0 16px; font-size:15px; line-height:1.7;">
                    Se ha generado un enlace temporal para que completes tu ficha de personal en el sistema Proserge.
                </p>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 20px; background:#f8fafc; border:1px solid #e2e8f0;">
                    <tr>
                        <td style="padding:14px 16px; font-size:14px; line-height:1.7;">
                            <strong>Documento:</strong> {$documentoHtml}<br>
                            <strong>Enlace:</strong> <a href="{$urlHtml}" style="color:#0f62fe; text-decoration:none;">Abrir ficha</a>
                        </td>
                    </tr>
                </table>
                <p style="margin:0 0 16px; font-size:14px; line-height:1.7;">
                    Importante: para una mejor experiencia, abre este enlace desde una computadora o laptop.
                </p>
                <div style="margin:0 0 20px;">
                    <a href="{$urlHtml}" style="display:inline-block; padding:12px 18px; background:#0f62fe; color:#ffffff; text-decoration:none; font-size:14px; font-weight:700;">
                        Completar ficha
                    </a>
                </div>
                <p style="margin:0 0 8px; font-size:13px; color:#475569;">Si el boton no funciona, copia y pega este enlace en tu navegador:</p>
                <p style="margin:0 0 18px; font-size:13px; line-height:1.7; word-break:break-all; color:#1d4ed8;">{$urlHtml}</p>
                <p style="margin:0; font-size:14px; line-height:1.7;">Saludos,<br><strong>Equipo Proserge</strong></p>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}
