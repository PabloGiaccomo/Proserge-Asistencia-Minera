<?php

namespace App\Modules\Personal\Services;

use App\Models\PersonalFicha;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

class PersonalFichaPdfService
{
    public function __construct(private readonly PersonalFichaService $fichaService)
    {
    }

    public function download(PersonalFicha $ficha): Response
    {
        if (!class_exists(Dompdf::class)) {
            return response($this->output($ficha), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }

        $name = $this->filename($ficha);

        return response($this->output($ficha), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    public function output(PersonalFicha $ficha): string
    {
        $ficha->loadMissing(['personal', 'familiares']);

        if ($this->canUsePdfTemplate()) {
            return $this->outputFromTemplate($ficha);
        }

        $html = view('personal.fichas.pdf', [
            'ficha' => $ficha,
            'data' => $this->fichaService->normalizeFichaData($ficha->datos_json ?? []),
            'familiares' => $ficha->familiares,
            'firmaBase64' => $ficha->firma_base64,
            'huellaDataUrl' => $this->fichaService->imageDataUrl($ficha->huella_path),
        ])->render();

        if (!class_exists(Dompdf::class)) {
            return $html;
        }

        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function canUsePdfTemplate(): bool
    {
        return class_exists(Fpdi::class)
            && is_file($this->templatePath());
    }

    private function outputFromTemplate(PersonalFicha $ficha): string
    {
        $data = $this->fichaService->normalizeFichaData($ficha->datos_json ?? []);
        $familiares = $ficha->familiares;

        $text = static function (string $key, string $default = '') use ($data): string {
            $value = trim((string) ($data[$key] ?? ''));
            return $value !== '' ? $value : $default;
        };

        $fullName = trim(collect([
            $text('apellido_paterno'),
            $text('apellido_materno'),
            $text('nombres'),
        ])->filter()->implode(' '));
        $fullName = $fullName !== '' ? $fullName : ($ficha->personal?->nombre_completo ?: '');

        $documentType = $ficha->tipo_documento ?: $text('tipo_documento', 'DNI');
        $documentNumber = $ficha->numero_documento ?: $text('numero_documento');
        $submittedDate = optional($ficha->submitted_at ?? $ficha->created_at)->format('d/m/Y') ?: now()->format('d/m/Y');

        $birthDisplay = '';
        $age = '';
        if ($text('fecha_nacimiento') !== '') {
            try {
                $birth = Carbon::parse($text('fecha_nacimiento'));
                $birthDisplay = $birth->format('d/m/Y');
                $age = (string) $birth->age;
            } catch (\Throwable) {
                $birthDisplay = $text('fecha_nacimiento');
            }
        }

        $entry = null;
        if ($text('fecha_ingreso') !== '') {
            try {
                $entry = Carbon::parse($text('fecha_ingreso'));
            } catch (\Throwable) {
                $entry = null;
            }
        }

        $orderedRelatives = collect(['Padre', 'Madre', 'Coyugue', 'Hijo 1', 'Hijo 2', 'Hijo 3', 'Hijo 4', 'Hijo 5'])
            ->map(function (string $label) use ($familiares): array {
                $match = $familiares->first(fn ($item): bool => strcasecmp((string) $item->parentesco, $label) === 0);

                return [
                    'name' => $match?->nombres_apellidos ?: '',
                    'date' => optional($match?->fecha_nacimiento)->format('d/m/Y') ?: '',
                    'vive' => $match ? ($match->vive_con_trabajador ? 'SI' : 'NO') : '',
                    'phone' => $match?->telefono ?: '',
                ];
            })->values();

        try {
            $otherEmployers = json_decode((string) ($data['quinta_otros_empleadores_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            $otherEmployers = is_array($otherEmployers) ? array_values($otherEmployers) : [];
        } catch (\Throwable) {
            $otherEmployers = [];
        }

        $city = $text('quinta_ciudad', $text('domicilio_provincia', 'Arequipa'));
        $quintaDay = $text('quinta_fecha_dia', now()->format('d'));
        $quintaMonth = $text('quinta_fecha_mes', now()->locale('es')->translatedFormat('F'));
        $quintaYear = $text('quinta_fecha_anio', now()->format('Y'));
        $isPrivatePension = Str::contains(Str::upper($text('sistema_pensionario')), 'PRIVADO');

        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pageCount = $pdf->setSourceFile($this->templatePath());
        $tempImages = [];

        try {
            for ($page = 1; $page <= $pageCount; $page++) {
                $templateId = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($templateId);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height']);

                $scaleX = $size['width'] / 935;
                $scaleY = $size['height'] / 1210;
                $field = function (
                    float $x,
                    float $y,
                    ?float $width,
                    string $value,
                    float $font = 10,
                    string $align = 'L',
                    bool $bold = false,
                    ?float $height = null
                ) use ($pdf, $scaleX, $scaleY): void {
                    $value = trim($value);
                    if ($value === '') {
                        return;
                    }

                    $style = $bold ? 'B' : '';
                    $encodedValue = $this->pdfText($value);
                    $pdf->SetFont('Times', $style, $font);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetFillColor(255, 255, 0);

                    $fieldHeight = ($height !== null ? $height * $scaleY : max($font * 1.18, 9));
                    $fieldWidth = ($width ?? 80) * $scaleX;
                    $fitFont = $font;
                    while ($fitFont > 6 && $pdf->GetStringWidth($encodedValue) > ($fieldWidth - 2)) {
                        $fitFont -= 0.5;
                        $pdf->SetFont('Times', $style, $fitFont);
                    }

                    $pdf->SetXY($x * $scaleX, $y * $scaleY);
                    $pdf->Cell($fieldWidth, $fieldHeight, $encodedValue, 0, 0, $align, true);
                };

                if ($page === 1) {
                    $field(596, 156, 82, $submittedDate, 10, 'C');
                    $field(218, 227, 85, $documentNumber, 11);
                    $field(218, 252, 85, $age, 11);
                    $field(126, 294, 140, $birthDisplay, 11, 'C');
                    $field(93, 327, 120, Str::upper($text('sexo')), 9);
                    $field(205, 327, 75, Str::upper($text('grupo_sanguineo')), 9, 'C');
                    $field(82, 387, 160, $text('telefono_alterno'), 10);
                    $field(82, 414, 160, $text('telefono'), 10);
                    $field(195, 435, 160, Str::upper($text('brevete')), 9);
                    $field(363, 220, 170, Str::upper($text('apellido_paterno')), 11, 'C');
                    $field(549, 220, 170, Str::upper($text('apellido_materno')), 11, 'C');
                    $field(723, 220, 160, Str::upper($text('nombres')), 11, 'C');
                    $field(524, 257, 290, Str::upper($text('domicilio_direccion', $text('domicilio_extranjero'))), 9);
                    $field(300, 286, 130, Str::upper($text('domicilio_distrito')), 10);
                    $field(492, 286, 120, Str::upper($text('domicilio_provincia')), 10);
                    $field(695, 286, 120, Str::upper($text('domicilio_departamento')), 10);
                    $field(491, 315, 240, $text('correo'), 9);
                    $field(301, 344, 145, Str::upper($text('nacionalidad')), 10);
                    $field(491, 344, 165, Str::upper($text('estado_civil')), 10);
                    $field(300, 385, 145, Str::upper($text('distrito_nacimiento')), 10);
                    $field(492, 385, 120, Str::upper($text('provincia_nacimiento')), 10);
                    $field(693, 385, 120, Str::upper($text('departamento_nacimiento')), 10);
                    $field(292, 412, 150, $text('numero_cuenta'), 9);
                    $field(691, 412, 120, Str::upper($text('banco')), 9);
                    $field(291, 434, 150, $text('cci'), 9);

                    $firstRelative = $familiares->first();
                    $field(282, 466, 205, Str::upper($firstRelative?->nombres_apellidos ?? ''), 8);
                    $field(720, 466, 85, $firstRelative?->telefono ?? '', 9);
                    $field(281, 490, 120, Str::upper($firstRelative?->parentesco ?? ''), 8);
                    $field(720, 490, 85, $firstRelative?->telefono ?? '', 9);

                    $field(188, 543, 60, $text('talla_zapato'), 10, 'C');
                    $field(336, 543, 55, Str::upper($text('talla_polo')), 10, 'C');
                    $field(528, 543, 60, $text('talla_pantalon'), 10, 'C');
                    $field(795, 543, 40, Str::upper($text('talla_respirador')), 10, 'C');
                    $field(289, 593, 160, Str::upper($text('grado_instruccion')), 9);
                    $field(693, 593, 145, Str::upper($text('carrera')), 9);
                    $field(289, 614, 180, Str::upper($text('profesion_oficio')), 9);
                    $field(693, 614, 145, Str::upper($text('institucion')), 9);
                    $field(289, 634, 90, $text('anio_egreso'), 9);
                    $field(454, 675, 160, Str::upper($text('especialidad')), 9, 'C');
                    $field(800, 675, 140, Str::upper($text('puesto')), 9, 'C');
                    $field(458, 696, 120, Str::upper($text('anio_experiencia')), 9, 'C');
                    $field(804, 696, 120, Str::upper($text('categoria_trabajador')), 9, 'C');
                    $field(248, 747, 210, Str::upper($text('sistema_pensionario')), 9);
                    $field(775, 747, 110, Str::upper($text('tipo_afp')), 9, 'C');
                    $field(250, 770, 110, Str::upper($text('tipo_comision')), 9);
                    $field(766, 770, 130, Str::upper($text('cuspp')), 8, 'C');

                    foreach ($orderedRelatives as $index => $relative) {
                        $baseY = 822 + ($index * 22);
                        $field(175, $baseY, 245, Str::upper($relative['name']), 7.8);
                        $field(421, $baseY, 150, $relative['date'], 7.8);
                        $field(663, $baseY, 70, $relative['vive'], 7.8, 'C');
                        $field(806, $baseY, 110, $relative['phone'], 7.8);
                    }

                    $field(813, 955, 40, 'SI', 10, 'C', true);
                    $this->drawImageData($pdf, $ficha->firma_base64, 144, 1007, 185, 55, $scaleX, $scaleY, $tempImages);
                    $this->drawImageData($pdf, $this->fichaService->imageDataUrl($ficha->huella_path), 429, 995, 130, 70, $scaleX, $scaleY, $tempImages);
                }

                if ($page === 2) {
                    $field(353, 228, 260, Str::upper($fullName), 9.5);
                    $this->drawImageData($pdf, $ficha->firma_base64, 145, 724, 190, 48, $scaleX, $scaleY, $tempImages);
                }

                if ($page === 3) {
                    $field(304, 196, 140, Str::upper($text('apellido_paterno')), 9);
                    $field(304, 224, 140, Str::upper($text('apellido_materno')), 9);
                    $field(304, 253, 220, Str::upper($text('nombres')), 9);
                    $field(509, 282, 90, $documentNumber, 9);
                    $field(356, 371, 28, Str::startsWith(Str::upper($text('sexo')), 'F') ? '' : 'X', 10, 'C', true);
                    $field(409, 371, 28, Str::startsWith(Str::upper($text('sexo')), 'F') ? 'X' : '', 10, 'C', true);
                    $field(443, 476, 110, Str::upper($text('domicilio_distrito')), 9);
                    $field(443, 499, 110, Str::upper($text('domicilio_provincia')), 9);
                    $field(491, 523, 120, Str::upper($text('domicilio_departamento')), 9);
                    if ($entry) {
                        $field(492, 726, 28, $entry->format('d'), 9, 'C');
                        $field(632, 726, 42, $entry->format('m'), 9, 'C');
                        $field(785, 726, 52, $entry->format('Y'), 9, 'C');
                    }
                    $field(722, 852, 58, $isPrivatePension ? 'X' : '', 11, 'C', true);
                    $field(289, 964, 320, $city, 9);
                    $field(454, 964, 60, $quintaDay, 9);
                    $field(578, 964, 95, $quintaMonth, 9);
                    $field(722, 964, 50, $quintaYear, 9);
                    $this->drawImageData($pdf, $ficha->firma_base64, 384, 884, 180, 60, $scaleX, $scaleY, $tempImages);
                }

                if ($page === 4) {
                    $field(274, 807, 210, Str::upper($fullName), 9);
                    $field(410, 854, 100, $documentNumber, 9);
                    $field(169, 1026, 110, $city, 9);
                    $field(319, 1026, 90, $quintaDay, 9);
                    $field(469, 1026, 110, $quintaMonth, 9);
                    $field(625, 1026, 85, $quintaYear, 9);
                }

                if ($page === 5) {
                    $employer = $otherEmployers[0] ?? [];
                    $field(310, 168, 478, Str::upper($fullName), 9);
                    $field(225, 191, 560, Str::upper($text('quinta_domicilio', $text('domicilio_direccion'))), 9);
                    $field(152, 366, 20, Str::lower($text('quinta_percibe_otras')) === 'si' ? 'X' : '', 12, 'C', true);
                    $field(152, 474, 20, Str::lower($text('quinta_adjunta_dj_anterior')) === 'si' ? 'X' : '', 12, 'C', true);
                    $field(177, 694, 190, Str::upper((string) ($employer['razon_social'] ?? $text('quinta_otra_empresa'))), 9);
                    $field(411, 694, 72, (string) ($employer['ruc'] ?? $text('quinta_otra_empresa_ruc')), 9, 'C');
                    $field(530, 694, 75, (string) ($employer['monto_anual'] ?? ''), 9, 'C');
                    $field(705, 694, 90, (string) ($employer['retencion'] ?? ''), 9, 'C');
                    $field(507, 854, 120, $city, 9);
                    $field(603, 854, 55, $quintaDay, 9);
                    $field(684, 854, 120, $quintaMonth, 9);
                    $field(842, 854, 70, $quintaYear, 9);
                    $this->drawImageData($pdf, $ficha->firma_base64, 425, 906, 170, 46, $scaleX, $scaleY, $tempImages);
                    $field(392, 966, 170, $documentNumber, 9);
                }

                if ($page === 6) {
                    $field(171, 322, 310, Str::upper($fullName), 11);
                    $field(774, 322, 120, $documentType, 11);
                    $field(129, 370, 110, $documentNumber, 11);
                    $field(381, 369, 471, Str::upper($text('domicilio_direccion', $text('domicilio_extranjero'))), 11);
                    $field(354, 881, 180, $city, 10);
                    $field(590, 881, 50, $quintaDay, 10);
                    $field(725, 881, 100, $quintaMonth, 10);
                    $field(838, 881, 90, $quintaYear, 10);
                    $this->drawImageData($pdf, $this->fichaService->imageDataUrl($ficha->huella_path), 134, 885, 98, 116, $scaleX, $scaleY, $tempImages);
                    $this->drawImageData($pdf, $ficha->firma_base64, 570, 989, 190, 54, $scaleX, $scaleY, $tempImages);
                }

                if ($page === 7) {
                    $field(309, 187, 260, Str::upper($fullName), 11);
                    $field(194, 219, 110, $documentNumber, 9);
                    $field(189, 236, 245, Str::upper($text('domicilio_direccion', $text('domicilio_extranjero'))), 8);
                    $this->drawImageData($pdf, $ficha->firma_base64, 660, 195, 110, 80, $scaleX, $scaleY, $tempImages);
                    $this->drawImageData($pdf, $this->fichaService->imageDataUrl($ficha->huella_path), 783, 195, 90, 80, $scaleX, $scaleY, $tempImages);
                }
            }

            return $pdf->Output('S');
        } finally {
            foreach ($tempImages as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function templatePath(): string
    {
        return resource_path('pdf-templates/ficha-colaborador-vacio-fpdi.pdf');
    }

    private function pdfText(string $value): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);

        return $converted !== false ? $converted : utf8_decode($value);
    }

    private function drawImageData(
        Fpdi $pdf,
        ?string $imageData,
        float $x,
        float $y,
        float $width,
        float $height,
        float $scaleX,
        float $scaleY,
        array &$tempImages
    ): void {
        $imageData = trim((string) $imageData);
        if ($imageData === '') {
            return;
        }

        $extension = 'png';
        $base64 = $imageData;
        if (Str::startsWith($imageData, 'data:')) {
            [$meta, $base64] = explode(',', $imageData, 2) + ['', ''];
            $extension = Str::contains($meta, 'jpeg') || Str::contains($meta, 'jpg') ? 'jpg' : 'png';
        }

        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            return;
        }

        $directory = storage_path('framework/cache/ficha-pdf');
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory . DIRECTORY_SEPARATOR . Str::uuid() . '.' . $extension;
        file_put_contents($path, $binary);
        $tempImages[] = $path;

        $pdf->Image($path, $x * $scaleX, $y * $scaleY, $width * $scaleX, $height * $scaleY);
    }

    public function filename(PersonalFicha $ficha): string
    {
        return 'ficha_colaborador_' . Str::slug($ficha->personal?->nombre_completo ?: $ficha->numero_documento) . '.pdf';
    }
}
