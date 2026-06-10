@extends('layouts.app')

@section('title', 'Documentos del trabajador - Proserge')

@section('content')
@php
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
.docs-page .docs-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0 0;
}
.docs-page .docs-download-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 8px;
    margin-top: 12px;
}
.docs-page .docs-download-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    font-size: 13px;
    color: #334155;
}
.docs-page .docs-download-option input {
    width: 16px;
    height: 16px;
    accent-color: #0d9488;
}
.docs-page .docs-download-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 14px;
}
@media (max-width: 900px) {
    .docs-page .docs-row {
        grid-template-columns: 1fr;
        align-items: stretch;
    }
    .docs-page .docs-actions {
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
                <div class="docs-summary">
                    <span class="docs-status docs-status-ok">{{ $documentSummary['aprobados'] ?? 0 }} aprobados</span>
                    <span class="docs-status">{{ $documentSummary['cargados'] ?? 0 }} cargados</span>
                    <span class="docs-status docs-status-missing">{{ $documentSummary['pendientes'] ?? 0 }} pendientes</span>
                    <span class="docs-status docs-status-missing">{{ $documentSummary['observados'] ?? 0 }} observados</span>
                    <span class="docs-status">{{ $documentSummary['no_aplica'] ?? 0 }} no aplica</span>
                </div>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('personal.index') }}" class="btn btn-outline btn-sm">Volver</a>
                <a href="{{ route('personal.contratos.index', $trabajador->id) }}" class="btn btn-outline btn-sm">Contratos</a>
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
                <span class="card-title">Contrato firmado</span>
                <span class="text-muted">{{ $contratoDatos?->signed_at ? 'Registrado' : 'Pendiente' }}</span>
            </div>
            <div class="card-body">
                <div class="docs-row">
                    <div>
                        <div class="docs-title">Contrato laboral firmado</div>
                        <div class="docs-meta">
                            @if($contratoDatos?->signed_at)
                                {{ $contratoDatos->signed_contract_original_name ?: 'Contrato firmado.pdf' }} - {{ $formatSize($contratoDatos->signed_contract_size) }}
                                <br>
                                Firmado/subido: {{ optional($contratoDatos->signed_at)->format('d/m/Y H:i') }}
                            @else
                                El contrato firmado todavia no fue subido.
                            @endif
                        </div>
                    </div>
                    <div>
                        <span class="docs-status {{ $contratoDatos?->signed_at ? 'docs-status-ok' : 'docs-status-missing' }}">
                            {{ $contratoDatos?->signed_at ? 'Cargado' : 'Faltante' }}
                        </span>
                    </div>
                    <div class="docs-actions">
                        @if($contratoDatos?->signed_at)
                            <a
                                href="{{ route('personal.documentos.contrato-firmado', $trabajador->id) }}"
                                class="btn btn-outline btn-xs docs-icon-btn"
                                title="Descargar contrato firmado"
                                aria-label="Descargar contrato firmado">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <path d="M7 10l5 5 5-5"/>
                                    <path d="M12 15V3"/>
                                </svg>
                            </a>
                        @else
                            <span class="text-muted" style="font-size:12px;">Se sube desde el check del listado de personal.</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if($ficha && $canDownloadDocuments)
            <div class="card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                    <span class="card-title">Descargar documentos</span>
                    <span class="text-muted">ZIP con carpeta del trabajador</span>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('personal.documentos.download-selected', $trabajador->id) }}">
                        @csrf
                        <label class="filter-compact-label">Tipos de documento</label>
                        <div class="docs-download-grid">
                            @foreach($documentTypeOptions as $docKey => $docLabel)
                                <label class="docs-download-option">
                                    <input type="checkbox" name="document_types[]" value="{{ $docKey }}" checked>
                                    <span>{{ $docLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="docs-download-footer">
                            <button type="submit" class="btn btn-primary btn-sm">Descargar seleccionados</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                <span class="card-title">Control documental</span>
                <span class="text-muted">{{ $documentSummary['total'] ?? 0 }} tipo(s)</span>
            </div>
            <div class="card-body">
                @if(!$ficha)
                    <p class="docs-empty">Este trabajador todavia no tiene ficha de colaborador asociada.</p>
                @else
                    @include('personal.documentos._document-status-table', [
                        'documentMatrix' => $documentMatrix,
                        'documentStateLabels' => $documentStateLabels,
                        'vidaLeyPhysicalStateLabels' => $vidaLeyPhysicalStateLabels,
                        'canUploadDocuments' => $canUploadDocuments,
                        'canReviewDocuments' => $canReviewDocuments,
                        'trabajador' => $trabajador,
                        'ficha' => $ficha,
                        'formatSize' => $formatSize,
                    ])
                @endif
            </div>
        </div>

        @if($extraArchivos->isNotEmpty())
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Otros archivos registrados</span>
                </div>
                <div class="card-body">
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
            </div>
        @endif

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
