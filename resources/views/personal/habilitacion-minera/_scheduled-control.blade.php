@php
    $controlPermissions = session('user.permissions', []);
    $controlCan = fn ($action) => \App\Support\Rbac\PermissionMatrix::allowsDirect($controlPermissions, 'habilitacion_minera', $action);
    $controlQuery = $scheduledControlFilters?->toQuery(false) ?? [];
    $controlPage = $scheduledControl ?? new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1);
    $controlOptions = $scheduledControlOptions ?? [];
    $controlLabels = ['PROGRAMADO' => 'Programado', 'ASISTIO' => 'Asistio', 'NO_ASISTIO' => 'No asistio', 'ANULADO' => 'Anulado'];
@endphp
<section @class(['card', 'mine-scheduled-card', 'mining-control', 'is-hidden' => ($activeMineView ?? 'scheduled') !== 'scheduled']) data-mine-view-panel="scheduled" aria-labelledby="scheduledControlTitle">
    <div class="mining-control-heading">
        <div><h2 id="scheduledControlTitle" class="card-title">Control de asistencia de cursos y EMO</h2><p class="mine-muted">Consulta fechas, clinicas, asistencia y resultados. Cada cita compartida agrupa sus minas.</p></div>
        <strong class="mine-state-chip info" aria-live="polite">{{ $controlPage->total() }} registros</strong>
        @if($controlCan('ver_programados') && $controlCan('exportar'))
            <a class="btn btn-primary mining-control-export" data-scheduled-export data-no-ajax="true" href="{{ route('personal.habilitacion-minera.scheduled.export', $controlQuery) }}">Exportar resultados</a>
        @endif
    </div>
    <form id="scheduledPaginationForm" method="GET" action="{{ route('personal.habilitacion-minera.index') }}" class="mining-control-filters" aria-label="Filtros del control de asistencia">
        <input type="hidden" name="vista" value="scheduled">
        <label class="mining-control-search">Trabajador, documento o cargo<input id="scheduledWorkerInput" name="scheduled_worker" value="{{ $controlQuery['scheduled_worker'] ?? '' }}" placeholder="Buscar trabajador" type="search"></label>
        <label>Desde<input id="scheduledFromInput" type="date" name="scheduled_from" value="{{ $controlQuery['scheduled_from'] ?? '' }}" required></label>
        <label>Hasta<input id="scheduledToInput" type="date" name="scheduled_to" value="{{ $controlQuery['scheduled_to'] ?? '' }}" required></label>
        @foreach([
            ['scheduled_category', 'Tipo', 'categories', [['id' => 'CURSO', 'name' => 'Curso'], ['id' => 'EMO', 'name' => 'EMO']]],
            ['scheduled_exam_id', 'Curso / EMO', 'exams', []],
            ['scheduled_mine_id', 'Mina', 'mines', []],
            ['scheduled_clinic_id', 'Clinica', 'clinics', []],
            ['scheduled_attendance_state', 'Asistencia', 'attendanceStates', collect($controlLabels)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all()],
        ] as [$name, $label, $optionKey, $fallback])
            <label>{{ $label }}<select name="{{ $name }}" id="{{ $name }}"><option value="">Todos</option>
                @foreach($controlOptions[$optionKey] ?? $fallback as $option)
                    <option value="{{ $option['id'] }}" @selected(($controlQuery[$name] ?? '') === $option['id'])>{{ $controlLabels[$option['name']] ?? $option['name'] }}{{ isset($option['active']) && !$option['active'] ? ' (inactiva)' : '' }}</option>
                @endforeach
            </select></label>
        @endforeach
        <label>Mostrar<select name="scheduled_limit" aria-label="Registros por pagina">@foreach([25, 50, 100] as $amount)<option value="{{ $amount }}" @selected(($scheduledControlFilters?->limit ?? 25) === $amount)>{{ $amount }}</option>@endforeach</select></label>
        <div class="mining-control-filter-actions"><button type="submit" class="btn btn-primary">Aplicar filtros</button><a class="btn btn-outline" href="{{ route('personal.habilitacion-minera.index', ['vista' => 'scheduled']) }}">Limpiar</a></div>
    </form>
    <p class="mine-muted mining-control-count">Mostrando {{ $controlPage->firstItem() ?? 0 }}-{{ $controlPage->lastItem() ?? 0 }} de {{ $controlPage->total() }} registros</p>
    <table class="mine-table mining-control-table" data-testid="mine-scheduled-exams">
        <thead><tr><th>Trabajador</th><th>Fecha / clinica</th><th>Curso / EMO</th><th>Minas / intentos</th><th>Asistencia / resultado</th><th>Acciones</th></tr></thead>
        <tbody>
        @forelse($controlPage as $row)
            <tr data-control-row="{{ $row->rowKey }}">
                <td data-label="Trabajador"><strong>{{ $row->workerName }}</strong><span class="mine-muted">{{ $row->workerDocument }} · {{ $row->positionName ?: 'Sin cargo' }}</span></td>
                <td data-label="Fecha / clinica"><strong>{{ $row->scheduledDate->format('d/m/Y') }}</strong><span>{{ $row->clinicName ?: 'No requiere clinica' }}</span></td>
                <td data-label="Curso / EMO"><span class="mine-state-chip info">{{ $row->sourceType }}</span><strong>{{ $row->examName }}</strong></td>
                <td data-label="Minas / intentos"><span>{{ implode(' · ', $row->mineNames) }}</span><details><summary>Ver intentos ({{ count($row->attemptLabels) }})</summary>@foreach($row->attemptLabels as $attempt)<span class="mine-muted">{{ $attempt }}</span>@endforeach</details></td>
                <td data-label="Asistencia / resultado"><strong>{{ $controlLabels[$row->attendanceState] ?? $row->attendanceState }}</strong>
                    @if($row->emoOutcome)
                        @php
                            [$emoColor, $emoDetailPrefix] = match ($row->emoOutcome) {
                                \App\Modules\Personal\Support\EmoOutcome::APTO => ['ok', null],
                                \App\Modules\Personal\Support\EmoOutcome::NO_APTO => ['danger', null],
                                \App\Modules\Personal\Support\EmoOutcome::APTO_CON_RESTRICCION => ['warn', 'Restriccion'],
                                \App\Modules\Personal\Support\EmoOutcome::APTO_CON_OBSERVACION => ['orange', 'Observacion'],
                            };
                            $emoDetail = $row->emoOutcome->detailTooltip($row->resultDetail);
                            $emoLabel = $row->emoOutcome->label();
                        @endphp
                        <span class="mine-state-chip {{ $emoColor }} mining-emo-outcome"
                              data-emo-outcome="{{ $row->emoOutcome->value }}"
                              @if($emoDetail !== '') title="{{ $emoDetail }}" data-emo-tooltip="{{ $emoDetail }}" tabindex="0" @endif
                              aria-label="{{ $emoLabel }}{{ $emoDetail !== '' ? '. '.$emoDetail : '' }}">{{ $emoLabel }}</span>
                    @elseif($row->resultDetail)
                        <span>{{ $row->resultDetail }}</span>
                    @endif
                    @if($row->performedAt)<span class="mine-muted">Realizado {{ $row->performedAt->format('d/m/Y') }}</span>@endif
                </td>
                <td data-label="Acciones"><div class="mining-control-row-actions">
                    <a class="btn btn-outline btn-xs" href="{{ route('personal.habilitacion-minera.index', ['worker_id' => $row->workerId, 'vista' => 'worker']) }}">Ver programados</a>
                    @if($row->sourceType === 'EMO' && $row->appointmentRevision !== null)
                        @foreach(($row->operationalState === 'PROGRAMADA' ? array_merge($controlCan('registrar') ? ['complete' => 'Registrar resultado'] : [], $controlCan('programar') ? ['cancel' => 'Anular cita'] : []) : ($row->operationalState === 'ANULADA' && $controlCan('programar') ? ['reprogram' => 'Reprogramar'] : [])) as $action => $label)
                            <button type="button" class="btn btn-outline btn-xs" data-emo-action="{{ $action }}" data-emo-id="{{ $row->rowKey }}" data-emo-revision="{{ $row->appointmentRevision }}" data-emo-date="{{ $row->scheduledDate->toDateString() }}" data-emo-clinic="{{ $row->clinicId }}" data-emo-clinic-name="{{ $row->clinicName }}" data-emo-worker="{{ $row->workerName }}">{{ $label }}</button>
                        @endforeach
                        @if($row->operationalState === 'PROGRAMADA' && $controlCan('programar'))<span class="mine-muted">Anule la cita para reprogramarla.</span>@endif
                    @elseif($row->sourceType === 'EMO' && $row->attendanceState === 'PROGRAMADO' && str_starts_with($row->rowKey, 'legacy-emo-attempt:') && $controlCan('programar'))
                        <button type="button" class="btn btn-outline btn-xs" data-emo-action="regularize" data-emo-id="{{ substr($row->rowKey, strlen('legacy-emo-attempt:')) }}" data-emo-worker="{{ $row->workerName }}">Regularizar clinica</button>
                    @endif
                </div></td>
            </tr>
        @empty
            <tr><td colspan="6" class="mining-control-empty">Sin registros para estos filtros. Amplia el rango de fechas o limpia los filtros.</td></tr>
        @endforelse
        </tbody>
    </table>
    @if($controlPage->hasPages())<nav class="mining-control-pagination" aria-label="Paginas del control de asistencia">{{ $controlPage->appends(array_merge($controlQuery, ['vista' => 'scheduled']))->links() }}</nav>@endif
</section>
