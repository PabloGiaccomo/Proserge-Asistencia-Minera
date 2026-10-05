@php
    /**
     * Resumen de la atencion logistica de un requerimiento de transporte y sus
     * documentos descargables. Se reutiliza en modo edicion y en modo lectura.
     *
     * @var array $row
     */
    $documentosTransporte = collect(data_get($row, 'documentos', []))->values();
    $documentosLegacy = [
        ['slot' => 'legacy-vehiculo', 'label' => 'Documentacion del vehiculo', 'path' => (string) data_get($row, 'doc_vehiculo_path', '')],
        ['slot' => 'legacy-proserge', 'label' => 'Documentacion Proserge', 'path' => (string) data_get($row, 'doc_proserge_path', '')],
        ['slot' => 'legacy-mantenimiento', 'label' => 'Plan de mantenimiento', 'path' => (string) data_get($row, 'doc_mantenimiento_path', '')],
        ['slot' => 'legacy-checklist', 'label' => 'Checklist de enseres', 'path' => (string) data_get($row, 'doc_checklist_path', '')],
    ];
    $documentosLegacy = array_values(array_filter($documentosLegacy, fn (array $doc): bool => $doc['path'] !== ''));
    $fechaUso = trim(data_get($row, 'fecha_inicio_label', '') . ' - ' . data_get($row, 'fecha_fin_label', ''), ' -');
@endphp

<div class="lgt-service-summary">
    <div class="lgt-service-summary-item">
        <span class="lgt-service-summary-label">Origen</span>
        <span class="lgt-service-summary-value">{{ data_get($row, 'origen_label') ?: 'Sin definir' }}</span>
    </div>
    <div class="lgt-service-summary-item">
        <span class="lgt-service-summary-label">Unidad / placa</span>
        <span class="lgt-service-summary-value">{{ data_get($row, 'placas_asignadas') ?: 'Sin asignar' }}</span>
    </div>
    <div class="lgt-service-summary-item">
        <span class="lgt-service-summary-label">Capacidad</span>
        <span class="lgt-service-summary-value">{{ data_get($row, 'capacidad_camion') ?: 'Sin registrar' }}</span>
    </div>
    <div class="lgt-service-summary-item">
        <span class="lgt-service-summary-label">Estado logistico</span>
        <span class="lgt-service-summary-value">{{ data_get($row, 'estado_label') ?: 'Requerido' }}</span>
    </div>
    <div class="lgt-service-summary-item">
        <span class="lgt-service-summary-label">Recepcion</span>
        <span class="lgt-service-summary-value">
            {{ data_get($row, 'recepcion_estado_label') ?: 'Pendiente' }}
            @if(data_get($row, 'recepcion_fecha_label') && data_get($row, 'recepcion_fecha_label') !== '-')
                · {{ data_get($row, 'recepcion_fecha_label') }}
            @endif
        </span>
    </div>
    <div class="lgt-service-summary-item">
        <span class="lgt-service-summary-label">Fechas de uso</span>
        <span class="lgt-service-summary-value">{{ $fechaUso !== '' ? $fechaUso : 'Sin fechas' }}</span>
    </div>
    @if(data_get($row, 'comentario_cambio'))
        <div class="lgt-service-summary-item is-wide">
            <span class="lgt-service-summary-label">Ultimo comentario</span>
            <span class="lgt-service-summary-value">{{ data_get($row, 'comentario_cambio') }}</span>
        </div>
    @endif
    @if(data_get($row, 'incidencia_operativa'))
        <div class="lgt-service-summary-item is-wide">
            <span class="lgt-service-summary-label">Incidencia operativa</span>
            <span class="lgt-service-summary-value">{{ data_get($row, 'incidencia_operativa') }}</span>
        </div>
    @endif
    @if(data_get($row, 'recepcion_observacion'))
        <div class="lgt-service-summary-item is-wide">
            <span class="lgt-service-summary-label">Observacion de recepcion</span>
            <span class="lgt-service-summary-value">{{ data_get($row, 'recepcion_observacion') }}</span>
        </div>
    @endif
</div>

@php
    $historialAtencion = collect(data_get($row, 'eventos', []))->values();
@endphp

@if($historialAtencion->isNotEmpty())
    <details class="lgt-service-history">
        <summary>Historial de atencion ({{ $historialAtencion->count() }})</summary>
        <ul class="lgt-service-history-list">
            @foreach($historialAtencion as $evento)
                <li>
                    <span class="lgt-service-history-date">{{ $evento['fecha_label'] }}</span>
                    <span class="lgt-service-history-text">
                        {{ $evento['tipo_label'] }}
                        @if($evento['estado_nuevo_label'] !== '')
                            : {{ $evento['estado_anterior_label'] !== '' ? $evento['estado_anterior_label'] . ' → ' : '' }}{{ $evento['estado_nuevo_label'] }}
                        @endif
                        @if($evento['descripcion'] !== '')
                            · {{ $evento['descripcion'] }}
                        @endif
                    </span>
                    <span class="lgt-service-history-user">{{ $evento['usuario'] }}</span>
                </li>
            @endforeach
        </ul>
    </details>
@endif

@if($documentosLegacy !== [] || $documentosTransporte->isNotEmpty())
    <div class="lgt-service-docs">
        @foreach($documentosLegacy as $documento)
            <a class="lgt-service-doc-link" target="_blank"
               href="{{ route('logistica.transportes.documento', ['id' => data_get($row, 'id'), 'slot' => $documento['slot']]) }}">
               📄 {{ $documento['label'] }}
            </a>
        @endforeach
        @foreach($documentosTransporte as $index => $documento)
            <a class="lgt-service-doc-link" target="_blank"
               href="{{ route('logistica.transportes.documento', ['id' => data_get($row, 'id'), 'slot' => 'adicional-' . $index]) }}">
               📄 {{ data_get($documento, 'nombre') ?: 'Documento ' . ($index + 1) }}
            </a>
        @endforeach
    </div>
@endif
