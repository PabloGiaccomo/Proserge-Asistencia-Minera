@extends('layouts.app')

@section('title', 'Scope Mina - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Scope Mina</h1>
                <p class="page-subtitle">{{ $usuario->personal?->nombre_completo ?? $usuario->email }}</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('usuarios.show', $usuario->id) }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom: 16px;">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-error" style="margin-bottom: 16px;">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        <div class="card-header">
            <span class="card-title">Minas disponibles</span>
            <span class="card-badge">{{ count($scopeIds) }} seleccionadas</span>
        </div>
        <div class="card-body">
            <form action="{{ route('usuarios.scope-update', $usuario->id) }}" method="POST">
                @csrf
                @method('PUT')

                <p style="margin-bottom: 20px; color: var(--color-text-secondary);">Selecciona las minas a las que este usuario puede acceder.</p>

                <div style="display: grid; gap: 12px; margin-bottom: 20px;">
                    @forelse($minas as $mina)
                        <label style="display: flex; align-items: center; justify-content: space-between; gap: 12px; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; cursor: pointer;">
                            <span>
                                <strong>{{ $mina->nombre }}</strong><br>
                                <span style="color: var(--color-text-secondary); font-size: 13px;">Estado catalogo: {{ strtoupper((string) $mina->estado) }}</span>
                            </span>
                            <input type="checkbox" name="mina_ids[]" value="{{ $mina->id }}" {{ in_array($mina->id, $scopeIds, true) ? 'checked' : '' }}>
                        </label>
                    @empty
                        <div class="empty-state" style="padding: 24px 0;">
                            <h3 class="empty-title">No hay minas registradas</h3>
                            <p class="empty-description">Primero debes registrar minas en el catálogo.</p>
                        </div>
                    @endforelse
                </div>

                <div class="form-actions">
                    <a href="{{ route('usuarios.show', $usuario->id) }}" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Guardar Scope</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
