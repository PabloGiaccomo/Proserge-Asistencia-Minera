@extends('layouts.app')
@section('title', 'Carga de habilitacion masiva - Proserge')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/bulk-review.css') }}?v={{ filemtime(public_path('css/bulk-review.css')) }}">
@endpush
@section('content')
<div class="bulk-preview">
    <a class="bulk-back" href="{{ route('personal.habilitacion-minera.index') }}">&larr; Habilitacion minera</a>
    <header class="bulk-heading"><div><h1>Carga de habilitacion masiva</h1><p>Programaciones y resultados de cursos / EMO</p></div><span class="bulk-badge neutral">Revision y confirmacion</span></header>
    @if((($canViewManagement ?? false) && ($canConfigure ?? false)) || ($canCreateExam ?? false) || ($canEditExam ?? false))
    <nav class="bulk-management" aria-label="Configurar minas y examenes">
        @if(($canViewManagement ?? false) && ($canConfigure ?? false))<a class="btn btn-outline" href="{{ route('personal.habilitacion-minera.index', ['abrir' => 'configuracion']) }}" target="_blank" rel="noopener">Configurar minas y requisitos</a>@endif
        @if($canCreateExam ?? false)<a class="btn btn-outline" href="{{ route('personal.habilitacion-minera.index', ['abrir' => 'agregar-examen']) }}" target="_blank" rel="noopener">Agregar examen</a>@endif
        @if($canEditExam ?? false)<a class="btn btn-outline" href="{{ route('personal.habilitacion-minera.index', ['abrir' => 'editar-examen']) }}" target="_blank" rel="noopener">Editar examen</a>@endif
    </nav>
    @endif
    <form class="bulk-upload" method="POST" enctype="multipart/form-data" action="{{ route('personal.habilitacion-minera.bulk-preview') }}">
        @csrf
        <div><label for="bulk-file">Archivo Excel</label><input id="bulk-file" type="file" name="archivo" accept=".xlsx" required></div>
        <button class="btn btn-primary" type="submit">Revisar archivo</button>
        @error('archivo')<p class="bulk-error" role="alert">{{ $message }}</p>@enderror
    </form>
    <p class="bulk-notice">La revision no guarda cambios ni crea asignaciones a minas.</p>
    <p class="bulk-notice">Cada fila debe indicar su mina. Al confirmar, el curso se aplica directamente solo a esa mina; usarlo en otra requiere una convalidacion permitida y confirmada.</p>
    @if($rows !== null)
        <header class="bulk-results-heading"><h2>Revision del archivo</h2><span>{{ count($rows) }} filas &middot; <strong>{{ $report['with_issues'] }} con observaciones</strong></span></header>
        <nav class="bulk-views" aria-label="Estado de las filas">
            <button type="button" class="bulk-reset" data-bulk-category="changes" aria-pressed="false">Cambios propuestos ({{ $report['changes'] ?? 0 }})</button>
            <button type="button" class="bulk-reset" data-bulk-category="issues" aria-pressed="false">Con observaciones ({{ $report['with_issues'] }})</button>
            <button type="button" class="bulk-reset" data-bulk-category="unchanged" aria-pressed="false">Sin cambios ({{ $report['unchanged'] ?? 0 }})</button>
            <button type="button" class="bulk-reset" data-bulk-category="all" aria-pressed="false">Todas ({{ count($rows) }})</button>
        </nav>
        @if($token ?? null)
        @if((($canViewManagement ?? false) && ($canConfigure ?? false)) || ($canCreateExam ?? false) || ($canEditExam ?? false))<p class="bulk-notice">Despues de cambiar minas o examenes, vuelve aqui y pulsa Revisar de nuevo.</p>@endif
        <form method="POST" action="{{ route('personal.habilitacion-minera.bulk-recheck') }}" class="bulk-confirm">
            @csrf<input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" class="btn btn-outline">Revisar de nuevo</button>
        </form>
        <form method="POST" action="{{ route('personal.habilitacion-minera.bulk-confirm') }}" class="bulk-confirm">
            @csrf<input type="hidden" name="token" value="{{ $token }}">
            <label><input type="checkbox" name="confirmar" value="1" required> Confirmo los cambios propuestos del archivo</label>
            <button type="submit" class="btn btn-primary" @disabled($report['with_issues'] || !$report['changes'])>Aplicar cambios ({{ $report['changes'] }})</button>
            @if($report['with_issues'])<p>Corrige las filas observadas antes de confirmar.</p>@endif
        </form>
        @endif
        <nav class="bulk-categories" aria-label="Tipos de observacion">
            @foreach($report['groups'] as $group)
                <button type="button" class="bulk-category" data-bulk-category="{{ $group['key'] }}" aria-pressed="false">
                    <span class="bulk-category-count {{ $group['count'] ? 'has-issues' : '' }}">{{ $group['count'] }}</span>
                    <span>{{ ['dni' => 'DNI no reconocidos', 'examen' => 'Examenes por revisar', 'mina' => 'Minas por revisar', 'identidad' => 'Nombres por revisar', 'archivo' => 'Fechas y datos', 'historial' => 'Conflictos de historial'][$group['key']] }}</span>
                </button>
            @endforeach
        </nav>
        <div class="bulk-toolbar">
            <div class="bulk-search"><label for="bulk-search">Buscar en el archivo</label><input type="search" id="bulk-search" placeholder="DNI, nombre, examen u observacion..." autocomplete="off"></div>
            <div><label for="bulk-filter">Observaciones</label><select id="bulk-filter">
                <option value="changes">Cambios propuestos</option><option value="unchanged">Sin cambios</option>
                <option value="issues" @selected($report['with_issues'] > 0)>Con observaciones</option>
                <option value="all" @selected($report['with_issues'] === 0)>Todas las filas</option>
                <option value="clean">Sin observaciones en el cruce</option>
                @foreach($report['groups'] as $group)<option value="{{ $group['key'] }}">{{ $group['label'] }} ({{ $group['count'] }})</option>@endforeach
            </select></div>
            <div><label for="bulk-type">Hoja</label><select id="bulk-type"><option value="all">Ambas hojas</option><option value="Programacion">Programaciones</option><option value="Resultado">Resultados</option></select></div>
            <button type="button" class="bulk-reset" id="bulk-reset">Limpiar filtros</button>
        </div>
        <div class="bulk-list-heading"><span>Trabajador</span><span>Curso / EMO</span><span>Fecha</span><span>Revision</span><span></span></div>
        <div class="bulk-list" id="bulk-list">
            @foreach($rows as $row)
                <details class="bulk-row" data-bulk-disposition="{{ $row['disposition'] ?? '' }}" data-bulk-categories="{{ implode(' ', $row['categories']) }}" data-bulk-issues="{{ count($row['issues']) ? '1' : '0' }}" data-bulk-type="{{ $row['type'] }}">
                    <summary>
                        <span class="bulk-worker"><strong>{{ $row['name'] ?: 'Nombre sin registrar' }}</strong><small>{{ $row['dni'] }} &middot; {{ $row['sheet'] }}, fila {{ $row['line'] }}</small></span>
                        <span class="bulk-course"><strong>{{ $row['exam'] ?: 'Examen sin registrar' }}</strong><small>{{ $row['type'] }} &middot; {{ $row['matched_mine'] ?? $row['mine'] ?? 'Mina sin indicar' }}</small></span>
                        <span class="bulk-date">{{ $row['date'] ?? 'Sin fecha valida' }}</span>
                        <span class="bulk-row-state"><span class="bulk-badge {{ count($row['issues']) ? 'warning' : 'neutral' }}">{{ count($row['issues']) ? count($row['issues']).(count($row['issues']) === 1 ? ' observacion' : ' observaciones') : 'Sin observaciones' }}</span><small>{{ $row['issues'][0] ?? ($row['action'] ?? 'Pendiente de cruce') }}</small></span>
                        <span class="bulk-chevron" aria-hidden="true">&#8250;</span>
                    </summary>
                    <div class="bulk-detail">
                        <section><h3>Revision de la fila</h3><ul>@forelse($row['issues'] as $issue)<li>{{ $issue }}</li>@empty<li>Sin observaciones en este cruce. Pendiente de validacion final.</li>@endforelse</ul><h3>Accion propuesta</h3><p>{{ $row['action'] ?? 'Pendiente de cruce' }}</p>
                        @if(($canConfigure ?? false) && ($token ?? null) && ($row['mina_id'] ?? null) && ($row['examen_id'] ?? null) && count($row['issues']) === 1 && in_array('El curso o EMO no esta configurado como requisito activo para la mina indicada.', $row['issues'], true))
                            <form method="POST" action="{{ route('personal.habilitacion-minera.bulk-configure-requirement') }}" class="bulk-confirm">
                                @csrf
                                <input type="hidden" name="token" value="{{ $token }}">
                                <input type="hidden" name="mina_id" value="{{ $row['mina_id'] }}">
                                <input type="hidden" name="examen_id" value="{{ $row['examen_id'] }}">
                                <input type="hidden" name="obligatorio" value="1">
                                <p>Configurar <strong>{{ $row['matched_exam'] }}</strong> como requisito obligatorio de <strong>{{ $row['matched_mine'] }}</strong>. Se recalcularan las habilitaciones de esa mina.</p>
                                <label><input type="checkbox" name="confirmar" value="1" required> Confirmo este cambio de configuracion</label>
                                <button type="submit" class="btn btn-outline">Agregar requisito a la mina</button>
                            </form>
                        @endif
                        </section>
                        <dl><div><dt>Nombre en Excel</dt><dd>{{ $row['name'] ?: '-' }}</dd></div><div><dt>Nombre en Personal</dt><dd>{{ $row['personal'] ?? 'Sin coincidencia unica' }}</dd></div><div><dt>Examen en catalogo</dt><dd>{{ $row['matched_exam'] ?? 'Sin coincidencia activa unica' }}</dd></div><div><dt>Mina de destino</dt><dd>{{ $row['matched_mine'] ?? $row['mine'] ?? 'Sin coincidencia activa unica' }}</dd></div><div><dt>Resultado / nota</dt><dd>{{ $row['result'] ?: '-' }} / {{ $row['score'] ?? '-' }}</dd></div><div><dt>Intentos despues de la carga</dt><dd>{{ isset($row['attempts_used']) ? $row['attempts_used'].'/'.$row['attempts_limit'] : '-' }} @if($row['attempts_exceeded'] ?? false)<span class="bulk-over-limit">Excedido</span>@endif</dd></div><div><dt>Minas por actualizar</dt><dd>{{ $row['mines_pending_sync'] ?? 0 }}</dd></div><div><dt>Vencimiento</dt><dd>{{ $row['expiry'] ?? '-' }}</dd></div><div><dt>Programacion vinculada</dt><dd>{{ $row['matched_schedule'] ?? '-' }}</dd></div><div><dt>Observacion del Excel</dt><dd>{{ $row['observation'] ?: '-' }}</dd></div></dl>
                    </div>
                </details>
            @endforeach
            <div class="bulk-empty" id="bulk-filter-empty" hidden><h3>No hay filas en esta categoria.</h3><p>No se encontraron coincidencias con los filtros actuales.</p></div>
        </div>
        <footer class="bulk-pagination"><span id="bulk-count" role="status" aria-live="polite"></span><div><label for="bulk-size">Filas</label><select id="bulk-size"><option>20</option><option>50</option><option>100</option></select><button type="button" id="bulk-prev" aria-label="Pagina anterior" title="Pagina anterior">&larr;</button><span id="bulk-page"></span><button type="button" id="bulk-next" aria-label="Pagina siguiente" title="Pagina siguiente">&rarr;</button></div></footer>
    @endif
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/bulk-review.js') }}?v={{ filemtime(public_path('js/bulk-review.js')) }}" defer></script>
@endpush
