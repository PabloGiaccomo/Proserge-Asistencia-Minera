@extends('layouts.app')

@section('title', 'Revisar actualización de ficha')

@section('content')
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Revisar actualización de ficha</h1>
                <p class="page-subtitle">{{ $ingreso->personalExistente?->nombre_completo }} · Revisión {{ $comparison['revision'] }}</p>
            </div>
            <a href="{{ route('personal.fichas.temporales') }}" class="btn btn-outline">Volver a Ingresos</a>
        </div>
    </div>
    @if(session('success'))
        <div class="ficha-alert" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="ficha-alert ficha-alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif
    <div class="ficha-card">
        <div class="ficha-card-header">
            <h2 class="ficha-card-title">Datos propuestos</h2>
            @if($ingreso->estado === 'ACEPTADA')
                <span class="ficha-status">Aceptada</span>
            @elseif($ingreso->estado === 'OBSERVADO')
                <span class="ficha-status">Observada</span>
            @elseif($comparison['completeness']['complete'])
                <span class="ficha-status">Completa para revisión</span>
            @endif
        </div>
        <div class="ficha-card-body">
            @if($comparison['conflict'])
                <p class="ficha-alert ficha-alert-warning">La ficha cambió desde que se generó el enlace. Revisa la propuesta y solicita una actualización nueva si corresponde.</p>
            @endif
            @if(!empty($comparison['observation']))
                <p class="ficha-alert ficha-alert-warning">{{ $comparison['observation'] }}</p>
            @endif
            @if($comparison['changes'] === [])
                <p>La propuesta coincide con los datos registrados.</p>
            @else
                <div class="ficha-batch-table-wrap">
                    <table class="ficha-batch-table">
                        <thead><tr><th scope="col">Dato</th><th scope="col">Registrado</th><th scope="col">Propuesto</th></tr></thead>
                        <tbody>
                            @foreach($comparison['changes'] as $field => $change)
                                <tr>
                                    <th scope="row">{{ \App\Modules\Personal\Support\PersonalFichaCatalog::fields()[$field]['label'] ?? $field }}</th>
                                    <td>{{ $change['before'] !== '' ? $change['before'] : 'Sin dato' }}</td>
                                    <td>{{ $change['after'] !== '' ? $change['after'] : 'Borrar dato' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if(($comparison['family_changes'] ?? []) !== [])
                <h3 class="ficha-section-title" style="margin-top:1rem;">Familiares propuestos</h3>
                @foreach($comparison['family_changes'] as $family)
                    <section class="ficha-section">
                        <p class="ficha-alert {{ $family['action'] === 'conflict' ? 'ficha-alert-warning' : '' }}">{{ $family['message'] }}</p>
                        <div class="ficha-fields">
                            <div class="ficha-field">
                                <span class="ficha-label">Apellidos y nombres</span>
                                <span>{{ $family['after']['nombres_apellidos'] }}</span>
                            </div>
                            <div class="ficha-field">
                                <span class="ficha-label">Parentesco</span>
                                <span>{{ $family['after']['parentesco'] }}</span>
                            </div>
                            <div class="ficha-field">
                                <span class="ficha-label">Fecha de nacimiento</span>
                                <span>{{ \App\Modules\Personal\Support\PersonalFichaCatalog::displayDate($family['after']['fecha_nacimiento']) }}</span>
                            </div>
                            <div class="ficha-field">
                                <span class="ficha-label">Documento</span>
                                <span>{{ $family['after']['tipo_documento'] }} {{ $family['after']['numero_documento'] ?: 'Sin dato' }}</span>
                            </div>
                            <div class="ficha-field">
                                <span class="ficha-label">Teléfono</span>
                                <span>{{ $family['after']['telefono'] ?: 'Sin dato' }}</span>
                            </div>
                            <div class="ficha-field">
                                <span class="ficha-label">Vive con el trabajador</span>
                                <span>{{ $family['after']['vive_con_trabajador'] ? 'Sí' : 'No' }}</span>
                            </div>
                            <div class="ficha-field">
                                <span class="ficha-label">Estudia</span>
                                <span>{{ $family['after']['estudia'] ? 'Sí' : 'No' }}</span>
                            </div>
                            <div class="ficha-field">
                                <span class="ficha-label">Contacto de emergencia</span>
                                <span>{{ $family['after']['contacto_emergencia'] ? 'Sí' : 'No' }}</span>
                            </div>
                        </div>
                    </section>
                @endforeach
            @endif

            @if($ingreso->estado === 'FICHA_RECIBIDA')
                @allowedDirect('personal_ingresos', 'aprobar')
                    <div class="ficha-actions-bar" style="justify-content:flex-start; margin-top:1rem;">
                        @if(!$comparison['conflict'] && $comparison['completeness']['complete'])
                            <form method="POST" action="{{ route('personal.ingresos.actualizacion.accept', $ingreso->id) }}">
                                @csrf
                                <input type="hidden" name="revision" value="{{ $comparison['revision'] }}">
                                <input type="hidden" name="base_hash" value="{{ $comparison['base_hash'] }}">
                                <input type="hidden" name="review_hash" value="{{ $comparison['review_hash'] }}">
                                <button type="submit" class="btn btn-primary">Aceptar actualización</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('personal.ingresos.actualizacion.observe', $ingreso->id) }}">
                            @csrf
                            <input type="hidden" name="revision" value="{{ $comparison['revision'] }}">
                            <label class="ficha-label" for="update-observation">Motivo de observación</label>
                            <textarea class="ficha-textarea" name="reason" id="update-observation" maxlength="1000" required></textarea>
                            <button type="submit" class="btn btn-outline">Observar actualización</button>
                        </form>
                    </div>
                @endallowedDirect
            @endif
        </div>
    </div>
</div>
@endsection
