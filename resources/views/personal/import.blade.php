@extends('layouts.app')

@section('title', 'Importar Personal - Proserge')

@section('content')
<div class="module-page">
    {{-- Page Header --}}
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <h1 class="page-title">Importar Personal</h1>
                <p class="page-subtitle">Carga masiva de trabajadores desde un archivo Excel</p>
            </div>

            <div class="page-actions">
                <a href="{{ route('personal.index') }}" class="btn btn-outline">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5"/>
                        <path d="M12 19l-7-7 7-7"/>
                    </svg>
                    Volver
                </a>
            </div>
        </div>
    </div>

    <form method="POST"
          action="{{ route('personal.importar.post') }}"
          enctype="multipart/form-data"
          class="space-y-6">
        @csrf

        <div class="grid grid-2" style="align-items: stretch;">
            {{-- Card principal --}}
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Archivo Excel</span>
                </div>

                <div class="card-body">
                    <p class="text-muted mb-6">
                        Selecciona o arrastra el archivo que deseas importar.
                    </p>

                    <div id="dropzone"
                         class="empty-state"
                         style="border: 2px dashed #E2E8F0; border-radius: 20px; background: #F8FAFC; cursor: pointer; transition: all .2s;"
                         onclick="document.getElementById('file').click()"
                         ondragover="handleDragOver(event)"
                         ondragleave="handleDragLeave(event)"
                         ondrop="handleDrop(event)">
                        
                        <input type="file"
                               name="file"
                               id="file"
                               class="hidden"
                               accept=".xlsx,.xls"
                               onchange="updateFileName(this)">

                        <div class="empty-icon" style="margin-bottom: 24px;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9"/>
                                <path d="M12 12v9"/>
                                <path d="m16 16-4-4-4 4"/>
                            </svg>
                        </div>

                        <h3 class="empty-title">Arrastra tu archivo aquí</h3>
                        <p class="empty-description" style="max-width: 560px; margin: 0 auto 24px;">
                            También puedes seleccionarlo manualmente. Formatos aceptados:
                            <strong>.xlsx</strong> y <strong>.xls</strong>.
                        </p>

                        <button type="button"
                                onclick="event.stopPropagation(); document.getElementById('file').click()"
                                class="btn btn-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            Seleccionar archivo
                        </button>

                        <div id="fileState"
                             class="badge badge-success"
                             style="display:none; margin-top: 20px; padding: 12px 16px; border-radius: 14px;">
                            <span id="fileName"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Card lateral --}}
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Formato esperado</span>
                </div>

                <div class="card-body">
                    <div class="space-y-4" style="display:flex; flex-direction:column; gap:16px;">
                        <div class="alert-item info" style="align-items:flex-start;">
                            <div class="alert-item-label" style="line-height:1.7;">
                                El archivo debe contener una hoja llamada <strong>"Resumen GRAL"</strong> o usar la primera hoja disponible.
                            </div>
                        </div>

                        <div class="alert-item info" style="align-items:flex-start;">
                            <div class="alert-item-label" style="line-height:1.7;">
                                Columnas requeridas: <strong>DNI</strong>, <strong>Apellidos y Nombres</strong>, <strong>Cargo/Puesto</strong> y <strong>Fecha Ingreso</strong>.
                            </div>
                        </div>

                        <div class="alert-item info" style="align-items:flex-start;">
                            <div class="alert-item-label" style="line-height:1.7;">
                                Estados válidos para unidades mineras: <strong>HABILITADO</strong>, <strong>EN PROCESO</strong> o <strong>NO HABILITADO</strong>.
                            </div>
                        </div>

                        <div class="alert-item info" style="align-items:flex-start;">
                            <div class="alert-item-label" style="line-height:1.7;">
                                La columna <strong>OCUPACIÓN</strong> determina si el trabajador es supervisor (<strong>E</strong> o <strong>P</strong>).
                            </div>
                        </div>

                        <div class="badge badge-warning" style="padding: 14px 16px; border-radius: 14px; display:block; line-height:1.7;">
                            Verifica el formato antes de importar para evitar errores de carga.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert-error">
                <div>
                    <strong>No se pudo procesar la importación</strong>
                    <div style="margin-top:8px;">
                        @foreach ($errors->all() as $error)
                            <div>• {{ $error }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <div class="form-actions" style="padding-top: 0; margin-top: 0; border-top: none;">
            <a href="{{ route('personal.index') }}" class="btn btn-secondary">
                Cancelar
            </a>

            <button type="submit" id="submitBtn" class="btn btn-primary" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Importar Personal
            </button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
function updateFileName(input) {
    if (input.files && input.files[0]) {
        showSelectedFile(input.files[0].name);
    }
}

function handleDragOver(event) {
    event.preventDefault();
    document.getElementById('dropzone').style.borderColor = '#19D3C5';
    document.getElementById('dropzone').style.background = '#ECFEFF';
}

function handleDragLeave(event) {
    event.preventDefault();
    document.getElementById('dropzone').style.borderColor = '#E2E8F0';
    document.getElementById('dropzone').style.background = '#F8FAFC';
}

function handleDrop(event) {
    event.preventDefault();

    const dz = document.getElementById('dropzone');
    const input = document.getElementById('file');

    dz.style.borderColor = '#E2E8F0';
    dz.style.background = '#F8FAFC';

    const file = event.dataTransfer.files[0];
    if (!file) return;

    const isValid = file.name.endsWith('.xlsx') || file.name.endsWith('.xls');
    if (!isValid) return;

    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    input.files = dataTransfer.files;

    showSelectedFile(file.name);
}

function showSelectedFile(name) {
    const state = document.getElementById('fileState');
    document.getElementById('fileName').textContent = name;
    state.style.display = 'inline-flex';
    document.getElementById('submitBtn').disabled = false;
}
</script>
@endpush