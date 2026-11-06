@extends('layouts.app')
@section('title', 'Revisiones de asistencia - Proserge')
@section('content')
<div class="module-page">
    <h1 class="page-title">Revisiones de asistencia</h1>
    <p>Estas evaluaciones requieren tu decisión porque su asistencia cambió a ausente. La nota queda guardada y espera revisión para contar en los promedios.</p>
    <p><a class="btn btn-outline" href="{{ route('evaluaciones.index') }}">Volver a Evaluaciones</a></p>
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif
    @forelse($rows as $row)
        <section class="card" style="margin-bottom:1rem">
            <div class="card-body">
                <strong>{{ $row->trabajador?->nombre_completo ?: 'Trabajador' }}</strong>
                <p>{{ $row->fecha->format('d/m/Y') }} · Nota original {{ $row->total }} · Pendiente de revisión por su evaluador</p>
                <form method="POST" action="{{ route('evaluaciones.revisiones-asistencia.resolve', $row->id) }}" class="eval-form">
                    @csrf
                    <input type="hidden" name="revision" value="{{ $row->revision_asistencia }}">
                    <label>Decisión
                        <select class="form-control" name="decision" required>
                            <option value="">Selecciona una decisión</option>
                            <option value="RATIFICAR">Ratificar después de corregir y cerrar la asistencia</option>
                            <option value="EXCLUIR">Excluir de los promedios y conservar la nota original</option>
                        </select>
                    </label>
                    <label>Motivo de la decisión<textarea class="form-control" name="motivo" required maxlength="1000"></textarea></label>
                    <button type="submit" class="btn btn-primary">Registrar decisión</button>
                </form>
            </div>
        </section>
    @empty
        <div class="card"><div class="card-body">No tienes revisiones pendientes.</div></div>
    @endforelse
    {{ $rows->links() }}
</div>
@endsection
