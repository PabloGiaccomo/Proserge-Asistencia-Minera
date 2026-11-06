@php
    $editing = $formKey !== 'nuevo';
    $recover = old('form_key') === $formKey;
    $value = static function (string $field, $default = '') use ($recover, $values): string {
        $current = $values[$field] ?? $default;
        $input = $recover ? old($field, $current) : $current;
        return is_scalar($input) ? (string) $input : '';
    };
    $driverId = $value('conductor_personal_id');
    $scopes = $recover ? old('alcances', $values['alcances'] ?? []) : ($values['alcances'] ?? []);
    $scopes = array_values(array_filter(is_array($scopes) ? $scopes : [], fn ($row): bool => is_array($row) && (bool) array_filter($row)));
    $scopes[] = [];
@endphp
<form method="POST" action="{{ $editing ? route('transporte.servicios.update', $formKey) : route('transporte.servicios.store') }}" data-transport-form>
    @csrf
    @if($editing)
        @method('PATCH')
    @else
        @foreach(['rq_mina_id', 'rq_mina_plan_id', 'fecha', 'turno'] as $field)
            <input type="hidden" name="{{ $field }}" value="{{ $formContext[$field] ?? '' }}">
        @endforeach
    @endif
    <input type="hidden" name="form_key" value="{{ $formKey }}">
    @include('transporte.partials.context-fields')

    <div class="tr-form-error" data-transport-error role="alert" tabindex="-1"
        @if(!$recover || (!$errors->any() && !session('error'))) hidden @endif>
        @if($recover && $errors->any())
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @elseif($recover)
            {{ session('error') }}
        @endif
    </div>
    <div class="tr-grid">
        <label class="tr-field">
            <span>Tipo</span>
            <select class="tr-select" name="tipo" required>
                <option value="PERSONAL" @selected($value('tipo', 'PERSONAL') === 'PERSONAL')>Personal</option>
                <option value="CARGA" @selected($value('tipo') === 'CARGA')>Carga</option>
            </select>
        </label>
        <label class="tr-field">
            <span>Tramo</span>
            <select class="tr-select" name="tramo" required>
                <option value="IDA" @selected($value('tramo', 'IDA') === 'IDA')>Ida</option>
                <option value="RETORNO" @selected($value('tramo') === 'RETORNO')>Retorno</option>
                <option value="TRASLADO_INTERNO" @selected($value('tramo') === 'TRASLADO_INTERNO')>Traslado interno</option>
            </select>
        </label>
        <label class="tr-field">
            <span>Conductor</span>
            <select class="tr-select" name="conductor_personal_id">
                <option value="">Seleccionar conductor</option>
                @if($driverId !== '' && $driverId === ($values['conductor_personal_id'] ?? null) && !$drivers->contains('id', $driverId))
                    <option value="{{ $driverId }}" selected>{{ $values['conductor'] }}</option>
                @endif
                @foreach($drivers as $driver)
                    <option value="{{ $driver['id'] }}" @selected($driverId === $driver['id'])>{{ $driver['nombre_completo'] }}</option>
                @endforeach
            </select>
        </label>
        @foreach([
            'placa' => ['Placa', 'text', 'maxlength=50'],
            'capacidad' => ['Capacidad', 'number', 'min=0'],
            'transportista' => ['Transportista', 'text', 'maxlength=191'],
            'tipo_vehiculo' => ['Vehículo', 'text', 'maxlength=120'],
            'hora_salida' => ['Hora de salida', 'time', ''],
            'hora_retorno' => ['Hora de retorno', 'time', ''],
            'origen' => ['Origen', 'text', 'maxlength=191'],
            'destino' => ['Destino', 'text', 'maxlength=191'],
        ] as $field => [$label, $type, $constraint])
            <label class="tr-field">
                <span>{{ $label }}</span>
                <input class="tr-input" type="{{ $type }}" name="{{ $field }}"
                    value="{{ $type === 'time' ? substr($value($field), 0, 5) : $value($field) }}" {{ $constraint }}>
            </label>
        @endforeach
        @foreach($scopes as $index => $scope)
            @php
                $groupId = is_string($scope['grupo_trabajo_id'] ?? null) ? $scope['grupo_trabajo_id'] : '';
                $savedScope = collect($values['alcances'] ?? [])->firstWhere('grupo_trabajo_id', $groupId);
            @endphp
            <label class="tr-field">
                <span>Alcance Man Power {{ count($scopes) > 1 ? $index + 1 : '' }}</span>
                <select class="tr-select" name="alcances[{{ $index }}][grupo_trabajo_id]">
                    <option value="">Sin grupo</option>
                    @if($groupId !== '' && $savedScope && !$groups->contains('id', $groupId))
                        <option value="{{ $groupId }}" selected>{{ $savedScope['grupo_man_power'] ?? 'Grupo guardado' }}</option>
                    @endif
                    @foreach($groups as $group)
                        <option value="{{ $group['id'] }}" @selected($groupId === $group['id'])>{{ $group['servicio'] }} - {{ $group['paradero'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="tr-field">
                <span>Referencia SAIT {{ count($scopes) > 1 ? $index + 1 : '' }}</span>
                <input class="tr-input" name="alcances[{{ $index }}][sait_snapshot]" maxlength="191"
                    value="{{ is_string($scope['sait_snapshot'] ?? null) ? $scope['sait_snapshot'] : '' }}">
            </label>
            @foreach(['rq_mina_actividad_grupo_id', 'rq_mina_actividad_id'] as $field)
                @if(is_scalar($scope[$field] ?? null) && $scope[$field] !== '')
                    <input type="hidden" name="alcances[{{ $index }}][{{ $field }}]" value="{{ $scope[$field] }}">
                @endif
            @endforeach
        @endforeach
        <label class="tr-field tr-form-notes">
            <span>Observaciones</span>
            <textarea class="tr-textarea" name="observaciones" maxlength="2000">{{ $value('observaciones') }}</textarea>
        </label>
    </div>
    <div class="tr-actions tr-form-actions">
        <button class="tr-btn tr-btn-primary" type="submit">{{ $editing ? 'Guardar cambios' : 'Crear servicio' }}</button>
        @if($editing)
            <a class="tr-btn" href="{{ route('transporte.planificacion', $formContext) }}#transporte-servicios">Volver a servicios</a>
        @endif
    </div>
</form>
