@php
    use App\Modules\Personal\Support\PersonalNormalizer;

    $value = fn (string $key, string $default = '-') => trim((string) ($data[$key] ?? '')) !== '' ? trim((string) $data[$key]) : $default;
    $fullName = trim(collect([$value('apellido_paterno', ''), $value('apellido_materno', ''), $value('nombres', '')])->filter()->implode(' '));
    $fullName = $fullName !== '' ? $fullName : ($ficha->personal?->nombre_completo ?: '-');
    $document = trim(($ficha->tipo_documento ?: $value('tipo_documento', 'DNI')) . ' ' . ($ficha->numero_documento ?: $value('numero_documento', '')));
    $fechaFicha = optional($ficha->submitted_at ?? $ficha->created_at)->format('d/m/Y') ?: now()->format('d/m/Y');
    $fechaNacimiento = $value('fecha_nacimiento', '');
    $edad = '-';
    if ($fechaNacimiento !== '') {
        try {
            $edad = \Carbon\Carbon::parse($fechaNacimiento)->age;
        } catch (\Throwable) {
            $edad = '-';
        }
    }
    $fechaInicio = $value('fecha_ingreso', '');
    $inicio = ['dia' => '', 'mes' => '', 'anio' => ''];
    if ($fechaInicio !== '') {
        try {
            $date = \Carbon\Carbon::parse($fechaInicio);
            $inicio = ['dia' => $date->format('d'), 'mes' => $date->format('m'), 'anio' => $date->format('Y')];
        } catch (\Throwable) {
            $inicio = ['dia' => '', 'mes' => '', 'anio' => ''];
        }
    }
    $domicilio = $value('domicilio_direccion', '');
    $domicilioDeclaracion = $value('quinta_domicilio', $domicilio);
    $ciudad = $value('quinta_ciudad', $value('domicilio_provincia', 'Arequipa'));
    $quintaDia = $value('quinta_fecha_dia', now()->format('d'));
    $quintaMes = $value('quinta_fecha_mes', now()->locale('es')->translatedFormat('F'));
    $quintaAnio = $value('quinta_fecha_anio', now()->format('Y'));
    $familiaresOrden = collect(['Padre', 'Madre', 'Conyuge', 'Hijo 1', 'Hijo 2', 'Hijo 3', 'Hijo 4', 'Hijo 5'])
        ->map(function (string $parentesco) use ($familiares) {
            $match = $familiares->first(fn ($item) => strcasecmp((string) $item->parentesco, $parentesco) === 0);
            return [
                'parentesco' => $parentesco,
                'nombres' => $match?->nombres_apellidos ?: '',
                'fecha' => optional($match?->fecha_nacimiento)->format('d/m/Y') ?: '',
                'vive' => $match ? ($match->vive_con_trabajador ? 'SI' : 'NO') : '',
                'telefono' => $match?->telefono ?: '',
            ];
        });
    $contacto = $familiares->firstWhere('contacto_emergencia', true) ?: $familiares->first();
    try {
        $otrosEmpleadores = json_decode((string) ($data['quinta_otros_empleadores_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
        $otrosEmpleadores = is_array($otrosEmpleadores) ? $otrosEmpleadores : [];
    } catch (\Throwable) {
        $otrosEmpleadores = [];
    }
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 22px 24px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 9.5px; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td, th { border: 1px solid #111827; padding: 4px 5px; vertical-align: top; word-wrap: break-word; }
        th { background: #f1f5f9; font-weight: bold; text-align: center; }
        .no-border td, .no-border th { border: 0; }
        .header-title { font-size: 14px; font-weight: bold; text-align: center; }
        .doc-title { font-size: 16px; font-weight: bold; text-align: center; margin: 14px 0; }
        .section-title { background: #e5e7eb; border: 1px solid #111827; padding: 5px; font-weight: bold; margin-top: 7px; }
        .label { font-weight: bold; background: #f8fafc; }
        .center { text-align: center; }
        .right { text-align: right; }
        .small { font-size: 8.5px; }
        .muted { color: #374151; }
        .photo-box { height: 82px; text-align: center; font-weight: bold; }
        .signature-box { height: 76px; text-align: center; }
        .image-box { max-width: 170px; max-height: 68px; object-fit: contain; }
        .huella-box { width: 82px; height: 82px; text-align: center; }
        .blank-box { height: 360px; border: 1px solid #111827; }
        .line { border-bottom: 1px solid #111827; min-height: 16px; display: inline-block; }
        .check { font-family: DejaVu Sans, sans-serif; }
    </style>
</head>
<body>
    <div class="page">
        <table>
            <tr>
                <td class="photo-box" rowspan="3">FOTO</td>
                <td class="header-title" rowspan="3">FICHA DE COLABORADOR</td>
                <td class="label">Codigo:</td><td>SGC-FOR-13</td>
            </tr>
            <tr><td class="label">Version:</td><td>03</td></tr>
            <tr><td class="label">Fecha:</td><td>01/05/2026</td></tr>
        </table>

        <table style="margin-top:6px;">
            <tr><td class="label" style="width:16%;">FECHA</td><td>{{ $fechaFicha }}</td><td class="label">RUC</td><td>20539399536</td></tr>
        </table>

        <div class="section-title">DATOS PERSONALES:</div>
        <table>
            <tr><td class="label">AP. PATERNO</td><td>{{ $value('apellido_paterno') }}</td><td class="label">AP. MATERNO</td><td>{{ $value('apellido_materno') }}</td><td class="label">NOMBRES</td><td>{{ $value('nombres') }}</td></tr>
            <tr><td class="label">DNI / DOC.</td><td>{{ $document }}</td><td class="label">Edad</td><td>{{ $edad }}</td><td class="label">Fecha nacimiento</td><td>{{ $fechaNacimiento }}</td></tr>
            <tr><td class="label">Sexo</td><td>{{ $value('sexo') }}</td><td class="label">G. sanguin.</td><td>{{ $value('grupo_sanguineo') }}</td><td class="label">Celular movil</td><td>{{ $value('telefono') }}</td></tr>
            <tr><td class="label">E-mail</td><td colspan="2">{{ $value('correo') }}</td><td class="label">Nacionalidad</td><td colspan="2">{{ $value('nacionalidad') === 'Otra' ? $value('nacionalidad_otra') : $value('nacionalidad') }}</td></tr>
            <tr><td class="label">Estado civil</td><td>{{ $value('estado_civil') === 'Otro' ? $value('estado_civil_otro') : $value('estado_civil') }}</td><td class="label">N. brevete</td><td colspan="3">{{ $value('brevete') }}</td></tr>
        </table>

        <div class="section-title">DOMICILIO ACTUAL</div>
        <table>
            <tr><td class="label">Direccion</td><td colspan="5">{{ $domicilio !== '' ? $domicilio : $value('domicilio_extranjero') }}</td></tr>
            <tr><td class="label">Distrito</td><td>{{ $value('domicilio_distrito') }}</td><td class="label">Provincia</td><td>{{ $value('domicilio_provincia') }}</td><td class="label">Departamento</td><td>{{ $value('domicilio_departamento') }}</td></tr>
        </table>

        <div class="section-title">LUGAR DE NACIMIENTO</div>
        <table>
            <tr><td class="label">Distrito</td><td>{{ $value('distrito_nacimiento') }}</td><td class="label">Provincia</td><td>{{ $value('provincia_nacimiento') }}</td><td class="label">Departamento / Pais</td><td>{{ $value('departamento_nacimiento', $value('pais_nacimiento_otro')) }}</td></tr>
        </table>

        <div class="section-title">DATOS BANCARIOS / EPPS / TALLA</div>
        <table>
            <tr><td class="label">Nro cuenta - banco</td><td>{{ $value('numero_cuenta') }} - {{ $value('banco') === 'Otro' ? $value('banco_otro') : $value('banco') }}</td><td class="label">CCI</td><td>{{ $value('cci') }}</td></tr>
            <tr><td class="label">Zapato/botas</td><td>{{ $value('talla_zapato') }}</td><td class="label">Camisa/chaleco</td><td>{{ $value('talla_polo') }}</td></tr>
            <tr><td class="label">Pantalon</td><td>{{ $value('talla_pantalon') }}</td><td class="label">Respirador</td><td>{{ $value('talla_respirador') }}</td></tr>
        </table>

        <div class="section-title">GRADO DE INSTRUCCION / OCUPACION / OFICIO</div>
        <table>
            <tr><td class="label">Grado de instruccion</td><td>{{ $value('grado_instruccion') }}</td><td class="label">Carrera</td><td>{{ $value('carrera') }}</td></tr>
            <tr><td class="label">Profesion u oficio</td><td>{{ $value('profesion_oficio') }}</td><td class="label">Institucion</td><td>{{ $value('institucion') }}</td></tr>
            <tr><td class="label">Anio de egreso</td><td>{{ $value('anio_egreso') }}</td><td class="label">Especialidad</td><td>{{ $value('especialidad') }}</td></tr>
            <tr><td class="label">Tipo trabajador</td><td>{{ $value('tipo_trabajador') }}</td><td class="label">Categoria</td><td>{{ $value('categoria_trabajador') }}</td></tr>
        </table>

        <div class="section-title">SISTEMA PENSIONARIO</div>
        <table>
            <tr><td class="label">Sistema pensionario</td><td>{{ $value('sistema_pensionario') }}</td><td class="label">Tipo AFP</td><td>{{ $value('tipo_afp') }}</td></tr>
            <tr><td class="label">Tipo comision</td><td>{{ $value('tipo_comision') }}</td><td class="label">CUSPP</td><td>{{ $value('cuspp') }}</td></tr>
        </table>

        <div class="section-title">DATOS FAMILIARES</div>
        <table>
            <tr><th>Parentesco</th><th>Apellidos y nombres</th><th>Fecha de nacimiento</th><th>Vive conmigo</th><th>Telefono</th></tr>
            @foreach($familiaresOrden as $row)
                <tr><td>{{ $row['parentesco'] }}</td><td>{{ $row['nombres'] }}</td><td>{{ $row['fecha'] }}</td><td>{{ $row['vive'] }}</td><td>{{ $row['telefono'] }}</td></tr>
            @endforeach
        </table>

        <p class="center" style="margin-top:10px;font-weight:bold;">DECLARO BAJO JURAMENTO QUE LOS DATOS CONSIGNADOS SON VERDADEROS</p>
        <table class="no-border">
            <tr>
                <td class="signature-box">
                    @if($firmaBase64)<img class="image-box" src="{{ $firmaBase64 }}" alt="Firma">@endif<br>
                    ................................................<br>TRABAJADOR
                </td>
                <td class="signature-box">................................................<br>Vº Bº P&S PROSERGE</td>
                <td class="huella-box">
                    @if($huellaDataUrl)<img class="image-box" src="{{ $huellaDataUrl }}" alt="Huella">@endif<br>
                    Huella Digital
                </td>
            </tr>
        </table>
        <p class="small">En caso de Emergencia Contacte a {{ $contacto?->nombres_apellidos ?: '-' }}. Relacion con Usted: {{ $contacto?->parentesco ?: '-' }}. Celular {{ $contacto?->telefono ?: '-' }}</p>
    </div>

    <div class="page">
        <div class="doc-title">INDUCCION DE PERSONAL NUEVO</div>
        <table><tr><td class="label">Nombre de la nueva persona funcionaria</td><td>{{ $fullName }}</td></tr><tr><td class="label">Cargo a desempenar</td><td>{{ $value('puesto') }}</td></tr><tr><td class="label">Jefatura inmediata</td><td></td></tr></table>
        <div class="section-title">DESCRIPCION DE ACTIVIDADES DE INDUCCION Y ENTRENAMIENTO EN EL PUESTO</div>
        <table>
            <tr><th>Descripcion de la actividad</th><th>Fecha</th><th>Aplica SI/NO</th><th>Ejecutada SI/NO</th><th>Area responsable</th><th>Firma</th></tr>
            @foreach(['Recorrido por las instalaciones y presentacion de colaboradores.', 'Informacion del procedimiento ante dano o mal funcionamiento de equipos.', 'Induccion - Anexo 5.', 'Manual de procedimientos de seguridad.', 'Induccion - Anexo 4.', 'Induccion por Almacen o Logistica y entrega de EPPs.'] as $activity)
                <tr><td>{{ $activity }}</td><td></td><td></td><td></td><td>OPERACIONES</td><td></td></tr>
            @endforeach
        </table>
    </div>

    <div class="page">
        <div class="doc-title">Formato de Eleccion del Sistema Pensionario</div>
        <div class="section-title">I.- DATOS DEL TRABAJADOR</div>
        <table>
            <tr><td class="label">Apellido Paterno</td><td>{{ $value('apellido_paterno') }}</td><td class="label">Apellido Materno</td><td>{{ $value('apellido_materno') }}</td><td class="label">Nombres</td><td>{{ $value('nombres') }}</td></tr>
            <tr><td class="label">Tipo de documento</td><td>{{ $ficha->tipo_documento }}</td><td class="label">Numero</td><td>{{ $ficha->numero_documento }}</td><td class="label">Sexo</td><td>{{ $value('sexo') }}</td></tr>
            <tr><td class="label">Fecha nacimiento</td><td>{{ $fechaNacimiento }}</td><td class="label">Distrito</td><td>{{ $value('domicilio_distrito') }}</td><td class="label">Provincia</td><td>{{ $value('domicilio_provincia') }}</td></tr>
        </table>
        <div class="section-title">III.- DATOS DEL VINCULO LABORAL</div>
        <table>
            <tr><td class="label">Nombre o razon social</td><td>{{ $value('empleador_razon_social') }}</td><td class="label">Nro. RUC</td><td>{{ $value('empleador_ruc') }}</td></tr>
            <tr><td class="label">Departamento del domicilio fiscal</td><td colspan="3">{{ $value('empleador_domicilio_fiscal') }}</td></tr>
            <tr><td class="label">Fecha de inicio de la relacion laboral</td><td>Dia {{ $inicio['dia'] }} Mes {{ $inicio['mes'] }} Anio {{ $inicio['anio'] }}</td><td class="label">Remuneracion</td><td>{{ $value('remuneracion') }}</td></tr>
        </table>
        <div class="section-title">IV.- ELECCION DEL SISTEMA PENSIONARIO</div>
        <table><tr><td>ONP</td><td class="center">{{ $value('sistema_pensionario') === 'ONP' ? 'X' : '' }}</td></tr><tr><td>Sistema Privado de Pensiones</td><td class="center">{{ $value('sistema_pensionario') === 'Sistema Privado de Pensiones' ? 'X' : '' }}</td></tr></table>
        <p style="margin-top:40px;">Firma del Trabajador: @if($firmaBase64)<img class="image-box" src="{{ $firmaBase64 }}" alt="Firma">@endif</p>
        <p class="center">Ciudad de {{ $ciudad }}, {{ $quintaDia }} de {{ $quintaMes }} del {{ $quintaAnio }}</p>
    </div>

    <div class="page">
        <div class="doc-title">Constancia de Entrega del Boletin Informativo acerca de las caracteristicas del SPP y SNP</div>
        <p>Por medio del presente documento dejo constancia de haber recibido de parte de mi empleador P & S Produccion y Servicios Generales S.R.L., con RUC 20539399536, el Boletin Informativo y el Formato de Eleccion del Sistema Pensionario.</p>
        <p>Datos del Trabajador:</p>
        <table><tr><td class="label">Nombres y Apellidos</td><td>{{ $fullName }}</td></tr><tr><td class="label">Tipo y numero de documento</td><td>{{ $document }}</td></tr><tr><td class="label">Firma y huella digital</td><td>@if($firmaBase64)<img class="image-box" src="{{ $firmaBase64 }}" alt="Firma">@endif @if($huellaDataUrl)<img class="image-box" src="{{ $huellaDataUrl }}" alt="Huella">@endif</td></tr></table>
        <p class="center" style="margin-top:80px;">Ciudad de {{ $ciudad }}, {{ $quintaDia }} de {{ $quintaMes }} del {{ $quintaAnio }}</p>
    </div>

    <div class="page">
        <div class="doc-title">DECLARACION JURADA DE INGRESOS DE RENTA DE QUINTA CATEGORIA</div>
        <div class="section-title">A. IDENTIFICACION DE TRABAJADOR</div>
        <table><tr><td class="label">Apellidos y nombres</td><td>{{ $fullName }}</td></tr><tr><td class="label">Domicilio</td><td>{{ $domicilioDeclaracion }}</td></tr></table>
        <p>Por la presente cumplo con informar que mi empleador principal es:</p>
        <p>- P&S PROSERGE S.R.L. con RUC 20539399536 {{ $value('quinta_empleador_principal') === 'P&S PROSERGE S.R.L.' ? '(X)' : '( )' }}</p>
        <p>- Otra empresa {{ $value('quinta_otra_empresa', '................................') }} RUC Nro {{ $value('quinta_otra_empresa_ruc', '........................') }} {{ $value('quinta_empleador_principal') === 'Otra empresa' ? '(X)' : '( )' }}</p>
        <p>Y sera la UNICA encargada de efectuar retenciones de Quinta Categoria durante el ejercicio del anio {{ $value('quinta_ejercicio_anio', $quintaAnio) }}.</p>
        <p>En caso de haber designado a P&S PROSERGE S.R.L. como empleador principal, informo que:</p>
        <p>( {{ $value('quinta_percibe_otras') === 'No' ? 'X' : ' ' }} ) NO percibo otras remuneraciones de Quinta Categoria.</p>
        <p>( {{ $value('quinta_percibe_otras') === 'Si' ? 'X' : ' ' }} ) SI percibo otras remuneraciones para que este acumule y efectue la retencion correspondiente.</p>
        <p>( {{ $value('quinta_adjunta_dj_anterior') === 'Si' ? 'X' : ' ' }} ) Adjunto la Declaracion Jurada de mi anterior empleador.</p>
        <p>( {{ $value('quinta_declara_sin_ingresos') === 'Si' ? 'X' : ' ' }} ) Declaro bajo juramento que durante el ejercicio del anio en curso no tuve ingresos que afectan al Impuesto de Quinta Categoria ni de Cuarta categoria.</p>
        <div class="section-title">B. DECLARACION DE INGRESOS</div>
        <table>
            <tr><th>Otros Empleadores (Razon Social)</th><th>RUC</th><th>Monto Anual</th><th>Retencion de Impuestos</th></tr>
            @forelse($otrosEmpleadores as $empleador)
                <tr><td>{{ $empleador['empresa'] ?? '' }}</td><td>{{ $empleador['ruc'] ?? '' }}</td><td>{{ $empleador['monto'] ?? '' }}</td><td>{{ $empleador['retencion'] ?? '' }}</td></tr>
            @empty
                <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
                <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
            @endforelse
        </table>
        <p style="margin-top:28px;">{{ $ciudad }}, {{ $quintaDia }} de {{ $quintaMes }} del {{ $quintaAnio }}</p>
        <p class="center" style="margin-top:50px;">@if($firmaBase64)<img class="image-box" src="{{ $firmaBase64 }}" alt="Firma">@endif<br>Firma del trabajador</p>
    </div>

    <div class="page">
        <div class="doc-title">LEY N° 28882<br>DECLARACION JURADA DE DOMICILIO</div>
        <p>Yo, {{ $fullName }} de Nacionalidad {{ $value('nacionalidad') === 'Otra' ? $value('nacionalidad_otra') : $value('nacionalidad') }}; con {{ $document }}; domiciliado en: {{ $domicilioDeclaracion }}; en pleno goce de los Derechos Constitucionales y en concordancia con lo previsto en la Ley de Procedimientos Administrativos N° 27444.</p>
        <h3 class="center">DECLARO BAJO JURAMENTO</h3>
        <p>Que, la direccion que senalo lineas arriba, es mi domicilio real, actual, efectivo y verdadero, donde tengo vivencia real, fisica y permanente. Formula la presente Declaracion Jurada para los fines legales de: TRABAJO.</p>
        <p class="center" style="margin-top:70px;">Ciudad de {{ $ciudad }}, {{ $quintaDia }} de {{ $quintaMes }} del {{ $quintaAnio }}</p>
        <table class="no-border" style="margin-top:70px;"><tr><td class="center">@if($huellaDataUrl)<img class="image-box" src="{{ $huellaDataUrl }}" alt="Huella">@endif<br>Huella Digital</td><td class="center">@if($firmaBase64)<img class="image-box" src="{{ $firmaBase64 }}" alt="Firma">@endif<br>Firma</td></tr></table>
    </div>

    <div class="page">
        <div class="doc-title">CROQUIS DOMICILIARIO</div>
        <table><tr><td class="label">Apellidos y nombres</td><td>{{ $fullName }}</td></tr><tr><td class="label">DNI / Documento</td><td>{{ $ficha->numero_documento }}</td></tr><tr><td class="label">Direccion</td><td>{{ $domicilioDeclaracion }}</td></tr></table>
        <div class="blank-box" style="margin-top:20px;"></div>
        <table class="no-border" style="margin-top:30px;"><tr><td class="center">@if($huellaDataUrl)<img class="image-box" src="{{ $huellaDataUrl }}" alt="Huella">@endif<br>HUELLA</td><td class="center">@if($firmaBase64)<img class="image-box" src="{{ $firmaBase64 }}" alt="Firma">@endif<br>FIRMA</td></tr></table>
    </div>
</body>
</html>
