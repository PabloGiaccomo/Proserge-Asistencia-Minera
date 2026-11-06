@extends('layouts.app')

@section('title', 'Alta múltiple de usuarios - Proserge')

@section('content')
<div class="module-page" data-usuario-alta-lote data-max-filas="{{ $maxFilas }}">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Alta múltiple de usuarios</h1>
                <p class="page-subtitle">Selecciona hasta {{ $maxFilas }} trabajadores de Personal y revisa sus correos antes de crear las cuentas.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('usuarios.create') }}" class="btn btn-outline">Volver al alta individual</a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="alert alert-error" role="alert" style="margin-bottom: 16px;">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-error" role="alert" style="margin-bottom: 16px;">
            <strong>No se pudo completar el alta múltiple.</strong>
            <p>{{ $errors->first() }}</p>
        </div>
    @endif

    <div class="grid" style="align-items: start; grid-template-columns: repeat(auto-fit, minmax(min(100%, 25rem), 1fr));">
        <section class="card" aria-labelledby="lote-busqueda-titulo" style="min-width: 0;">
            <div class="card-header">
                <h2 class="card-title" id="lote-busqueda-titulo">1. Agregar trabajadores</h2>
            </div>
            <div class="card-body">
                <p style="margin-bottom: 12px;">Puedes hacer varias búsquedas. Los trabajadores que agregues permanecerán en la selección.</p>
                @include('seguridad.usuarios.partials.buscador-trabajador', ['modo' => 'lote'])
                <p data-lote-feedback role="status" aria-live="polite" style="margin-top: 12px;"></p>
                <div class="form-actions" style="justify-content: flex-start;">
                    <a href="#lote-revision-titulo" class="btn btn-outline" data-lote-jump>Ver selección ({{ count($seleccion) }})</a>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="lote-revision-titulo" style="min-width: 0;">
            <div class="card-header">
                <h2 class="card-title" id="lote-revision-titulo" tabindex="-1">2. Revisar y crear cuentas</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('usuarios.lotes.store') }}" data-lote-form>
                    @csrf
                    <input type="hidden" name="lote_id" value="{{ $loteId }}">

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required" for="lote-rol">Rol inicial para todas las cuentas</label>
                            <select class="form-control" id="lote-rol" name="rol_id" required @error('rol_id') aria-invalid="true" aria-describedby="lote-rol-error" @enderror>
                                <option value="">Selecciona un rol</option>
                                @foreach($roles as $rol)
                                    <option value="{{ $rol->id }}" @selected(old('rol_id') === $rol->id)>{{ $rol->nombre }}</option>
                                @endforeach
                            </select>
                            @error('rol_id')
                                <p class="alert alert-error" id="lote-rol-error" style="margin-top: 8px;">{{ $message }}</p>
                            @enderror
                        </div>
                        @if($hasEstadoColumn)
                            <div class="form-group">
                                <label class="form-label" for="lote-estado">Estado inicial de las cuentas</label>
                                <select class="form-control" id="lote-estado" name="estado" @error('estado') aria-invalid="true" aria-describedby="lote-estado-error" @enderror>
                                    <option value="ACTIVO" @selected(old('estado', 'ACTIVO') === 'ACTIVO')>Activo</option>
                                    <option value="INACTIVO" @selected(old('estado') === 'INACTIVO')>Inactivo</option>
                                </select>
                                @error('estado')
                                    <p class="alert alert-error" id="lote-estado-error" style="margin-top: 8px;">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    </div>

                    @if($roles->isEmpty())
                        <p class="alert alert-error" style="margin-bottom: 12px;">No hay roles disponibles para crear las cuentas.</p>
                    @endif

                    <p style="margin-bottom: 12px;">Revisa que cada correo corresponda al trabajador seleccionado. El sistema genera una contraseña distinta para cada cuenta.</p>
                    <p data-lote-empty @if(count($seleccion)) hidden @endif style="margin-bottom: 12px;">Aún no agregaste trabajadores.</p>

                    @php
                        $filasVista = $seleccion + ['plantilla' => null];
                    @endphp
                    <div data-lote-rows style="display: grid; gap: 12px;">
                        @foreach($filasVista as $indice => $fila)
                            @php
                                $esPlantilla = $indice === 'plantilla';
                                $nombre = $fila['nombre'] ?? 'Trabajador no disponible';
                                $datosTrabajador = implode(' · ', array_filter([
                                    !empty($fila['dni']) ? 'DNI '.$fila['dni'] : null,
                                    $fila['puesto'] ?? null,
                                ]));
                                $emailError = "filas.$indice.email";
                                $filaError = $errors->first("filas.$indice") ?: $errors->first("filas.$indice.personal_id");
                            @endphp
                            @if($esPlantilla)
                                <template data-lote-template>
                            @endif
                            <article class="card" data-lote-row style="box-shadow: none; min-width: 0;">
                                <div class="card-body" style="padding: 14px;">
                                    <strong data-lote-name style="overflow-wrap: anywhere;">{{ $esPlantilla ? '' : $nombre }}</strong>
                                    <p data-lote-meta class="text-muted" style="overflow-wrap: anywhere;">{{ $datosTrabajador }}</p>
                                    <input type="hidden" name="filas[{{ $indice }}][personal_id]" value="{{ $fila['id'] ?? '' }}" data-lote-personal>

                                    <div class="form-group" style="margin-top: 12px; margin-bottom: 8px;">
                                        <label class="form-label required" for="lote-email-{{ $indice }}" data-lote-email-label>Correo electrónico</label>
                                        <input type="email" class="form-control" id="lote-email-{{ $indice }}" name="filas[{{ $indice }}][email]" value="{{ $fila['email'] ?? '' }}" maxlength="191" autocomplete="off" autocapitalize="none" spellcheck="false" required data-lote-email
                                            @error($emailError) aria-invalid="true" aria-describedby="lote-email-error-{{ $indice }}" @enderror>
                                        @error($emailError)
                                            <p class="alert alert-error" id="lote-email-error-{{ $indice }}" style="margin-top: 8px;">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    @if($filaError)
                                        <p class="alert alert-error" style="margin-bottom: 8px;">{{ $filaError }}</p>
                                    @endif
                                    <button type="button" class="btn btn-outline" data-lote-remove aria-label="Quitar a {{ $nombre }} de la selección">Quitar</button>
                                </div>
                            </article>
                            @if($esPlantilla)
                                </template>
                            @endif
                        @endforeach
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" data-lote-submit @disabled(count($seleccion) === 0 || $roles->isEmpty())>
                            {{ count($seleccion) === 1 ? 'Crear 1 usuario' : 'Crear '.count($seleccion).' usuarios' }}
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </div>

    @if($lotesRecientes !== [])
        <section class="card" aria-labelledby="lote-recientes-titulo" style="margin-top: 16px;">
            <div class="card-header">
                <h2 class="card-title" id="lote-recientes-titulo">Lotes recientes</h2>
            </div>
            <div class="card-body">
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Creado</th>
                                <th scope="col">Usuarios</th>
                                <th scope="col">Disponible hasta</th>
                                <th scope="col">Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($lotesRecientes as $reciente)
                                <tr>
                                    <td data-label="Creado">{{ $reciente['creado'] }}</td>
                                    <td data-label="Usuarios">{{ $reciente['cantidad'] }}</td>
                                    <td data-label="Disponible hasta">{{ $reciente['vence'] }}</td>
                                    <td data-label="Resultado"><a href="{{ route('usuarios.lotes.show', ['lote' => $reciente['id']]) }}" class="btn btn-outline">Ver resultado</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
</div>
@endsection
