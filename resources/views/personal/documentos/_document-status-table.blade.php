@php
    $trabajadorForDocs = $trabajador ?? $ficha?->personal;
    $canUploadDocuments = $canUploadDocuments ?? false;
    $canDownloadDocuments = $canDownloadDocuments ?? false;
    $canReviewDocuments = $canReviewDocuments ?? false;
    $documentStateLabels = $documentStateLabels ?? \App\Modules\Personal\Support\PersonalFichaCatalog::documentStateLabels();
    $vidaLeyPhysicalStateLabels = $vidaLeyPhysicalStateLabels ?? \App\Modules\Personal\Support\PersonalFichaCatalog::vidaLeyPhysicalStateLabels();
    $formatSize = $formatSize ?? function ($bytes): string {
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

@once
    
@endonce

<div class="document-status-table-wrap">
    <table class="document-status-table">
        <thead>
            <tr>
                <th>Documento</th>
                <th>Estado</th>
                <th>Archivo</th>
                <th>Observacion / Vida Ley</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse($documentMatrix as $row)
                @php
                    $archivo = $row['archivo'] ?? null;
                    $estado = $row['estado'] ?? 'PENDIENTE';
                    $canMarkNoAplica = $canReviewDocuments && (($row['conditional'] ?? false) || !($row['required'] ?? false) || !($row['applies'] ?? true));
                @endphp
                <tr>
                    <td>
                        <strong>{{ $row['label'] }}</strong>
                        @if($row['required'])
                            <div class="doc-note">Obligatorio{{ ($row['conditional'] ?? false) ? ' condicional' : '' }}</div>
                        @else
                            <div class="doc-note">Opcional / segun corresponda</div>
                        @endif
                        @if(!empty($row['description']))
                            <div class="doc-note">{{ $row['description'] }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="doc-state-badge doc-state-{{ $estado }}">{{ $row['estado_label'] ?? ($documentStateLabels[$estado] ?? $estado) }}</span>
                        @if(!($row['applies'] ?? true))
                            <div class="doc-note">No exigido por los datos actuales.</div>
                        @elseif($row['pending_review'] ?? false)
                            <div class="doc-note">Tiene archivo, falta revision.</div>
                        @elseif($row['missing_file'] ?? false)
                            <div class="doc-note">Falta cargar archivo.</div>
                        @endif
                    </td>
                    <td>
                        @if($archivo && $canDownloadDocuments)
                            <a href="{{ route('personal.fichas.archivos.download', $archivo->id) }}">
                                {{ $archivo->nombre_original ?: 'Descargar documento' }}
                            </a>
                            <div class="doc-note">{{ $formatSize($archivo->size) }} - {{ optional($archivo->created_at)->format('d/m/Y H:i') }}</div>
                        @elseif($archivo)
                            <span>{{ $archivo->nombre_original ?: 'Documento cargado' }}</span>
                            <div class="doc-note">{{ $formatSize($archivo->size) }} - {{ optional($archivo->created_at)->format('d/m/Y H:i') }}</div>
                        @else
                            <span class="text-muted">Sin archivo</span>
                        @endif

                        @if($canUploadDocuments && $trabajadorForDocs && ($row['applies'] ?? true))
                            <form method="POST" action="{{ route('personal.documentos.store', $trabajadorForDocs->id) }}" enctype="multipart/form-data" class="doc-inline-form">
                                @csrf
                                <input type="file" name="documentos[{{ $row['key'] }}]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" required>
                                <button type="submit" class="btn btn-outline btn-xs">{{ $archivo ? 'Reemplazar' : 'Subir' }}</button>
                            </form>
                        @endif
                    </td>
                    <td>
                        @if($row['observacion'])
                            <div>{{ $row['observacion'] }}</div>
                        @else
                            <span class="text-muted">Sin observacion.</span>
                        @endif

                        @if(($row['special'] ?? null) === 'vida_ley')
                            <div class="doc-note">
                                Entrega fisica:
                                <strong>{{ $row['vida_ley_entrega_fisica_label'] ?? 'Pendiente de registrar' }}</strong>
                            </div>
                            @if($row['vida_ley_entrega_observacion'])
                                <div class="doc-note">{{ $row['vida_ley_entrega_observacion'] }}</div>
                            @endif
                            @if($canReviewDocuments && $trabajadorForDocs)
                                <form method="POST" action="{{ route('personal.documentos.estado', ['id' => $trabajadorForDocs->id, 'tipo' => $row['key']]) }}" class="doc-inline-form">
                                    @csrf
                                    <input type="hidden" name="estado" value="{{ $estado }}">
                                    <select name="vida_ley_entrega_fisica" required>
                                        @foreach($vidaLeyPhysicalStateLabels as $physicalKey => $physicalLabel)
                                            <option value="{{ $physicalKey }}" {{ ($row['vida_ley_entrega_fisica'] ?? '') === $physicalKey ? 'selected' : '' }}>{{ $physicalLabel }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="vida_ley_entrega_observacion" value="{{ $row['vida_ley_entrega_observacion'] }}" placeholder="Observacion fisica">
                                    <button type="submit" class="btn btn-outline btn-xs">Guardar</button>
                                </form>
                            @endif
                        @endif
                    </td>
                    <td class="doc-actions-stack">
                        @if($canReviewDocuments && $trabajadorForDocs)
                            @if($archivo && $estado !== 'APROBADO')
                                <form method="POST" action="{{ route('personal.documentos.estado', ['id' => $trabajadorForDocs->id, 'tipo' => $row['key']]) }}" class="doc-inline-form">
                                    @csrf
                                    <input type="hidden" name="estado" value="APROBADO">
                                    <button type="submit" class="btn btn-primary btn-xs">Aprobar</button>
                                </form>
                            @endif

                            @if($row['applies'] ?? true)
                                <form method="POST" action="{{ route('personal.documentos.estado', ['id' => $trabajadorForDocs->id, 'tipo' => $row['key']]) }}" class="doc-inline-form">
                                    @csrf
                                    <input type="hidden" name="estado" value="OBSERVADO">
                                    <input type="text" name="observacion" value="{{ $estado === 'OBSERVADO' ? $row['observacion'] : '' }}" placeholder="Observacion" required>
                                    <button type="submit" class="btn btn-outline btn-xs">Observar</button>
                                </form>
                            @endif

                            @if($canMarkNoAplica && $estado !== 'NO_APLICA')
                                <form method="POST" action="{{ route('personal.documentos.estado', ['id' => $trabajadorForDocs->id, 'tipo' => $row['key']]) }}" class="doc-inline-form">
                                    @csrf
                                    <input type="hidden" name="estado" value="NO_APLICA">
                                    <input type="text" name="observacion" placeholder="Motivo si corresponde">
                                    <button type="submit" class="btn btn-outline btn-xs">No aplica</button>
                                </form>
                            @endif
                        @else
                            <span class="text-muted">Solo lectura</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No hay documentos configurados.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
