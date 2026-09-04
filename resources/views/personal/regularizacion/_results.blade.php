@php
    $rows = $dashboard['rows'];
    $metrics = $dashboard['metrics'];
    $section = $dashboard['section'];
    $query = array_filter([
        'section' => $section,
        'q' => request('q'),
        'type' => request('type'),
        'per_page' => request('per_page'),
    ], fn ($value) => filled($value));
@endphp

<section class="regularization-metrics" aria-label="Resumen de regularización">
    @if($section === 'pending')
        <a href="{{ route('personal.regularizacion.index', ['section' => 'pending']) }}">
            <strong>{{ $metrics['pending_workers'] }}</strong>
            <span>Trabajadores pendientes</span>
        </a>
        <a href="{{ route('personal.regularizacion.index', ['section' => 'pending', 'type' => 'fields']) }}">
            <strong>{{ $metrics['missing_fields_workers'] }}</strong>
            <span>Con ficha incompleta</span>
        </a>
        <a href="{{ route('personal.regularizacion.index', ['section' => 'pending', 'type' => 'documents']) }}">
            <strong>{{ $metrics['missing_documents_workers'] }}</strong>
            <span>Con documentos pendientes</span>
        </a>
        <a href="{{ route('personal.regularizacion.index', ['section' => 'pending', 'type' => 'contracts']) }}">
            <strong>{{ $metrics['pending_contract_workers'] }}</strong>
            <span>Sin contrato firmado vigente</span>
        </a>
    @else
        <div class="regularization-ceased-summary">
            <strong>{{ $metrics['ceased_workers'] }}</strong>
            <span>Trabajadores cesados disponibles para revisar</span>
        </div>
    @endif
</section>

<section class="regularization-list" aria-label="{{ $section === 'ceased' ? 'Trabajadores cesados' : 'Pendientes de regularización' }}">
    <div class="regularization-list-head">
        <div>
            <h2>{{ $section === 'ceased' ? 'Cesados' : 'Pendientes de regularización' }}</h2>
            <p>
                @if($section === 'ceased')
                    El reingreso crea un contrato nuevo y conserva el historial anterior.
                @else
                    {{ $rows->total() }} trabajador(es) coinciden con la revisión actual.
                @endif
            </p>
        </div>
        @if($rows->total() > 0)
            <span class="regularization-range">{{ $rows->firstItem() }}-{{ $rows->lastItem() }} de {{ $rows->total() }}</span>
        @endif
    </div>

    @forelse($rows as $row)
        @if($section === 'pending')
            <details class="regularization-worker">
                <summary>
                    <span class="regularization-worker-main">
                        <strong>{{ $row['name'] }}</strong>
                        <small>{{ $row['document_type'] }} {{ $row['document'] ?: 'sin documento' }} · {{ $row['position'] }}</small>
                    </span>
                    <span class="regularization-worker-counts">
                        @if($row['missing_fields_count'] > 0)
                            <span class="regularization-chip is-warning">{{ $row['missing_fields_count'] }} dato(s)</span>
                        @endif
                        @if($row['document_action_count'] > 0)
                            <span class="regularization-chip is-info">{{ $row['document_action_count'] }} documento(s)</span>
                        @endif
                        @if($row['contract_pending'])
                            <span class="regularization-chip is-danger">Contrato</span>
                        @endif
                    </span>
                    <svg class="regularization-chevron" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </summary>

                <div class="regularization-worker-body">
                    <div class="regularization-issues">
                        @if($row['missing_fields_count'] > 0)
                            <section>
                                <h3>Ficha personal</h3>
                                <ul>
                                    @foreach($row['missing_fields'] as $field)
                                        <li><span>{{ $field['label'] }}</span><small>{{ $field['section'] }}</small></li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif

                        @if($row['document_action_count'] > 0)
                            <section>
                                <h3>Documentos</h3>
                                <ul>
                                    @foreach($row['documents'] as $document)
                                        <li>
                                            <span>{{ $document['label'] }}</span>
                                            <small class="document-state is-{{ $document['status'] }}">{{ $document['status_label'] }}</small>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif

                        @if($row['contract_pending'])
                            <section>
                                <h3>Contrato laboral</h3>
                                <p class="regularization-contract-warning">Falta un contrato firmado vigente o está pendiente de regularización.</p>
                            </section>
                        @endif
                    </div>

                    <div class="regularization-actions">
                        @if($access['can_edit'] && $row['missing_fields_count'] > 0)
                            <a href="{{ route('personal.edit', $row['id']) }}" class="btn btn-primary btn-sm">Completar ficha</a>
                        @endif
                        @if($access['can_view_documents'] && $row['document_action_count'] > 0)
                            <a href="{{ route('personal.documentos.index', $row['id']) }}" class="btn btn-outline btn-sm">Revisar documentos</a>
                        @endif
                        @if($access['can_view_contracts'] && $row['contract_pending'])
                            <a href="{{ route('personal.contratos.index', $row['id']) }}" class="btn btn-outline btn-sm">Ver contratos</a>
                        @endif
                    </div>
                </div>
            </details>
        @else
            <article class="regularization-ceased-row">
                <div class="regularization-worker-main">
                    <strong>{{ $row['name'] }}</strong>
                    <small>{{ $row['document_type'] }} {{ $row['document'] ?: 'sin documento' }} · {{ $row['position'] }}</small>
                </div>
                <dl>
                    <div><dt>Fecha de cese</dt><dd>{{ $row['ceased_at'] ?: 'No registrada' }}</dd></div>
                    <div><dt>Último contrato</dt><dd>{{ $row['latest_contract_end'] ? 'Finalizó ' . $row['latest_contract_end'] : 'Sin fecha registrada' }}</dd></div>
                </dl>
                @if($access['can_view_contracts'])
                    <a href="{{ route('personal.contratos.index', ['id' => $row['id'], 'accion' => 'reingreso']) }}#movimiento-contractual" class="btn {{ $row['has_preparation'] ? 'btn-outline' : 'btn-primary' }} btn-sm">
                        {{ $row['has_preparation'] ? 'Continuar contrato' : 'Crear nuevo contrato' }}
                    </a>
                @endif
            </article>
        @endif
    @empty
        <div class="regularization-empty">
            <svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <strong>No hay resultados para esta revisión.</strong>
            <span>Prueba limpiando los filtros o actualiza la información.</span>
        </div>
    @endforelse

    @if($rows->hasPages())
        <div class="regularization-pagination">
            {{ $rows->appends($query)->links() }}
        </div>
    @endif
</section>
