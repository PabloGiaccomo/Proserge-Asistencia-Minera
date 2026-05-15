<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalFicha;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PersonalFichaEmailTemplateService
{
    private const SUBJECT_KEY = 'personal_ficha_email.subject';
    private const BODY_KEY = 'personal_ficha_email.body';

    public const DEFAULT_SUBJECT = '{{ tipo_envio }} de link para completar ficha - {{ documento }}';

    public const DEFAULT_BODY = "Hola {{ nombre }},\n\nSe ha habilitado un enlace temporal para que completes o regularices tu ficha de colaborador.\n\nDocumento: {{ documento }}\nVence: {{ vence }}\n\nIngresa desde este link:\n{{ link }}\n\nImportante: el enlace deja de estar disponible cuando vence o cuando el proceso se cierre.\n\nSaludos,\nEquipo Proserge";

    public function get(): array
    {
        $subject = self::DEFAULT_SUBJECT;
        $body = self::DEFAULT_BODY;

        if (Schema::hasTable('app_settings')) {
            $settings = DB::table('app_settings')
                ->whereIn('key', [self::SUBJECT_KEY, self::BODY_KEY])
                ->pluck('value', 'key');

            $subject = trim((string) ($settings[self::SUBJECT_KEY] ?? self::DEFAULT_SUBJECT)) ?: self::DEFAULT_SUBJECT;
            $body = trim((string) ($settings[self::BODY_KEY] ?? self::DEFAULT_BODY)) ?: self::DEFAULT_BODY;
        }

        if (!str_contains($body, '{{ link }}')) {
            $body .= "\n\n{{ link }}";
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'default_subject' => self::DEFAULT_SUBJECT,
            'default_body' => self::DEFAULT_BODY,
            'placeholders' => ['{{ nombre }}', '{{ documento }}', '{{ vence }}', '{{ link }}', '{{ tipo_envio }}'],
        ];
    }

    public function save(string $subject, string $body): array
    {
        $subject = trim($subject) ?: self::DEFAULT_SUBJECT;
        $body = trim($body) ?: self::DEFAULT_BODY;

        if (!Schema::hasTable('app_settings')) {
            throw new \RuntimeException('La tabla de configuracion no existe. Ejecuta las migraciones.');
        }

        $now = now();
        foreach ([self::SUBJECT_KEY => $subject, self::BODY_KEY => $body] as $key => $value) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        return $this->get();
    }

    public function renderSubject(PersonalFicha $ficha, bool $isResend = false): string
    {
        $template = $this->get()['subject'];
        $subject = $this->replacePlaceholders($template, $ficha, null, $isResend);
        $subject = trim(preg_replace('/\s+/', ' ', strip_tags($subject)) ?? '');

        return Str::limit($subject ?: self::DEFAULT_SUBJECT, 180, '');
    }

    public function renderBodyHtml(PersonalFicha $ficha, string $url, bool $isResend = false): string
    {
        $body = $this->get()['body'];
        $escaped = e($body);
        $values = $this->placeholderValues($ficha, $url, $isResend);
        $linkHtml = '<a href="' . e($url) . '" style="color:#0f62fe; font-weight:700; word-break:break-all;">' . e($url) . '</a>';

        $html = str_replace(
            ['{{ nombre }}', '{{ documento }}', '{{ vence }}', '{{ tipo_envio }}', '{{ link }}'],
            [e($values['nombre']), e($values['documento']), e($values['vence']), e($values['tipo_envio']), $linkHtml],
            $escaped,
        );

        return nl2br($html, false);
    }

    public function renderPlainText(PersonalFicha $ficha, string $url, bool $isResend = false): string
    {
        return $this->replacePlaceholders($this->get()['body'], $ficha, $url, $isResend);
    }

    private function replacePlaceholders(string $template, PersonalFicha $ficha, ?string $url, bool $isResend): string
    {
        $values = $this->placeholderValues($ficha, $url, $isResend);

        return str_replace(
            ['{{ nombre }}', '{{ documento }}', '{{ vence }}', '{{ tipo_envio }}', '{{ link }}'],
            [$values['nombre'], $values['documento'], $values['vence'], $values['tipo_envio'], $values['link']],
            $template,
        );
    }

    private function placeholderValues(PersonalFicha $ficha, ?string $url, bool $isResend): array
    {
        $ficha->loadMissing(['personal', 'link']);

        return [
            'nombre' => $ficha->personal?->nombre_completo ?: 'colaborador',
            'documento' => trim($ficha->tipo_documento . ' ' . $ficha->numero_documento),
            'vence' => optional($ficha->link?->expires_at)->format('d/m/Y H:i') ?: '-',
            'link' => $url ?: '{{ link }}',
            'tipo_envio' => $isResend ? 'Reenvio' : 'Envio',
        ];
    }
}
