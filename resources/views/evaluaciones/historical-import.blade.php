@extends('layouts.app')
@section('title', 'Carga historica de evaluaciones - Proserge')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/evaluations-dashboard.css') }}?v={{ filemtime(public_path('css/evaluations-dashboard.css')) }}">
@endpush
@section('content')
<div class="evaluations-page">
    <a href="{{ route('evaluaciones.index') }}">Volver a evaluaciones</a>
    <header class="evaluations-heading"><div><h1>Carga de datos históricos</h1><p>Asistencia, evaluaciones diarias, supervisores y residentes.</p></div></header>
    @if($errors->any())<div class="eval-alert eval-alert-error" role="alert">{{ $errors->first() }}</div>@endif
    <section class="eval-section">
        <div class="eval-section-heading"><h2>Archivos de origen</h2></div>
        <form method="POST" action="{{ route('evaluaciones.historico.preview') }}" enctype="multipart/form-data" class="eval-form" data-historical-submit>
            @csrf
            <div class="eval-form-grid eval-form-grid-2">
                <div class="eval-field"><label for="hist-control">Control de asistencia</label><input id="hist-control" name="control" type="file" accept=".xlsx" required>@error('control')<small class="eval-field-error">{{ $message }}</small>@enderror</div>
                <div class="eval-field"><label for="hist-consolidado">Consolidado de evaluaciones</label><input id="hist-consolidado" name="consolidado" type="file" accept=".xlsx" required>@error('consolidado')<small class="eval-field-error">{{ $message }}</small>@enderror</div>
            </div>
            <div class="eval-actions"><button class="btn btn-primary" type="submit">Revisar archivos</button></div>
        </form>
    </section>
    @if($batch)
    <section class="eval-section">
        <div class="eval-section-heading"><h2>Revisión de la carga</h2><a class="btn btn-outline" href="{{ route('evaluaciones.historico.reporte', ['lote' => $batch->id]) }}">Descargar reporte</a></div>
        <div class="eval-form">
            <p>{{ count($manifest['proposed']) }} cambios propuestos · {{ count($manifest['blocked']) }} filas bloqueadas · {{ count($manifest['unchanged']) }} sin cambios · {{ count($manifest['warnings']) }} advertencias</p>
            <form method="GET" action="{{ route('evaluaciones.historico.importar') }}">
                <input type="hidden" name="lote" value="{{ $batch->id }}">
                <div class="eval-form-grid eval-form-grid-3">
                    <div class="eval-field"><label for="hist-q">Buscar DNI, nombre, hoja o motivo</label><input id="hist-q" name="q" value="{{ $filters['q'] ?? '' }}"></div>
                    <div class="eval-field"><label for="hist-status">Estado</label><select id="hist-status" name="estado">@foreach(['proposed' => 'Cambios propuestos', 'blocked' => 'Con errores', 'unchanged' => 'Sin cambios', 'warnings' => 'Advertencias por revisar', 'all' => 'Todas las filas'] as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach</select></div>
                    <div class="eval-field"><label for="hist-type">Tipo</label><select id="hist-type" name="tipo"><option value="">Todos</option>@foreach(['attendance' => 'Asistencia histórica', 'daily' => 'Evaluación diaria', 'supervisor' => 'Supervisor', 'resident' => 'Residente'] as $key => $label)<option value="{{ $key }}" @selected(($filters['tipo'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></div>
                </div>
                <div class="eval-actions"><a class="btn btn-outline" href="{{ route('evaluaciones.historico.importar', ['lote' => $batch->id]) }}">Limpiar filtros</a><button class="btn btn-outline" type="submit">Filtrar</button></div>
            </form>
            @if(count($manifest['blocked']))<div class="eval-alert eval-alert-error">Corrige todas las filas bloqueadas y revisa los archivos nuevamente.</div>@endif
            <div class="eval-table-wrap"><table class="eval-table"><thead><tr><th>Hoja / fila</th><th>DNI</th><th>Fecha</th><th>Nota</th><th>Revisión</th></tr></thead><tbody>
                @forelse($paginator as $row)
                <tr><td>{{ $row['source_sheet'] ?? $row['source_file'] ?? '-' }}<small class="block">Fila {{ $row['source_row'] ?? '-' }}</small></td><td>{{ $row['dni'] ?? $row['dni_ref'] ?? '-' }}<small class="block">{{ $row['personal_name'] ?? $row['name'] ?? '' }}</small>@if(!empty($row['name']) && $row['name'] !== ($row['personal_name'] ?? $row['name']))<small class="block">Excel: {{ $row['name'] }}</small>@endif</td><td>{{ $row['date'] ?? '-' }}</td><td>{{ $row['payload']['resultado_final'] ?? $row['payload']['total'] ?? $row['total'] ?? '-' }}@if(($row['payload']['calculo_version'] ?? '') === 'HISTORICA')<small class="block">Cálculo histórico</small>@endif</td><td>{{ $row['reason'] ?? (['proposed' => 'Listo para guardar', 'unchanged' => 'Sin cambios', 'blocked' => 'Revisar datos'][$row['review_status']]) }}</td></tr>
                @empty<tr><td colspan="5">No hay filas con estos filtros.</td></tr>@endforelse
            </tbody></table></div>
            <div class="eval-actions"><span>{{ $paginator->total() }} filas</span>@if($paginator->previousPageUrl())<a class="btn btn-outline" href="{{ $paginator->previousPageUrl() }}">Anterior</a>@endif<span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>@if($paginator->nextPageUrl())<a class="btn btn-outline" href="{{ $paginator->nextPageUrl() }}">Siguiente</a>@endif</div>
            <form method="POST" action="{{ route('evaluaciones.historico.confirmar') }}" data-historical-submit>
                @csrf<input type="hidden" name="lote_id" value="{{ $batch->id }}"><input type="hidden" name="hash" value="{{ $batch->hash }}">
                <label class="eval-check"><input type="checkbox" name="confirmacion" value="1" required @disabled(count($manifest['blocked']) || !count($manifest['proposed']))><span>He revisado el reporte y confirmo la carga única de estos datos.</span></label>
                <div class="eval-actions"><button class="btn btn-primary" type="submit" @disabled(count($manifest['blocked']) || !count($manifest['proposed']))>Aplicar carga ({{ count($manifest['proposed']) }})</button></div>
            </form>
        </div>
    </section>
    @endif
</div>
@endsection
@push('scripts')
<script>
document.querySelectorAll('[data-historical-submit]').forEach(form => {
    form.addEventListener('submit', event => {
        if (form.dataset.submitting) { event.preventDefault(); return; }
        form.dataset.submitting = '1';
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.textContent = 'Procesando…';
    });
});
window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
</script>
@endpush
