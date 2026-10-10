@php
    $emoCan = fn ($action) => \App\Support\Rbac\PermissionMatrix::allowsDirect(session('user.permissions', []), 'habilitacion_minera', $action);
    $emoQuery = $currentQuery ?? request()->query();
    $emoRoutes = [];
    foreach (['schedule' => 'workerExamId', 'complete' => 'appointmentId', 'cancel' => 'appointmentId', 'reprogram' => 'appointmentId', 'regularize' => 'attemptId'] as $action => $parameter) {
        $emoRoutes[$action] = route('personal.habilitacion-minera.emo.'.$action, array_merge($emoQuery, [$parameter => '__ID__']));
    }
    $emoRuntime = [
        'routes' => $emoRoutes, 'csrf' => csrf_token(),
        'clinics' => collect($emoClinics ?? [])->map(fn ($clinic) => ['id' => $clinic->id, 'name' => $clinic->nombre, 'active' => (bool) $clinic->activo])->values(),
        'permissions' => ['programar' => $emoCan('programar'), 'registrar' => $emoCan('registrar')],
        'reopen' => session('habilitacion_mina_emo_modal'), 'context' => session('habilitacion_mina_emo_context', []),
        'old' => session()->getOldInput(), 'errors' => isset($errors) ? $errors->all() : [],
    ];
@endphp
<script type="application/json" id="miningEmoRuntime">@json($emoRuntime, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<dialog id="modal-emo-operation" class="mine-dialog mining-emo-dialog" aria-labelledby="miningEmoTitle">
    <div class="mine-dialog-header"><strong id="miningEmoTitle">Cita EMO</strong><button type="button" class="mine-dialog-close" data-emo-close aria-label="Cerrar formulario EMO">X</button></div>
    <div class="mine-dialog-body" id="miningEmoBody"></div>
</dialog>
@if($emoCan('configurar'))
<dialog id="modal-emo-clinics" class="mine-dialog mining-emo-dialog" aria-labelledby="emoClinicsTitle">
    <div class="mine-dialog-header"><strong id="emoClinicsTitle">Clinicas EMO</strong><button type="button" class="mine-dialog-close" data-emo-close aria-label="Cerrar clinicas">X</button></div>
    <div class="mine-dialog-body">
        <p>Las citas conservan el nombre de la clinica registrado en su momento.</p>
        @if(session('habilitacion_mina_emo_modal') === 'clinics' && isset($errors) && $errors->any())<div class="alert alert-danger" role="alert">{{ implode(' ', $errors->all()) }}</div>@endif
        <form method="POST" action="{{ route('personal.habilitacion-minera.clinics.store', $emoQuery) }}" class="mining-emo-form">@csrf<label>Nueva clinica<input name="nombre" value="{{ old('nombre') }}" required maxlength="191"></label><button class="btn btn-primary" type="submit">Agregar clinica</button></form>
        @foreach($emoClinics ?? [] as $clinic)
        <div class="mining-emo-clinic-row">
            <form method="POST" action="{{ route('personal.habilitacion-minera.clinics.update', array_merge($emoQuery, ['clinicId' => $clinic->id])) }}" class="mining-emo-form">@csrf<label>Nombre de clinica ({{ $clinic->activo ? 'Activa' : 'Inactiva' }})<input name="nombre" value="{{ $clinic->nombre }}" required maxlength="191"></label><button class="btn btn-outline" type="submit">Guardar nombre</button></form>
            @if($clinic->activo)<form method="POST" action="{{ route('personal.habilitacion-minera.clinics.deactivate', array_merge($emoQuery, ['clinicId' => $clinic->id])) }}">@csrf<button class="btn btn-outline" type="submit">Desactivar clinica</button></form>@endif
        </div>
        @endforeach
    </div>
</dialog>
<dialog id="modal-emo-category" class="mine-dialog mining-emo-dialog" aria-labelledby="emoCategoryTitle">
    <div class="mine-dialog-header"><strong id="emoCategoryTitle">Categoria operativa del examen</strong><button type="button" class="mine-dialog-close" data-emo-close aria-label="Cerrar categorias">X</button></div>
    <div class="mine-dialog-body">
        <p>Curso, EMO u otro define el flujo operativo. El cambio conserva historial y exige motivo.</p>
        @if(session('habilitacion_mina_emo_modal') === 'category' && isset($errors) && $errors->any())<div class="alert alert-danger" role="alert">{{ implode(' ', $errors->all()) }}</div>@endif
        <form method="POST" class="mining-emo-form" data-emo-category-form>@csrf
            <label>Examen<select data-emo-category-exam required><option value="">Seleccione un examen</option>@foreach($allExams ?? [] as $exam)<option value="{{ route('personal.habilitacion-minera.exams.category', array_merge($emoQuery, ['examId' => $exam->id])) }}" data-category="{{ $exam->categoria_operativa }}" @selected((session('habilitacion_mina_emo_context.examId') ?? '') === $exam->id)>{{ $exam->nombre }} ({{ $exam->categoria_operativa }})</option>@endforeach</select></label>
            <label>Categoria<select name="categoria_operativa" required>@foreach(['CURSO' => 'Curso', 'EMO' => 'EMO', 'OTRO' => 'Otro'] as $value => $label)<option value="{{ $value }}" @selected(old('categoria_operativa') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>Motivo<textarea name="motivo" required maxlength="5000">{{ old('motivo') }}</textarea></label><button type="submit" class="btn btn-primary">Guardar categoria</button>
        </form>
    </div>
</dialog>
@endif
