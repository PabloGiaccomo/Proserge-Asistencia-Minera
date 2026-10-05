@extends('layouts.app')
@section('title', 'Organizar exámenes - Proserge')
@section('content')
<div class="mco-page" data-mining-catalog>
    <header class="mco-header">
        <div><span class="mco-eyebrow">HABILITACIÓN MINERA · CATÁLOGO</span><h1>Organizar exámenes</h1>
            <p>Un mismo curso puede tener varios nombres. Revisa sus minas y reglas antes de asociarlos.</p></div>
        <a class="btn btn-outline" href="{{ route('personal.habilitacion-minera.index') }}">Volver a habilitación</a>
    </header>
    @if(session('success'))<p class="mco-notice is-success" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<div class="mco-notice is-warning" role="alert"><strong>No se guardaron cambios.</strong>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @unless($report['schema_ready'])<div class="mco-notice is-warning" role="alert"><strong>Falta instalar la estructura de organización del catálogo.</strong><p>Instala la migración 2026_10_05_000600 o el SQL de instalación en este ambiente. Puedes consultar el catálogo; guardar está deshabilitado.</p></div>@endunless
    <div class="mco-notice"><strong>Se conservan notas, fechas, intentos y requisitos.</strong> Asociar nombres no convalida ni habilita trabajadores automáticamente. Los cambios quedan registrados. Antes de una corrección masiva, realiza un respaldo de la base.</div>
    @if($preview)
    <section class="mco-card mco-preview" id="catalog-review">
        <span class="mco-eyebrow">PASO 2 · REVISAR ANTES DE GUARDAR</span><h2>Vista previa de la corrección</h2>
        <p>Acción: <strong>{{ $preview['payload']['accion'] }}</strong>. Motivo: {{ $preview['payload']['motivo'] }}</p>
        @if($preview['payload']['accion'] === 'renombrar')<p>Nuevo nombre: <strong>{{ $preview['payload']['nombre'] }}</strong>. El anterior seguirá reconocido.</p>@endif
        <div class="mco-impact-grid">
        @foreach($preview['impacto'] as $exam)
            <article class="mco-impact"><strong>{{ $exam['nombre'] }}</strong>
                <span>{{ $exam['id'] === $preview['payload']['principal_id'] ? 'Examen principal' : 'Nombre variante' }}</span>
                <span>Minas: {{ implode(', ', $exam['minas_nombres']) ?: 'Sin requisito activo' }}</span>
                <span>{{ $exam['examenes_trabajador'] }} registros · {{ $exam['intentos'] }} intentos · {{ $exam['cursos_globales'] }} cursos</span>
                <span>{{ $exam['requiere_nota'] ? 'Nota mínima: '.($exam['nota_minima'] ?? 'Sin mínimo') : 'No exige nota' }} · {{ $exam['permite_convalidacion'] ? 'Permite convalidación' : 'No permite convalidación' }}</span>
                <span>Vigencia: {{ $exam['tiene_vigencia'] ? ($exam['vigencia_dias'] ?? 'Por resultado').' días' : 'Sin vigencia de catálogo' }} · Intentos: {{ $exam['max_intentos'] }} · Precio: {{ $exam['precio'] ?? 'Sin precio' }} {{ $exam['moneda'] }}</span>
                <details><summary>Ver reglas por mina</summary>@foreach($exam['requisitos'] as $requirement)
                    <p>{{ collect($report['minas'])->firstWhere('id', $requirement->mina_id)['nombre'] ?? 'Mina histórica' }}: {{ $requirement->activo ? 'Activo' : 'Histórico' }} · {{ $requirement->permite_convalidacion_mina ? 'Convalidación permitida' : 'Convalidación desactivada' }} · {{ $requirement->convalidar_desde_otras_minas ? 'Acepta otras minas' : 'Solo misma mina' }} · Vigencia especial: {{ $requirement->vigencia_dias_override ?? 'Sin cambio' }}</p>
                @endforeach</details>
            </article>
        @endforeach
        </div>
        @foreach($preview['payload']['aliases'] as $alias)<p>Nombre alternativo: <strong>{{ $alias['nombre'] }}</strong> · {{ $alias['mina_id'] ? (collect($report['minas'])->firstWhere('id', $alias['mina_id'])['nombre'] ?? 'Mina seleccionada') : 'Todas las minas' }}</p>@endforeach
        <p>Las diferencias de nota, vigencia, precio y convalidación <strong>se conservan</strong>. Los IDs y los resultados no se fusionan.</p>
        <form method="POST" action="{{ route('personal.habilitacion-minera.catalogo.confirm') }}" data-catalog-confirm>
            @csrf<input type="hidden" name="token" value="{{ $preview['token'] }}">
            <label class="mco-check"><input type="checkbox" name="confirmar" value="1" required> Revisé las variantes, sus minas y las diferencias. Confirmo esta corrección.</label>
            <button class="btn btn-primary" type="submit" disabled>Confirmar y guardar</button>
        </form>
    </section>
    @endif
    <form method="POST" action="{{ route('personal.habilitacion-minera.catalogo.preview') }}" class="mco-workspace" data-catalog-form>
        @csrf
        <section class="mco-card">
            <div class="mco-section-head"><h2>Catálogo y posibles duplicados</h2><span data-catalog-count>{{ count($report['examenes']) }} exámenes</span></div>
            <div class="mco-filters"><label>Buscar<input type="search" data-catalog-search placeholder="Nombre de examen o mina"></label>
                <label>Mostrar<select data-catalog-filter><option value="all">Todos</option><option value="active">Activos</option><option value="history">Históricos</option><option value="similar">Posibles duplicados</option></select></label></div>
            <div class="mco-table-wrap"><table class="mco-table"><thead><tr><th>Variante</th><th>Examen y nombres reconocidos</th><th>Minas / historial</th><th>Reglas</th></tr></thead><tbody>
            @foreach($report['examenes'] as $exam)
                @php
                    $association = collect($report['asociaciones'])->firstWhere('variante_id', $exam['id']);
                    $principalId = $association['principal_id'] ?? $exam['id'];
                    $isSimilar = collect($report['sugerencias'])->contains(fn($pair) => $pair['primero_id'] === $exam['id'] || $pair['segundo_id'] === $exam['id']);
                    $aliases = collect($report['aliases'])->where('examen_id', $exam['id']);
                @endphp
                <tr data-catalog-row data-name="{{ mb_strtolower($exam['nombre'].' '.implode(' ', $exam['minas_nombres']).' '.$aliases->pluck('nombre')->implode(' ')) }}" data-active="{{ $exam['activo'] ? '1' : '0' }}" data-similar="{{ $isSimilar ? '1' : '0' }}" data-exam-id="{{ $exam['id'] }}">
                    <td><input type="checkbox" name="variante_ids[]" value="{{ $exam['id'] }}" aria-label="Asociar {{ $exam['nombre'] }}" @checked(in_array($exam['id'], old('variante_ids', [])))></td>
                    <td><strong>{{ $exam['nombre'] }}</strong><span class="mco-tag">{{ $exam['activo'] ? 'Activo' : 'Histórico' }}</span>
                        @if($isSimilar)<span class="mco-tag is-amber">Nombre parecido: revisar</span>@endif
                        @if($association)<small>Principal: {{ collect($report['examenes'])->firstWhere('id', $principalId)['nombre'] ?? 'Examen principal' }}</small>@endif
                        @foreach($aliases as $alias)<small>Alias: {{ $alias['nombre'] }} · {{ $alias['mina_id'] ? (collect($report['minas'])->firstWhere('id', $alias['mina_id'])['nombre'] ?? 'Mina histórica') : 'Global' }}</small>@endforeach
                    </td>
                    <td>{{ implode(', ', $exam['minas_nombres']) ?: 'Sin mina activa' }}<small>{{ $exam['examenes_trabajador'] }} registros · {{ $exam['intentos'] }} intentos</small></td>
                    <td>{{ $exam['requiere_nota'] ? 'Nota mínima: '.($exam['nota_minima'] ?? 'Sin mínimo') : 'Sin nota obligatoria' }}<small>{{ $exam['permite_convalidacion'] ? 'Convalidación permitida' : 'Convalidación desactivada' }}</small></td>
                </tr>
            @endforeach
            </tbody></table></div>
            <p class="mco-empty" data-catalog-empty hidden>No hay coincidencias con estos filtros.</p>
        </section>
        <aside class="mco-card mco-editor">
            <span class="mco-eyebrow">PASO 1 · PREPARAR CORRECCIÓN</span><h2>Elegir cómo organizar</h2>
            <fieldset @disabled(!$report['schema_ready'])>
                <label>Acción<select name="accion" data-catalog-action><option value="asociar">Asociar variantes del mismo examen</option><option value="renombrar">Corregir nombre del principal</option><option value="pendiente">Dejar registrado como pendiente</option></select></label>
                <label>Examen principal<select name="principal_id" required data-catalog-principal><option value="">Selecciona el examen principal</option>
                    @foreach($report['examenes'] as $exam)@unless(collect($report['asociaciones'])->contains('variante_id', $exam['id']))<option value="{{ $exam['id'] }}" @selected(old('principal_id') === $exam['id'])>{{ $exam['nombre'] }}{{ $exam['activo'] ? '' : ' (histórico)' }}</option>@endunless @endforeach
                </select></label>
                <p data-catalog-selection role="status">Selecciona el principal y marca las variantes en la tabla.</p>
                <label data-catalog-rename hidden>Nombre correcto<input name="nombre" maxlength="191" value="{{ old('nombre') }}" disabled></label>
                <div data-catalog-aliases><label>Otros nombres del Excel<textarea name="aliases_text" rows="3" placeholder="Un nombre alternativo por línea">{{ old('aliases_text') }}</textarea></label>
                    <label>Estos nombres aplican en<select name="alias_mina_id"><option value="">Todas las minas</option>@foreach($report['minas'] as $mine)<option value="{{ $mine['id'] }}" @selected(old('alias_mina_id') === $mine['id'])>{{ $mine['nombre'] }}</option>@endforeach</select></label></div>
                <label>Motivo de la corrección<textarea name="motivo" required minlength="5" maxlength="1000" rows="3" placeholder="Ej.: variantes del mismo curso revisadas con el responsable">{{ old('motivo') }}</textarea></label>
                <button class="btn btn-primary" type="submit">Revisar impacto</button>
                <p class="mco-muted">Este paso no guarda cambios. No unas teórico y práctico solo por tener nombres parecidos.</p>
            </fieldset>
        </aside>
    </form>
    <section class="mco-card mco-history"><h2>Últimas correcciones</h2>
        @forelse($report['cambios'] as $change)<p><strong>{{ $change['accion'] }}</strong> · {{ $change['created_at'] }} · {{ $change['motivo'] }}</p>@empty<p class="mco-muted">Todavía no hay correcciones registradas.</p>@endforelse
    </section>
</div>
@endsection
