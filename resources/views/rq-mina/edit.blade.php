@extends('layouts.app')

@section('title', 'RQ Mina - Editar')

@section('content')
<div class="page-header">
    <div class="page-header-content">
        <div>
            <h1 class="page-title">Editar Solicitud</h1>
            <p class="page-subtitle">RQ Mina #{{ $item['id'] ?? '' }}</p>
        </div>
    </div>
</div>

<form action="{{ route('rq-mina.update', $item['id'] ?? '') }}" method="POST" class="form">
    @csrf
    @method('PUT')
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Datos Generales</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="mina_id">Mina</label>
                    <select name="mina_id" id="mina_id" class="form-control" required>
                        @foreach($minas as $mina)
                        <option value="{{ $mina['id'] }}" {{ ($item['mina_id'] ?? '') == $mina['id'] ? 'selected' : '' }}>
                            {{ $mina['nombre'] }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="tipo">Tipo de Solicitud</label>
                    <select name="tipo" id="tipo" class="form-control" required>
                        <option value="preventivo" {{ ($item['tipo'] ?? '') == 'preventivo' ? 'selected' : '' }}>Preventivo</option>
                        <option value="correctivo" {{ ($item['tipo'] ?? '') == 'correctivo' ? 'selected' : '' }}>Correctivo</option>
                        <option value="emergencia" {{ ($item['tipo'] ?? '') == 'emergencia' ? 'selected' : '' }}>Emergencia</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="estado">Estado</label>
                    <select name="estado" id="estado" class="form-control">
                        <option value="borrador" {{ ($item['estado'] ?? 'borrador') == 'borrador' ? 'selected' : '' }}>Borrador</option>
                        <option value="enviado" {{ ($item['estado'] ?? '') == 'enviado' ? 'selected' : '' }}>Enviado</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Ubicación</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label for="destino_tipo">Tipo Destino</label>
                    <select name="destino_tipo" id="destino_tipo" class="form-control" required>
                        <option value="taller" {{ ($item['destino_tipo'] ?? '') == 'taller' ? 'selected' : '' }}>Taller</option>
                        <option value="oficina" {{ ($item['destino_tipo'] ?? '') == 'oficina' ? 'selected' : '' }}>Oficina</option>
                        <option value="mina" {{ ($item['destino_tipo'] ?? '') == 'mina' ? 'selected' : '' }}>Mina</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="destino_id">Destino</label>
                    <input type="number" name="destino_id" id="destino_id" class="form-control" value="{{ $item['destino_id'] ?? '' }}" required>
                </div>
            </div>
        </div>
    </div>

    @if(!empty($item['trabajos']))
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Trabajos Solicitados</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Descripción</th>
                            <th>Cantidad</th>
                            <th>Unidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($item['trabajos'] as $trabajo)
                        <tr>
                            <td>{{ $trabajo['descripcion'] ?? '-' }}</td>
                            <td>{{ $trabajo['cantidad'] ?? '-' }}</td>
                            <td>{{ $trabajo['unidad'] ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    <div class="form-actions">
        <a href="{{ route('rq-mina.index') }}" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary">Guardar Cambios</button>
    </div>
</form>
@endsection