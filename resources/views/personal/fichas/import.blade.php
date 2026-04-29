@extends('layouts.app')

@section('title', 'Importar trabajador desde macro - Proserge')

@section('content')
<div class="module-page ficha-workspace">
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Importar desde macro / contrato</h1>
                <p class="page-subtitle">Carga el archivo de RRHH para generar temporales y abrir la vista de links.</p>
            </div>
            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Volver</a>
            </div>
        </div>
    </div>

    @if(session('error'))
        <div class="ficha-alert ficha-alert-danger">{{ session('error') }}</div>
    @endif

    <div class="ficha-card">
        <div class="ficha-card-header">
            <div>
                <h2 class="ficha-card-title">Archivo base de RRHH</h2>
                <p class="ficha-card-subtitle">Formatos admitidos: Excel, CSV, TXT, DOCX o PDF. Maximo 20 MB.</p>
            </div>
        </div>
        <form method="POST" action="{{ route('personal.fichas.parse') }}" enctype="multipart/form-data" class="ficha-card-body">
            @csrf
            <div class="ficha-fields" style="padding:0;">
                <div class="ficha-field ficha-field-full">
                    <label class="ficha-label" for="macro">Macro o documento de contrato <span class="ficha-required">*</span></label>
                    <input id="macro" class="ficha-input" type="file" name="macro" accept=".xlsx,.xls,.xlsm,.csv,.txt,.docx,.pdf" required>
                    @error('macro') <span class="ficha-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="ficha-actions-bar" style="margin-top:18px;">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary">Subir archivo</button>
            </div>
        </form>
    </div>
</div>
@endsection
