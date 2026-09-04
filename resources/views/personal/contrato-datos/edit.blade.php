@extends('layouts.app')

@section('title', 'Datos de contrato - Proserge')

@section('content')
@php
    $dateValue = fn ($field): string => old($field, optional($datos->{$field})->toDateString() ?? '');
    $textValue = fn ($field): string => (string) old($field, $datos->{$field} ?? '');
    $estadoContrato = strtoupper((string) ($contrato->estado ?? 'PREPARACION'));
    $contratoLabel = $contrato
        ? app(\App\Modules\Personal\Services\PersonalContratoService::class)->contractDisplayLabel($contrato)
        : 'Contrato sin periodo';
@endphp

<div class="module-page contract-data-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Datos de contrato</h1>
                <p class="page-subtitle">
                    {{ $personal->nombre_completo }} - {{ $personal->tipo_documento ?: 'DNI' }} {{ $personal->numero_documento ?: $personal->dni }}
                    @if(false)
                    · Contrato #{{ $contrato->contrato_numero ?? '-' }} {{ $estadoContrato === 'PREPARACION' ? 'en preparacion' : strtolower($estadoContrato) }}
                    @endif
                    - {{ $contratoLabel }} {{ $estadoContrato === 'PREPARACION' ? 'en preparacion' : strtolower($estadoContrato) }}
                </p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('personal.contrato-datos.update', $personal->id) }}" class="card">
        @csrf
        @method('PUT')
        <div class="card-header">
            <span class="card-title">Campos para formatos de contrato</span>
        </div>
        <div class="card-body">
            <p class="contract-data-note" style="margin-bottom:14px;">Estos datos se usan para la vista previa y descarga de formatos de contrato. Puedes corregir el puesto aqui antes de generar el Excel.</p>

            <div class="contract-data-grid">
                <div class="form-group">
                    <label class="form-label">Fecha inicio contrato</label>
                    <input type="date" name="fecha_inicio_contrato" class="form-control" value="{{ $dateValue('fecha_inicio_contrato') }}" data-auto-fill="periodo_prueba_inicio">
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha fin</label>
                    <input type="date" name="fecha_fin_contrato" class="form-control" value="{{ $dateValue('fecha_fin_contrato') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Inicio prueba</label>
                    <input type="date" name="periodo_prueba_inicio" class="form-control" value="{{ $dateValue('periodo_prueba_inicio') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Fin prueba</label>
                    <input type="date" name="periodo_prueba_fin" class="form-control" value="{{ $dateValue('periodo_prueba_fin') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_HORA_PARADAS</label>
                    <input type="text" name="sueldo_hora_paradas" class="form-control" value="{{ $textValue('sueldo_hora_paradas') }}" data-auto-fill-texto="sueldo_hora_paradas_texto">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_HORA_PARADAS_TEXTO</label>
                    <input type="text" name="sueldo_hora_paradas_texto" class="form-control" value="{{ $textValue('sueldo_hora_paradas_texto') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_DIA_TALLER</label>
                    <input type="text" name="sueldo_dia_taller" class="form-control" value="{{ $textValue('sueldo_dia_taller') }}" data-auto-fill-texto="sueldo_dia_taller_texto">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_DIA_TALLER_TEXTO</label>
                    <input type="text" name="sueldo_dia_taller_texto" class="form-control" value="{{ $textValue('sueldo_dia_taller_texto') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_NUM</label>
                    <input type="text" name="sueldo_num" class="form-control" value="{{ $textValue('sueldo_num') }}" data-auto-fill-texto="sueldo_texto">
                </div>
                <div class="form-group">
                    <label class="form-label">SUELDO_TEXTO</label>
                    <input type="text" name="sueldo_texto" class="form-control" value="{{ $textValue('sueldo_texto') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Puesto</label>
                    @php $selectedPuesto = $textValue('puesto') ?: $personal->puesto; @endphp
                    @include('personal.partials.puesto-autocomplete', [
                        'name' => 'puesto',
                        'value' => $selectedPuesto,
                        'inputId' => 'contrato_puesto',
                        'listId' => 'puestos_catalogo_contrato',
                        'class' => 'form-control',
                        'options' => $puestoOptions ?? [],
                    ])
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha en la que firmo</label>
                    <input type="date" name="fecha_firma" class="form-control" value="{{ $dateValue('fecha_firma') }}" data-auto-fill="fecha_inicio_contrato">
                </div>
                <div class="form-group contract-data-wide">
                    <label class="form-label">FUNCIONES</label>
                    <textarea name="funciones" class="form-control" rows="5">{{ $textValue('funciones') }}</textarea>
                </div>
            </div>
        </div>
        <div class="card-footer contract-data-actions">
            <a href="{{ route('personal.index') }}" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">Guardar datos</button>
        </div>
    </form>
</div>
@section('scripts')
<script>
(function () {
    /* ---------- numero a texto en espanol ---------- */
    function numeroATexto(n) {
        if (n === 0) return 'cero';
        var unidades = ['','un','dos','tres','cuatro','cinco','seis','siete','ocho','nueve','diez','once','doce','trece','catorce','quince','dieciseis','diecisiete','dieciocho','diecinueve','veinte','veintiun','veintidos','veintitres','veinticuatro','veinticinco','veintiseis','veintisiete','veintiocho','veintinueve'];
        var decenas = ['','','treinta','cuarenta','cincuenta','sesenta','setenta','ochenta','noventa'];
        var centenas = ['','ciento','doscientos','trescientos','cuatrocientos','quinientos','seiscientos','setecientos','ochocientos','novecientos'];
        var resultado = '';
        if (n >= 1000000) { var m = Math.floor(n / 1000000); resultado += (m === 1 ? 'un' : numeroATexto(m)) + ' million'; if (m > 1) resultado += 'es'; n %= 1000000; if (n > 0) resultado += ' '; }
        if (n >= 1000) { var k = Math.floor(n / 1000); resultado += (k === 1 ? 'mil' : numeroATexto(k) + ' mil'); n %= 1000; if (n > 0) resultado += ' '; }
        if (n >= 100) { var c = Math.floor(n / 100); if (c === 1 && n % 100 === 0) { resultado += 'cien'; } else { resultado += centenas[c]; } n %= 100; if (n > 0) resultado += ' y '; }
        if (n >= 30) { var d = Math.floor(n / 10); resultado += decenas[d]; n %= 10; if (n > 0) resultado += ' y '; }
        if (n > 0 && n < 30) resultado += unidades[n];
        return resultado.trim();
    }

    function detectarMiles(valor) {
        var clean = valor.replace(/[^0-9.]/g, '');
        var num = parseFloat(clean);
        if (isNaN(num) || num <= 0 || num !== Math.floor(num)) return null;
        if (num >= 1000 && num % 1000 === 0) return Math.floor(num / 1000);
        return null;
    }

    function formatearNumero(valor) {
        var clean = valor.replace(/[^0-9.]/g, '');
        var num = parseFloat(clean);
        if (isNaN(num) || num <= 0) return '';
        var texto = numeroATexto(Math.floor(num));
        if (num % 1 !== 0) { var dec = Math.round((num % 1) * 100); texto += ' punto ' + numeroATexto(dec); }
        if (num >= 1000) texto += ' (' + Math.floor(num).toLocaleString('es-PE') + ')';
        return texto;
    }

    var form = document.querySelector('form');
    if (!form) return;

    /* ---------- Fecha inicio contrato -> Inicio prueba ---------- */
    var fechaInicio = form.querySelector('[name="fecha_inicio_contrato"]');
    var periodoPruebaInicio = form.querySelector('[name="periodo_prueba_inicio"]');
    if (fechaInicio && periodoPruebaInicio) {
        fechaInicio.addEventListener('change', function () {
            if (this.value && !periodoPruebaInicio.value) {
                periodoPruebaInicio.value = this.value;
            }
        });
    }

    /* ---------- Fecha firma -> Fecha inicio contrato ---------- */
    var fechaFirma = form.querySelector('[name="fecha_firma"]');
    if (fechaFirma && fechaInicio) {
        fechaFirma.addEventListener('change', function () {
            if (this.value && !fechaInicio.value) {
                fechaInicio.value = this.value;
            }
        });
    }

    /* ---------- Sueldo numerico -> texto ---------- */
    var camposSueldo = [
        { num: 'sueldo_hora_paradas', texto: 'sueldo_hora_paradas_texto' },
        { num: 'sueldo_dia_taller', texto: 'sueldo_dia_taller_texto' },
        { num: 'sueldo_num', texto: 'sueldo_texto' },
    ];

    camposSueldo.forEach(function (par) {
        var numInput = form.querySelector('[name="' + par.num + '"]');
        var textoInput = form.querySelector('[name="' + par.texto + '"]');
        if (!numInput || !textoInput) return;

        textoInput.dataset.autoFilled = textoInput.value ? '1' : '0';

        numInput.addEventListener('input', function () {
            var textoActual = formatearNumero(this.value);
            if (textoInput.dataset.autoFilled === '1' || !textoInput.value) {
                textoInput.value = textoActual;
                textoInput.dataset.autoFilled = '1';
            }
        });

        textoInput.addEventListener('input', function () {
            if (this.value) this.dataset.autoFilled = '0';
        });
    });
})();
</script>
@endsection

@endsection
