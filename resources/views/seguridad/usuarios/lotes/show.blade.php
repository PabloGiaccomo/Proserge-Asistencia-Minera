@extends('layouts.app')

@section('title', 'Resultado del alta múltiple - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Resultado del alta múltiple</h1>
                <p class="page-subtitle">{{ count($resultado['filas']) === 1 ? 'Se creó 1 usuario.' : 'Se crearon '.count($resultado['filas']).' usuarios.' }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('usuarios.lotes.create') }}" class="btn btn-outline">Volver al alta múltiple</a>
            </div>
        </div>
    </div>

    <section class="card" aria-labelledby="lote-credenciales-titulo">
        <div class="card-header">
            <h2 class="card-title" id="lote-credenciales-titulo">Credenciales de las cuentas creadas</h2>
        </div>
        <div class="card-body">
            <div class="alert alert-warning" style="margin-bottom: 16px;">
                <strong>Resultado disponible durante 15 minutos desde la creación.</strong>
                <p>Disponible hasta {{ $resultado['vence'] }}. Descarga el Excel antes de que venza el plazo.</p>
                <p>El archivo contiene contraseñas. Guárdalo en un lugar privado y entrega a cada trabajador solo sus propias credenciales.</p>
            </div>

            <form method="POST" action="{{ route('usuarios.lotes.export', ['lote' => $resultado['id']]) }}" style="margin-bottom: 16px;">
                @csrf
                <button type="submit" class="btn btn-primary">Descargar credenciales en Excel</button>
            </form>

            <div class="table-wrapper" tabindex="0" role="region" aria-labelledby="lote-credenciales-titulo">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Trabajador</th>
                            <th scope="col">Usuario</th>
                            <th scope="col">Contraseña</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($resultado['filas'] as $fila)
                            <tr>
                                <td data-label="Trabajador" style="gap: 8px; overflow-wrap: anywhere;"><strong>{{ $fila['trabajador'] }}</strong></td>
                                <td data-label="Usuario" style="gap: 8px; overflow-wrap: anywhere;"><span>{{ $fila['usuario'] }}</span></td>
                                <td data-label="Contraseña" style="gap: 8px; overflow-wrap: anywhere;"><code>{{ $fila['contrasena'] }}</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
@endsection
