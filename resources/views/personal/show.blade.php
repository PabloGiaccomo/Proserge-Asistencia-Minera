@extends('layouts.app')

@section('title', 'Ver Personal - Proserge')

@section('content')
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">{{ $trabajador['nombre'] ?? 'Detalle del trabajador' }}</h1>
                <p class="page-subtitle">{{ $trabajador['puesto'] ?? '-' }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver</a>
                <a href="{{ route('personal.edit', $id) }}" class="btn btn-primary">Editar</a>
                @allowed('personal', 'eliminar')
                    <form method="POST" action="{{ route('personal.destroy', $id) }}" onsubmit="return confirm('Se eliminara por completo este trabajador.');">
                        @csrf
                        <button type="submit" class="btn btn-danger">Eliminar</button>
                    </form>
                @endallowed
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="ficha-alert">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="ficha-alert ficha-alert-danger">{{ session('error') }}</div>
    @endif

    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title">Informacion principal</h2>
                <p class="ficha-card-subtitle">{{ ($trabajador['tipo_documento'] ?? 'DNI') . ' ' . ($trabajador['numero_documento'] ?? $trabajador['dni'] ?? '-') }}</p>
            </div>
            <span class="ficha-status">{{ $trabajador['estado_label'] ?? $trabajador['estado'] ?? '-' }}</span>
        </div>
        <div class="ficha-card-body">
            <div class="ficha-fields" style="padding:0;">
                <div class="ficha-field"><span class="ficha-label">Telefono</span><div class="ficha-input">{{ $trabajador['telefono'] ?? '-' }}</div></div>
                <div class="ficha-field"><span class="ficha-label">Correo</span><div class="ficha-input">{{ $trabajador['correo'] ?? '-' }}</div></div>
                <div class="ficha-field"><span class="ficha-label">Contrato</span><div class="ficha-input">{{ $trabajador['tipo_contrato'] ?? '-' }}</div></div>
                <div class="ficha-field"><span class="ficha-label">Fecha ingreso</span><div class="ficha-input">{{ $trabajador['fecha_ingreso'] ?? '-' }}</div></div>
                <div class="ficha-field ficha-field-wide"><span class="ficha-label">Minas / sedes</span><div class="ficha-input">{{ implode(', ', $trabajador['minas'] ?? []) ?: '-' }}</div></div>
            </div>
        </div>
    </div>

    @if($ficha)
        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Ficha del colaborador</h2>
                    <p class="ficha-card-subtitle">Estado: {{ \App\Modules\Personal\Support\PersonalFichaCatalog::stateLabel($ficha->estado) }}</p>
                </div>
                <div class="page-actions">
                    <a href="{{ route('personal.fichas.review', $ficha->id) }}" class="btn btn-outline">Revisar</a>
                    <a href="{{ route('personal.fichas.pdf', $ficha->id) }}" class="btn btn-primary">PDF</a>
                </div>
            </div>
            <div class="ficha-card-body">
                <div class="ficha-fields" style="padding:0;">
                    <div class="ficha-field"><span class="ficha-label">Creada</span><div class="ficha-input">{{ optional($ficha->created_at)->format('d/m/Y H:i') ?: '-' }}</div></div>
                    <div class="ficha-field"><span class="ficha-label">Enviada</span><div class="ficha-input">{{ optional($ficha->submitted_at)->format('d/m/Y H:i') ?: '-' }}</div></div>
                    <div class="ficha-field"><span class="ficha-label">Aprobada</span><div class="ficha-input">{{ optional($ficha->approved_at)->format('d/m/Y H:i') ?: '-' }}</div></div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
