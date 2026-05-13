@extends('layouts.app')

@section('title', 'Documentos del trabajador - Proserge')

@section('content')
@php
    $attachedCatalogRows = collect($requirements)
        ->filter(fn (array $requirement, string $key): bool => $attachedByType->has($key));
    $missingRequiredRows = collect($requirements)
        ->filter(fn (array $requirement, string $key): bool => (bool) ($requirement['required'] ?? false) && !$attachedByType->has($key));
    $missingOptionalRows = collect($requirements)
        ->filter(fn (array $requirement, string $key): bool => !(bool) ($requirement['required'] ?? false) && !$attachedByType->has($key));

    $formatSize = function ($bytes): string {
        $bytes = (int) $bytes;
        if ($bytes <= 0) {
            return '-';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return number_format($bytes / 1024, 1) . ' KB';
    };
@endphp

<style>
.docs-page .docs-grid {
    display: grid;
    gap: 16px;
}
.docs-page .docs-row {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(150px, 220px) minmax(220px, 300px);
    gap: 12px;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #e2e8f0;
}
.docs-page .docs-row:last-child {
    border-bottom: 0;
}
.docs-page .docs-title {
    font-weight: 700;
    color: #0f172a;
}
.docs-page .docs-label {
    color: #475569;
    font-size: 13px;
    line-height: 1.35;
}
.docs-page .docs-meta {
    color: #64748b;
    font-size: 12px;
}
.docs-page .docs-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
}
.docs-page .docs-upload {
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
}
.docs-page .docs-upload input[type="file"] {
    max-width: 190px;
    font-size: 12px;
}
.docs-page .docs-icon-btn {
    width: 34px;
    height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
}
.docs-page .docs-icon-btn svg {
    width: 17px;
    height: 17px;
}
.docs-page .docs-empty {
    margin: 0;
    color: #64748b;
}
.docs-page .docs-status {
    display: inline-flex;
    align-items: center;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: #f1f5f9;
    color: #475569;
}
.docs-page .docs-status-ok {
    background: #dcfce7;
    color: #166534;
}
.docs-page .docs-status-missing {
    background: #fef3c7;
    color: #92400e;
}
@media (max-width: 900px) {
    .docs-page .docs-row {
        grid-template-columns: 1fr;
        align-items: stretch;
    }
    .docs-page .docs-actions,
    .docs-page .docs-upload {
        justify-content: flex-start;
    }
}
</style>

<div class="module-page docs-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
                <h1 class="page-title">Documentos</h1>
                <p class="page-subtitle">{{ $trabajador->nombre_completo }} - {{ $trabajador->tipo_documento ?: 'DNI' }} {{ $trabajador->numero_documento ?: $trabajador->dni }}</p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('personal.index') }}" class="btn btn-outline btn-sm">Volver</a>
                <a href="{{ route('personal.edit', $trabajador->id) }}" class="btn btn-primary btn-sm">Editar trabajador</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom:16px;">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger" style="margin-bottom:16px;">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="docs-grid">
        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <span class="card-title">Documentos cargados</span>
                <span class="text-muted">{{ $attachedCatalogRows->count() }} documento(s)</span>
            </div>
            <div class="card-body">
                @if($attachedCatalogRows->isEmpty())
                    <p class="docs-empty">Aun no hay documentos cargados en la ficha.</p>
                @else
                    @foreach($attachedCatalogRows as $docKey => $requirement)
                        @php $archivo = $attachedByType->get($docKey); @endphp
                        <div class="docs-row">
                            <div>
                                <div class="docs-title">{{ $requirement['label'] }}</div>
                                <div class="docs-meta">
                                    {{ $archivo->nombre_original ?: 'Documento guardado' }} - {{ $formatSize($archivo->size) }}
                                </div>
                            </div>
                            <div>
                                <span class="docs-status docs-status-ok">Cargado</span>
                            </div>
                            <div class="docs-actions">
                                <a
                                    href="{{ route('personal.fichas.archivos.download', $archivo->id) }}"
                                    class="btn btn-outline btn-xs docs-icon-btn"
                                    title="Descargar documento"
                                    aria-label="Descargar documento">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <path d="M7 10l5 5 5-5"/>
                                        <path d="M12 15V3"/>
                                    </svg>
                                </a>
                                <form method="POST" action="{{ route('personal.documentos.store', $trabajador->id) }}" enctype="multipart/form-data" class="docs-upload">
                                    @csrf
                                    <input type="file" name="documentos[{{ $docKey }}]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required>
                                    <button
                                        type="submit"
                                        class="btn btn-outline btn-xs docs-icon-btn"
                                        title="Reemplazar documento"
                                        aria-label="Reemplazar documento">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                            <path d="M17 8l-5-5-5 5"/>
                                            <path d="M12 3v12"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                @endif

                @if($extraArchivos->isNotEmpty())
                    <div style="margin-top:16px; padding-top:16px; border-top:1px solid #e2e8f0;">
                        <h3 class="card-title" style="font-size:15px; margin:0 0 8px;">Otros archivos registrados</h3>
                        @foreach($extraArchivos as $archivo)
                            <div class="docs-row">
                                <div>
                                    <div class="docs-title">{{ str_replace('_', ' ', ucfirst((string) $archivo->tipo)) }}</div>
                                    <div class="docs-meta">{{ $archivo->nombre_original ?: 'Archivo guardado' }} - {{ $formatSize($archivo->size) }}</div>
                                </div>
                                <div><span class="docs-status docs-status-ok">Cargado</span></div>
                                <div class="docs-actions">
                                    <a href="{{ route('personal.fichas.archivos.download', $archivo->id) }}" class="btn btn-outline btn-xs docs-icon-btn" title="Descargar documento" aria-label="Descargar documento">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                            <path d="M7 10l5 5 5-5"/>
                                            <path d="M12 15V3"/>
                                        </svg>
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <span class="card-title">Documentos obligatorios faltantes</span>
                <span class="text-muted">{{ $missingRequiredRows->count() }} pendiente(s)</span>
            </div>
            <div class="card-body">
                @if($missingRequiredRows->isEmpty())
                    <p class="docs-empty">No hay documentos obligatorios pendientes.</p>
                @else
                    @foreach($missingRequiredRows as $docKey => $requirement)
                        <div class="docs-row">
                            <div>
                                <div class="docs-title">{{ $requirement['label'] }}</div>
                                <div class="docs-label">Documento obligatorio pendiente.</div>
                            </div>
                            <div>
                                <span class="docs-status docs-status-missing">Faltante</span>
                            </div>
                            <form method="POST" action="{{ route('personal.documentos.store', $trabajador->id) }}" enctype="multipart/form-data" class="docs-upload">
                                @csrf
                                <input type="file" name="documentos[{{ $docKey }}]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required>
                                <button type="submit" class="btn btn-primary btn-xs">Agregar</button>
                            </form>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <span class="card-title">Otros documentos que podrian faltar</span>
                <span class="text-muted">{{ $missingOptionalRows->count() }} opcional(es)</span>
            </div>
            <div class="card-body">
                @if($missingOptionalRows->isEmpty())
                    <p class="docs-empty">No hay documentos opcionales pendientes segun la lista actual.</p>
                @else
                    @foreach($missingOptionalRows as $docKey => $requirement)
                        <div class="docs-row">
                            <div>
                                <div class="docs-title">{{ $requirement['label'] }}</div>
                                <div class="docs-label">Puede adjuntarse cuando corresponda.</div>
                            </div>
                            <div>
                                <span class="docs-status docs-status-missing">Podria faltar</span>
                            </div>
                            <form method="POST" action="{{ route('personal.documentos.store', $trabajador->id) }}" enctype="multipart/form-data" class="docs-upload">
                                @csrf
                                <input type="file" name="documentos[{{ $docKey }}]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required>
                                <button type="submit" class="btn btn-outline btn-xs">Agregar</button>
                            </form>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

        @if($isMujer)
            <div class="card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                    <span class="card-title">Gestacion</span>
                    <span class="text-muted">{{ $gestacionBloqueos->count() }} periodo(s)</span>
                </div>
                <div class="card-body">
                    @if($gestacionBloqueos->isEmpty())
                        <p class="docs-empty">No hay periodos de gestacion registrados en Bienestar.</p>
                    @else
                        @foreach($gestacionBloqueos as $bloqueo)
                            @php
                                $isFuture = $bloqueo->fecha_inicio && $bloqueo->fecha_inicio->startOfDay()->greaterThan($today);
                            @endphp
                            <div class="docs-row">
                                <div>
                                    <div class="docs-title">Periodo de gestacion</div>
                                    <div class="docs-meta">
                                        {{ optional($bloqueo->fecha_inicio)->format('d/m/Y') }} al {{ optional($bloqueo->fecha_fin)->format('d/m/Y') }}
                                    </div>
                                </div>
                                <div>
                                    <span class="docs-status {{ $isFuture ? '' : 'docs-status-ok' }}">{{ $isFuture ? 'Programado' : 'Disponible' }}</span>
                                </div>
                                <div class="docs-actions">
                                    @if($isFuture)
                                        <span class="text-muted" style="font-size:12px;">PDF disponible al iniciar el periodo.</span>
                                    @else
                                        <a href="{{ route('personal.documentos.gestacion.pdf', ['id' => $trabajador->id, 'bloqueoId' => $bloqueo->id]) }}" class="btn btn-outline btn-xs docs-icon-btn" title="Descargar PDF de gestacion" aria-label="Descargar PDF de gestacion">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                                <path d="M7 10l5 5 5-5"/>
                                                <path d="M12 15V3"/>
                                            </svg>
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
