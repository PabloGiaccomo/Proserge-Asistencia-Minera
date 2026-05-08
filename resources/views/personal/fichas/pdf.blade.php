@php
    use Carbon\Carbon;
    use Illuminate\Support\Str;

    $text = function (string $key, string $default = '') use ($data): string {
        $value = trim((string) ($data[$key] ?? ''));
        return $value !== '' ? $value : $default;
    };

    $assetUri = function (string $relativePath): ?string {
        $path = public_path($relativePath);
        if (!is_file($path)) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
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

    $birthDate = $text('fecha_nacimiento');
    $birthDisplay = '';
    $age = '';
    if ($birthDate !== '') {
        try {
            $birth = Carbon::parse($birthDate);
            $birthDisplay = $birth->format('d/m/Y');
            $age = (string) $birth->age;
        } catch (\Throwable) {
            $birthDisplay = $birthDate;
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
        ->map(function (string $label) use ($familiares) {
            $match = $familiares->first(function ($item) use ($label) {
                return strcasecmp((string) $item->parentesco, $label) === 0;
            });

            return [
                'label' => $label,
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

    $pageBg = [];
    foreach (range(1, 7) as $pageNumber) {
        $pageBg[$pageNumber] = $assetUri('pdf-templates/ficha-colaborador/page_' . $pageNumber . '_clean.png')
            ?: $assetUri('pdf-templates/ficha-colaborador/page_' . $pageNumber . '.png');
    }

    $logoUri = null;
    $logoPath = base_path('img/LogoProserge.jpg');
    if (is_file($logoPath)) {
        $logoUri = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($logoPath));
    }

    $firmaUri = null;
    if (is_string($firmaBase64) && trim($firmaBase64) !== '') {
        $firmaUri = Str::startsWith($firmaBase64, 'data:') ? $firmaBase64 : 'data:image/png;base64,' . $firmaBase64;
    }

    $huellaUri = $huellaDataUrl ?: null;

    $city = $text('quinta_ciudad', $text('domicilio_provincia', 'Arequipa'));
    $quintaDay = $text('quinta_fecha_dia', now()->format('d'));
    $quintaMonth = $text('quinta_fecha_mes', now()->locale('es')->translatedFormat('F'));
    $quintaYear = $text('quinta_fecha_anio', now()->format('Y'));

    $pix = static fn (float $value): string => round($value * (612 / 935), 2) . 'pt';

    $fieldStyle = static function (
        float $x,
        float $y,
        ?float $width = null,
        ?float $height = null,
        float $font = 10,
        string $weight = 'normal',
        string $align = 'left',
        string $extra = ''
    ) use ($pix): string {
        $style = [
            'left:' . $pix($x),
            'top:' . $pix($y),
            'font-size:' . $font . 'pt',
            'font-weight:' . $weight,
            'text-align:' . $align,
        ];

        if ($width !== null) {
            $style[] = 'width:' . $pix($width);
        }

        if ($height !== null) {
            $style[] = 'height:' . $pix($height);
        }

        if ($extra !== '') {
            $style[] = $extra;
        }

        return implode(';', $style);
    };

    $pensionLabel = Str::upper($text('sistema_pensionario'));
    $isPrivatePension = Str::contains($pensionLabel, 'PRIVADO');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page { size: letter portrait; margin: 0; }
        html, body { margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; color: #000; }
        .page { position: relative; width: 612pt; height: 792pt; page-break-after: always; overflow: hidden; }
        .page:last-child { page-break-after: auto; }
        .page-bg { position: absolute; inset: 0; width: 612pt; height: 792pt; }
        .field { position: absolute; z-index: 2; display: block; min-height: 10pt; line-height: 1.05; white-space: nowrap; overflow: hidden; text-overflow: clip; background: #fff; padding: 0 1pt; }
        .multiline { white-space: normal; overflow: visible; }
        .logo-overlay { position: absolute; z-index: 2; object-fit: contain; }
        .signature-image { position: absolute; z-index: 2; object-fit: contain; }
    </style>
</head>
<body>
    <div class="page">
        @if($pageBg[1])<img class="page-bg" src="{{ $pageBg[1] }}" alt="Plantilla ficha 1">@endif
        <div class="field" style="{{ $fieldStyle(596, 156, 82, null, 11, 'normal', 'center') }}">{{ $submittedDate }}</div>
        <div class="field" style="{{ $fieldStyle(218, 227, 85, null, 12, 'normal') }}">{{ $documentNumber }}</div>
        <div class="field" style="{{ $fieldStyle(218, 252, 85, null, 12, 'normal') }}">{{ $age }}</div>
        <div class="field" style="{{ $fieldStyle(126, 294, 140, null, 12, 'normal') }}">{{ $birthDisplay }}</div>
        <div class="field" style="{{ $fieldStyle(93, 327, 120, null, 10, 'normal') }}">{{ Str::upper($text('sexo')) }}</div>
        <div class="field" style="{{ $fieldStyle(205, 327, 75, null, 10, 'normal') }}">{{ Str::upper($text('grupo_sanguineo')) }}</div>
        <div class="field" style="{{ $fieldStyle(82, 387, 160, null, 11, 'normal') }}">{{ $text('telefono_alterno') }}</div>
        <div class="field" style="{{ $fieldStyle(82, 414, 160, null, 11, 'normal') }}">{{ $text('telefono') }}</div>
        <div class="field" style="{{ $fieldStyle(195, 435, 160, null, 10, 'normal') }}">{{ Str::upper($text('brevete')) }}</div>

        <div class="field" style="{{ $fieldStyle(363, 220, 170, null, 12, 'normal', 'center') }}">{{ Str::upper($text('apellido_paterno')) }}</div>
        <div class="field" style="{{ $fieldStyle(549, 220, 170, null, 12, 'normal', 'center') }}">{{ Str::upper($text('apellido_materno')) }}</div>
        <div class="field" style="{{ $fieldStyle(723, 220, 160, null, 12, 'normal', 'center') }}">{{ Str::upper($text('nombres')) }}</div>
        <div class="field" style="{{ $fieldStyle(524, 257, 290, null, 10.5, 'normal') }}">{{ Str::upper($text('domicilio_direccion', $text('domicilio_extranjero'))) }}</div>
        <div class="field" style="{{ $fieldStyle(300, 286, 130, null, 11, 'normal') }}">{{ Str::upper($text('domicilio_distrito')) }}</div>
        <div class="field" style="{{ $fieldStyle(492, 286, 120, null, 11, 'normal') }}">{{ Str::upper($text('domicilio_provincia')) }}</div>
        <div class="field" style="{{ $fieldStyle(695, 286, 120, null, 11, 'normal') }}">{{ Str::upper($text('domicilio_departamento')) }}</div>
        <div class="field" style="{{ $fieldStyle(491, 315, 240, null, 10.5, 'normal') }}">{{ $text('correo') }}</div>
        <div class="field" style="{{ $fieldStyle(301, 344, 145, null, 11, 'normal') }}">{{ Str::upper($text('nacionalidad')) }}</div>
        <div class="field" style="{{ $fieldStyle(491, 344, 165, null, 11, 'normal') }}">{{ Str::upper($text('estado_civil')) }}</div>
        <div class="field" style="{{ $fieldStyle(300, 385, 145, null, 11, 'normal') }}">{{ Str::upper($text('distrito_nacimiento')) }}</div>
        <div class="field" style="{{ $fieldStyle(492, 385, 120, null, 11, 'normal') }}">{{ Str::upper($text('provincia_nacimiento')) }}</div>
        <div class="field" style="{{ $fieldStyle(693, 385, 120, null, 11, 'normal') }}">{{ Str::upper($text('departamento_nacimiento')) }}</div>
        <div class="field" style="{{ $fieldStyle(292, 412, 150, null, 10, 'normal') }}">{{ $text('numero_cuenta') }}</div>
        <div class="field" style="{{ $fieldStyle(691, 412, 120, null, 10, 'normal') }}">{{ Str::upper($text('banco')) }}</div>
        <div class="field" style="{{ $fieldStyle(291, 434, 150, null, 10, 'normal') }}">{{ $text('cci') }}</div>

        <div class="field" style="{{ $fieldStyle(282, 466, 205, null, 9, 'normal') }}">{{ Str::upper($familiares->first()?->nombres_apellidos ?? '') }}</div>
        <div class="field" style="{{ $fieldStyle(720, 466, 85, null, 10, 'normal') }}">{{ $familiares->first()?->telefono ?? '' }}</div>
        <div class="field" style="{{ $fieldStyle(281, 490, 120, null, 9, 'normal') }}">{{ Str::upper($familiares->first()?->parentesco ?? '') }}</div>
        <div class="field" style="{{ $fieldStyle(720, 490, 85, null, 10, 'normal') }}">{{ $familiares->first()?->telefono ?? '' }}</div>

        <div class="field" style="{{ $fieldStyle(188, 543, 60, null, 11, 'normal', 'center') }}">{{ $text('talla_zapato') }}</div>
        <div class="field" style="{{ $fieldStyle(336, 543, 55, null, 11, 'normal', 'center') }}">{{ Str::upper($text('talla_polo')) }}</div>
        <div class="field" style="{{ $fieldStyle(528, 543, 60, null, 11, 'normal', 'center') }}">{{ $text('talla_pantalon') }}</div>
        <div class="field" style="{{ $fieldStyle(795, 543, 40, null, 11, 'normal', 'center') }}">{{ Str::upper($text('talla_respirador')) }}</div>

        <div class="field" style="{{ $fieldStyle(289, 593, 160, null, 10, 'normal') }}">{{ Str::upper($text('grado_instruccion')) }}</div>
        <div class="field" style="{{ $fieldStyle(693, 593, 145, null, 10, 'normal') }}">{{ Str::upper($text('carrera')) }}</div>
        <div class="field" style="{{ $fieldStyle(289, 614, 180, null, 10, 'normal') }}">{{ Str::upper($text('profesion_oficio')) }}</div>
        <div class="field" style="{{ $fieldStyle(693, 614, 145, null, 10, 'normal') }}">{{ Str::upper($text('institucion')) }}</div>
        <div class="field" style="{{ $fieldStyle(289, 634, 90, null, 10, 'normal') }}">{{ $text('anio_egreso') }}</div>

        <div class="field" style="{{ $fieldStyle(454, 675, 160, null, 10, 'normal', 'center') }}">{{ Str::upper($text('especialidad')) }}</div>
        <div class="field" style="{{ $fieldStyle(800, 675, 140, null, 10, 'normal', 'center') }}">{{ Str::upper($text('puesto')) }}</div>
        <div class="field" style="{{ $fieldStyle(458, 696, 120, null, 10, 'normal', 'center') }}">{{ Str::upper($text('anio_experiencia')) }}</div>
        <div class="field" style="{{ $fieldStyle(804, 696, 120, null, 10, 'normal', 'center') }}">{{ Str::upper($text('categoria_trabajador')) }}</div>

        <div class="field" style="{{ $fieldStyle(248, 747, 210, null, 9.5, 'normal') }}">{{ Str::upper($text('sistema_pensionario')) }}</div>
        <div class="field" style="{{ $fieldStyle(775, 747, 110, null, 9.5, 'normal', 'center') }}">{{ Str::upper($text('tipo_afp')) }}</div>
        <div class="field" style="{{ $fieldStyle(250, 770, 110, null, 9.5, 'normal') }}">{{ Str::upper($text('tipo_comision')) }}</div>
        <div class="field" style="{{ $fieldStyle(766, 770, 130, null, 9, 'normal', 'center') }}">{{ Str::upper($text('cuspp')) }}</div>

        @foreach($orderedRelatives as $index => $relative)
            @php $baseY = 822 + ($index * 22); @endphp
            <div class="field" style="{{ $fieldStyle(175, $baseY, 245, null, 8.4, 'normal') }}">{{ Str::upper($relative['name']) }}</div>
            <div class="field" style="{{ $fieldStyle(421, $baseY, 150, null, 8.4, 'normal') }}">{{ $relative['date'] }}</div>
            <div class="field" style="{{ $fieldStyle(663, $baseY, 70, null, 8.4, 'normal', 'center') }}">{{ $relative['vive'] }}</div>
            <div class="field" style="{{ $fieldStyle(806, $baseY, 110, null, 8.4, 'normal') }}">{{ $relative['phone'] }}</div>
        @endforeach

        <div class="field" style="{{ $fieldStyle(813, 955, 40, null, 10, 'bold', 'center') }}">SI</div>

        @if($firmaUri)
            <img class="signature-image" src="{{ $firmaUri }}" alt="Firma" style="{{ $fieldStyle(144, 1007, 185, 55) }}">
        @endif
        @if($huellaUri)
            <img class="signature-image" src="{{ $huellaUri }}" alt="Huella" style="{{ $fieldStyle(429, 995, 130, 70) }}">
        @endif
    </div>

    <div class="page">
        @if($pageBg[2])<img class="page-bg" src="{{ $pageBg[2] }}" alt="Plantilla ficha 2">@endif
        <div class="field" style="{{ $fieldStyle(353, 228, 260, null, 10.5, 'normal') }}">{{ Str::upper($fullName) }}</div>
        @if($firmaUri)
            <img class="signature-image" src="{{ $firmaUri }}" alt="Firma" style="{{ $fieldStyle(145, 724, 190, 48) }}">
        @endif
    </div>

    <div class="page">
        @if($pageBg[3])<img class="page-bg" src="{{ $pageBg[3] }}" alt="Plantilla ficha 3">@endif
        <div class="field" style="{{ $fieldStyle(304, 196, 140, null, 10, 'normal') }}">{{ Str::upper($text('apellido_paterno')) }}</div>
        <div class="field" style="{{ $fieldStyle(304, 224, 140, null, 10, 'normal') }}">{{ Str::upper($text('apellido_materno')) }}</div>
        <div class="field" style="{{ $fieldStyle(304, 253, 220, null, 10, 'normal') }}">{{ Str::upper($text('nombres')) }}</div>
        <div class="field" style="{{ $fieldStyle(509, 282, 90, null, 10, 'normal') }}">{{ $documentNumber }}</div>
        <div class="field" style="{{ $fieldStyle(356, 371, 28, null, 10, 'normal', 'center') }}">{{ Str::startsWith(Str::upper($text('sexo')), 'F') ? '' : 'X' }}</div>
        <div class="field" style="{{ $fieldStyle(409, 371, 28, null, 10, 'normal', 'center') }}">{{ Str::startsWith(Str::upper($text('sexo')), 'F') ? 'X' : '' }}</div>
        <div class="field" style="{{ $fieldStyle(443, 476, 110, null, 10, 'normal') }}">{{ Str::upper($text('domicilio_distrito')) }}</div>
        <div class="field" style="{{ $fieldStyle(443, 499, 110, null, 10, 'normal') }}">{{ Str::upper($text('domicilio_provincia')) }}</div>
        <div class="field" style="{{ $fieldStyle(491, 523, 120, null, 10, 'normal') }}">{{ Str::upper($text('domicilio_departamento')) }}</div>
        @if($entry)
            <div class="field" style="{{ $fieldStyle(492, 726, 28, null, 9.5, 'normal', 'center') }}">{{ $entry->format('d') }}</div>
            <div class="field" style="{{ $fieldStyle(632, 726, 42, null, 9.5, 'normal', 'center') }}">{{ $entry->format('m') }}</div>
            <div class="field" style="{{ $fieldStyle(785, 726, 52, null, 9.5, 'normal', 'center') }}">{{ $entry->format('Y') }}</div>
        @endif
        <div class="field" style="{{ $fieldStyle(722, 852, 58, null, 11, 'bold', 'center') }}">{{ $isPrivatePension ? 'X' : '' }}</div>
        <div class="field" style="{{ $fieldStyle(289, 964, 320, null, 10, 'normal') }}">{{ $city }}</div>
        <div class="field" style="{{ $fieldStyle(454, 964, 60, null, 10, 'normal') }}">{{ $quintaDay }}</div>
        <div class="field" style="{{ $fieldStyle(578, 964, 95, null, 10, 'normal') }}">{{ $quintaMonth }}</div>
        <div class="field" style="{{ $fieldStyle(722, 964, 50, null, 10, 'normal') }}">{{ $quintaYear }}</div>
        @if($firmaUri)
            <img class="signature-image" src="{{ $firmaUri }}" alt="Firma" style="{{ $fieldStyle(384, 884, 180, 60) }}">
        @endif
    </div>

    <div class="page">
        @if($pageBg[4])<img class="page-bg" src="{{ $pageBg[4] }}" alt="Plantilla ficha 4">@endif
        <div class="field" style="{{ $fieldStyle(274, 807, 210, null, 10, 'normal') }}">{{ Str::upper($fullName) }}</div>
        <div class="field" style="{{ $fieldStyle(410, 854, 100, null, 10, 'normal') }}">{{ $documentNumber }}</div>
        <div class="field" style="{{ $fieldStyle(169, 1026, 110, null, 10, 'normal') }}">{{ $city }}</div>
        <div class="field" style="{{ $fieldStyle(319, 1026, 90, null, 10, 'normal') }}">{{ $quintaDay }}</div>
        <div class="field" style="{{ $fieldStyle(469, 1026, 110, null, 10, 'normal') }}">{{ $quintaMonth }}</div>
        <div class="field" style="{{ $fieldStyle(625, 1026, 85, null, 10, 'normal') }}">{{ $quintaYear }}</div>
    </div>

    <div class="page">
        @if($pageBg[5])<img class="page-bg" src="{{ $pageBg[5] }}" alt="Plantilla ficha 5">@endif
        <div class="field" style="{{ $fieldStyle(310, 168, 478, null, 10, 'normal') }}">{{ Str::upper($fullName) }}</div>
        <div class="field" style="{{ $fieldStyle(225, 191, 560, null, 10, 'normal') }}">{{ Str::upper($text('quinta_domicilio', $text('domicilio_direccion'))) }}</div>
        <div class="field" style="{{ $fieldStyle(152, 366, 20, null, 12, 'bold', 'center') }}">{{ Str::lower($text('quinta_percibe_otras')) === 'si' ? 'X' : '' }}</div>
        <div class="field" style="{{ $fieldStyle(152, 474, 20, null, 12, 'bold', 'center') }}">{{ Str::lower($text('quinta_adjunta_dj_anterior')) === 'si' ? 'X' : '' }}</div>
        @php $employer = $otherEmployers[0] ?? []; @endphp
        <div class="field" style="{{ $fieldStyle(177, 694, 190, null, 10, 'normal') }}">{{ Str::upper((string) ($employer['razon_social'] ?? $text('quinta_otra_empresa'))) }}</div>
        <div class="field" style="{{ $fieldStyle(411, 694, 72, null, 10, 'normal', 'center') }}">{{ (string) ($employer['ruc'] ?? $text('quinta_otra_empresa_ruc')) }}</div>
        <div class="field" style="{{ $fieldStyle(530, 694, 75, null, 10, 'normal', 'center') }}">{{ (string) ($employer['monto_anual'] ?? '') }}</div>
        <div class="field" style="{{ $fieldStyle(705, 694, 90, null, 10, 'normal', 'center') }}">{{ (string) ($employer['retencion'] ?? '') }}</div>
        <div class="field" style="{{ $fieldStyle(507, 854, 120, null, 10, 'normal') }}">{{ $city }}</div>
        <div class="field" style="{{ $fieldStyle(603, 854, 55, null, 10, 'normal') }}">{{ $quintaDay }}</div>
        <div class="field" style="{{ $fieldStyle(684, 854, 120, null, 10, 'normal') }}">{{ $quintaMonth }}</div>
        <div class="field" style="{{ $fieldStyle(842, 854, 70, null, 10, 'normal') }}">{{ $quintaYear }}</div>
        @if($firmaUri)
            <img class="signature-image" src="{{ $firmaUri }}" alt="Firma" style="{{ $fieldStyle(425, 906, 170, 46) }}">
        @endif
        <div class="field" style="{{ $fieldStyle(392, 966, 170, null, 10, 'normal') }}">{{ $documentNumber }}</div>
    </div>

    <div class="page">
        @if($pageBg[6])<img class="page-bg" src="{{ $pageBg[6] }}" alt="Plantilla ficha 6">@endif
        <div class="field" style="{{ $fieldStyle(171, 322, 310, null, 12, 'normal') }}">{{ Str::upper($fullName) }}</div>
        <div class="field" style="{{ $fieldStyle(774, 322, 120, null, 12, 'normal') }}">{{ $documentType }}</div>
        <div class="field" style="{{ $fieldStyle(129, 370, 110, null, 12, 'normal') }}">{{ $documentNumber }}</div>
        <div class="field" style="{{ $fieldStyle(381, 369, 471, null, 12, 'normal') }}">{{ Str::upper($text('domicilio_direccion', $text('domicilio_extranjero'))) }}</div>
        <div class="field" style="{{ $fieldStyle(354, 881, 180, null, 11, 'normal') }}">{{ $city }}</div>
        <div class="field" style="{{ $fieldStyle(590, 881, 50, null, 11, 'normal') }}">{{ $quintaDay }}</div>
        <div class="field" style="{{ $fieldStyle(725, 881, 100, null, 11, 'normal') }}">{{ $quintaMonth }}</div>
        <div class="field" style="{{ $fieldStyle(838, 881, 90, null, 11, 'normal') }}">{{ $quintaYear }}</div>
        @if($huellaUri)
            <img class="signature-image" src="{{ $huellaUri }}" alt="Huella" style="{{ $fieldStyle(134, 885, 98, 116) }}">
        @endif
        @if($firmaUri)
            <img class="signature-image" src="{{ $firmaUri }}" alt="Firma" style="{{ $fieldStyle(570, 989, 190, 54) }}">
        @endif
    </div>

    <div class="page">
        @if($pageBg[7])<img class="page-bg" src="{{ $pageBg[7] }}" alt="Plantilla ficha 7">@endif
        <div class="field" style="{{ $fieldStyle(309, 187, 260, null, 12, 'normal') }}">{{ Str::upper($fullName) }}</div>
        <div class="field" style="{{ $fieldStyle(194, 219, 110, null, 10, 'normal') }}">{{ $documentNumber }}</div>
        <div class="field multiline" style="{{ $fieldStyle(189, 236, 245, 30, 8.5, 'normal') }}">{{ Str::upper($text('domicilio_direccion', $text('domicilio_extranjero'))) }}</div>
        @if($firmaUri)
            <img class="signature-image" src="{{ $firmaUri }}" alt="Firma" style="{{ $fieldStyle(660, 195, 110, 80) }}">
        @endif
        @if($huellaUri)
            <img class="signature-image" src="{{ $huellaUri }}" alt="Huella" style="{{ $fieldStyle(783, 195, 90, 80) }}">
        @endif
    </div>
</body>
</html>
