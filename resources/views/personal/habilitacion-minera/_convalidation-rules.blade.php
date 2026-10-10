@php
    $convalidationRules = $requirementsByMine->flatten(1)->map(fn ($requirement) => [
        'id' => $requirement->id,
        'examen_id' => $requirement->examen_id,
        'examen' => $requirement->examen?->nombre ?: $requirement->nombre,
        'mina' => $mines->firstWhere('id', $requirement->mina_id)?->nombre,
        'permitida' => (bool) $requirement->permite_convalidacion_mina,
        'otras_minas' => (bool) $requirement->convalidar_desde_otras_minas,
        'catalogo_permite' => (bool) $requirement->examen?->permite_convalidacion,
        'equivalentes' => $requirement->examenes_equivalentes_json ?: [],
        'url' => route('personal.habilitacion-minera.requisitos.convalidation', array_merge(['requirementId' => $requirement->id], $currentQuery)),
    ])->values();
@endphp
<script type="application/json" id="miningConvalidationRulesData">@json($convalidationRules)</script>
<dialog id="modal-convalidation-rules" class="mine-dialog is-wide">
    <div class="mine-dialog-header">
        <div class="mine-dialog-title">
            <span class="mine-dialog-kicker">Equivalencias por mina</span>
            <strong>Reglas de convalidación</strong>
            <p class="mine-dialog-subtitle" id="miningConvalidationTarget"></p>
        </div>
        <button type="button" class="mine-dialog-close" onclick="closeDialog(this)" aria-label="Cerrar">X</button>
    </div>
    <div class="mine-dialog-body">
        <form method="POST" id="miningConvalidationRulesForm" class="mine-form" data-loading-message="Guardando reglas de convalidación...">
            @csrf
            <p class="mine-helper-card is-full">El mismo examen se acepta como origen. Marca también los exámenes con nombres diferentes que esta mina reconoce como equivalentes. Cada resultado del trabajador se confirma por separado.</p>
            <p class="alert alert-warning is-full" id="miningConvalidationCatalogWarning" hidden>El catálogo de este examen tiene la convalidación desactivada. Actívala en Acciones &gt; Editar examen para poder usar estas reglas.</p>
            <label class="mine-checkline is-full"><input type="hidden" name="permite_convalidacion_mina" value="0"><input id="miningConvalidationAllowed" type="checkbox" name="permite_convalidacion_mina" value="1"><span>Permitir convalidación para este requisito</span></label>
            <label class="mine-checkline is-full"><input type="hidden" name="convalidar_desde_otras_minas" value="0"><input id="miningConvalidationOtherMines" type="checkbox" name="convalidar_desde_otras_minas" value="1"><span>Aceptar resultados de otras minas</span></label>
            <fieldset class="is-full">
                <legend>Exámenes de origen equivalentes autorizados</legend>
                <label>Buscar examen<input type="search" id="miningEquivalentSearch" placeholder="Nombre del examen"></label>
                <div id="miningEquivalentList" style="max-height:260px;overflow:auto;padding:12px;display:grid;gap:10px;">
                    @foreach($exams as $exam)
                        <label class="mine-checkline" data-equivalent-choice data-exam-id="{{ $exam->id }}">
                            <input type="checkbox" name="examenes_equivalentes[]" value="{{ $exam->id }}">
                            <span>{{ $exam->nombre }} — {{ $exam->requiere_nota ? 'Con nota' : 'Sin nota obligatoria' }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <p class="mine-muted is-full">La equivalencia aplica solo a este examen y mina destino. No autoriza la dirección inversa. La nota se conserva en su escala original; si el destino exige nota, debe existir y alcanzar su mínimo.</p>
            <div class="mine-form-actions">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeDialog(this)">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm">Guardar reglas</button>
            </div>
        </form>
    </div>
</dialog>
