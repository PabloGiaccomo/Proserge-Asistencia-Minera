<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalFicha;
use Illuminate\Validation\ValidationException;

class OutlookMailService
{
    private readonly string $scriptPath;

    public function __construct(
        private readonly PersonalFichaService $fichaService,
        private readonly PersonalFichaEmailTemplateService $emailTemplateService,
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

        $subject = $this->emailTemplateService->renderSubject($ficha, $wasResent);
        $body = $this->emailTemplateService->renderPlainText($ficha, $url, $wasResent);
        $htmlBody = $this->buildHtmlBody($ficha, $url, $wasResent);

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

    private function buildHtmlBody(PersonalFicha $ficha, string $url, bool $wasResent): string
    {
        $titulo = $wasResent ? 'Reenvio de enlace temporal' : 'Enlace temporal para completar ficha';
        $bodyHtml = $this->emailTemplateService->renderBodyHtml($ficha, $url, $wasResent);

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
                <div style="margin:0 0 18px; font-size:15px; line-height:1.7;">{$bodyHtml}</div>
                <p style="margin:0; font-size:14px; line-height:1.7;">Saludos,<br><strong>Equipo Proserge</strong></p>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}
