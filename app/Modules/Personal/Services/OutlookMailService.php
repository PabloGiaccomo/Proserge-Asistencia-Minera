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

        $this->sendViaOutlook($email, $subject, $body);

        $link->forceFill([
            'emailed_at' => now(),
            'emailed_to' => $email,
        ])->save();

        return [
            'email' => $email,
            'resent' => $wasResent,
            'link' => $link->fresh(),
        ];
    }

    private function sendViaOutlook(string $to, string $subject, string $body): void
    {
        if (!file_exists($this->scriptPath)) {
            throw ValidationException::withMessages([
                'correo' => 'El script de Outlook no se encuentra en el servidor.',
            ]);
        }

        $escapedTo = escapeshellarg($to);
        $subjectBase64 = base64_encode($subject);
        $bodyBase64 = base64_encode($body);

        $command = sprintf(
            'powershell -ExecutionPolicy Bypass -File %s -To %s -SubjectBase64 %s -BodyBase64 %s 2>&1',
            escapeshellarg($this->scriptPath),
            $escapedTo,
            $subjectBase64,
            $bodyBase64,
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
}
