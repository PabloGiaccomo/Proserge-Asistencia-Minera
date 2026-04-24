@extends('layouts.app')

@section('title', 'Importar Personal - Proserge')

@section('content')
<div class="module-page">
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

    @if (session('success'))
        <div class="alert-item success" style="margin-bottom:16px; align-items:flex-start;">
            <div class="alert-item-label" style="line-height:1.6;">
                {{ session('success') }}
            </div>
        </div>
        
        @if(session('import_result'))
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header">
                <span class="card-title">Resultado de Importación</span>
            </div>
            <div class="card-body">
                @php($importResult = session('import_result'))

<div class="grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:16px;">
                    <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $importResult['nuevos'] ?? 0 }}</strong><div class="text-muted">Nuevos</div></div></div>
                    <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $importResult['actualizados'] ?? 0 }}</strong><div class="text-muted">Actualizados</div></div></div>
                    <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $importResult['camposActualizados'] ?? 0 }}</strong><div class="text-muted">Campos modificados</div></div></div>
                    <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $importResult['reactivados'] ?? 0 }}</strong><div class="text-muted">Reactivados</div></div></div>
                    <div class="card" style="box-shadow:none; border:1px solid #e2e8f0;"><div class="card-body"><strong>{{ $importResult['inactivados'] ?? 0 }}</strong><div class="text-muted">Inactivados</div></div></div>
                </div>

                @if(!empty($importResult['cambiosDetectados']))
                    @if(($importResult['cambiosDetectadosTotal'] ?? 0) > count($importResult['cambiosDetectados'] ?? []))
                        <div class="alert-item" style="margin-bottom:12px; align-items:flex-start; background:#fff7ed; border:1px solid #fdba74;">
                            <div class="alert-item-label" style="line-height:1.6; color:#9a3412;">
                                Se muestran {{ count($importResult['cambiosDetectados'] ?? []) }} cambios de {{ $importResult['cambiosDetectadosTotal'] ?? 0 }} trabajadores actualizados.
                            </div>
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>DNI</th>
                                    <th>Trabajador</th>
                                    <th>Cambios detectados</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($importResult['cambiosDetectados'] as $item)
                                    <tr>
                                        <td>{{ $item['dni'] ?? '-' }}</td>
                                        <td>{{ $item['nombre'] ?? '-' }}</td>
                                        <td>
                                            <div style="display:flex; flex-direction:column; gap:8px;">
                                                @foreach($item['cambios'] ?? [] as $cambio)
                                                    <div style="padding:8px 10px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc;">
                                                        <strong>{{ $cambio['label'] ?? 'Campo' }}</strong><br>
                                                        <span class="text-muted">Antes:</span> {{ $cambio['antes'] ?? '-' }}<br>
                                                        <span class="text-muted">Después:</span> {{ $cambio['despues'] ?? '-' }}
                                                    </div>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted" style="margin:0;">No se detectaron cambios de datos sobre trabajadores existentes en esta importación.</p>
                @endif

                @if(!empty($importResult['nuevosDetalle']))
                    <div style="margin-top:20px;">
                        <h3 style="margin:0 0 12px; font-size:16px;">Trabajadores nuevos detectados</h3>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
<tr>
                                        <th>DNI</th>
                                        <th>Nombre</th>
                                        <th>Puesto</th>
                                        <th>Ocupación</th>
                                        <th>Contrato</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($importResult['nuevosDetalle'] as $item)
                                        <tr>
<td>{{ $item['dni'] ?? '-' }}</td>
                                            <td>{{ $item['nombre'] ?? '-' }}</td>
                                            <td>{{ $item['puesto'] ?? '-' }}</td>
                                            <td>{{ $item['ocupacion'] ?? '-' }}</td>
                                            <td>{{ $item['contrato'] ?? '-' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if(!empty($importResult['reactivadosDetalle']) || !empty($importResult['inactivadosDetalle']))
                    <div class="grid" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; margin-top:20px;">
                        @if(!empty($importResult['reactivadosDetalle']))
                            <div>
                                <h3 style="margin:0 0 12px; font-size:16px;">Trabajadores reactivados</h3>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>DNI</th>
                                                <th>Nombre</th>
                                                <th>Cambio</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($importResult['reactivadosDetalle'] as $item)
                                                <tr>
                                                    <td>{{ $item['dni'] ?? '-' }}</td>
                                                    <td>{{ $item['nombre'] ?? '-' }}</td>
                                                    <td>{{ $item['antes'] ?? '-' }} -> {{ $item['despues'] ?? '-' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif

                        @if(!empty($importResult['inactivadosDetalle']))
                            <div>
                                <h3 style="margin:0 0 12px; font-size:16px;">Trabajadores inactivados</h3>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>DNI</th>
                                                <th>Nombre</th>
                                                <th>Cambio</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($importResult['inactivadosDetalle'] as $item)
                                                <tr>
                                                    <td>{{ $item['dni'] ?? '-' }}</td>
                                                    <td>{{ $item['nombre'] ?? '-' }}</td>
                                                    <td>{{ $item['antes'] ?? '-' }} -> {{ $item['despues'] ?? '-' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
        @endif
    @endif

    @if ($errors->any())
        <div class="alert-error" style="margin-bottom:16px;">
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

    <form method="POST"
          action="{{ route('personal.importar.post') }}"
          enctype="multipart/form-data"
            id="importForm"
          class="space-y-6">
        @csrf

        <div style="display:flex; flex-direction:column; gap:16px;">
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
                         onclick="openFilePicker()"
                         ondragover="handleDragOver(event)"
                         ondragleave="handleDragLeave(event)"
                         ondrop="handleDrop(event)">

                        <input type="file"
                               name="file"
                               id="file"
                               class="hidden"
                               accept=".xlsx,.xls"
                               onchange="updateFileName(this)">

                        <div class="empty-icon" style="margin-bottom:24px;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9"/>
                                <path d="M12 12v9"/>
                                <path d="m16 16-4-4-4 4"/>
                            </svg>
                        </div>

                        <h3 class="empty-title" id="dropzoneTitle">Arrastra tu archivo aquí</h3>
                        <p class="empty-description" id="dropzoneDescription" style="max-width:560px; margin:0 auto 24px;">
                            También puedes seleccionarlo manualmente. Formatos aceptados:
                            <strong>.xlsx</strong> y <strong>.xls</strong>.
                        </p>

                        <button type="button"
                                id="selectFileButton"
                                onclick="event.stopPropagation(); openFilePicker();"
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
                             style="display:none; margin-top:14px; padding:12px 16px; border-radius:14px; max-width:100%; white-space:normal;">
                            Archivo agregado: <span id="fileName"></span>
                        </div>

                        <div id="inlineActions" style="display:none; margin-top:16px; gap:10px; justify-content:center; flex-wrap:wrap;">
                            <a href="{{ route('personal.index') }}" class="btn btn-secondary" onclick="event.stopPropagation();">Cancelar</a>

                            <button type="button" id="changeFileButton" class="btn btn-outline" style="padding:8px 12px; font-size:12px;" onclick="event.stopPropagation(); changeSelectedFile();">
                                Cambiar archivo
                            </button>

                            <button type="submit" id="submitBtnInline" class="btn btn-primary" onclick="event.stopPropagation();" disabled>
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                    <polyline points="17 8 12 3 7 8"/>
                                    <line x1="12" y1="3" x2="12" y2="15"/>
                                </svg>
                                Importar Personal
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Formato esperado</span>
                </div>

                <div class="card-body" style="display:flex; flex-direction:column; gap:10px;">
                    <ul style="margin:0; padding-left:18px; color:#475569; line-height:1.7;">
                        <li>Hoja recomendada: <strong>RESUMEN GRAL</strong> (o primera hoja disponible).</li>
                        <li>Columnas base: <strong>DNI</strong>, <strong>Apellidos y Nombres</strong>, <strong>Cargo/Puesto</strong>, <strong>Fecha Ingreso</strong>.</li>
                        <li>Estados por mina: <strong>HABILITADO</strong>, <strong>EN PROCESO</strong>, <strong>NO HABILITADO</strong>.</li>
                        <li>La columna <strong>OCUPACIÓN</strong> define supervisor cuando es <strong>E</strong> o <strong>P</strong>.</li>
                    </ul>

                    <div class="badge badge-warning" style="padding:10px 14px; border-radius:12px; display:inline-flex; width:fit-content;">
                        Revisa el formato antes de importar para evitar errores.
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div id="importLoadingOverlay"
         aria-live="polite"
         aria-busy="true"
         style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,.35); align-items:center; justify-content:center; padding:16px;">
        <div style="background:#ffffff; border-radius:16px; padding:16px 18px; display:flex; align-items:center; gap:12px; box-shadow:0 12px 38px rgba(15,23,42,.25); max-width:420px; width:100%;">
            <div id="loadingSpinner" style="width:22px; height:22px; border:3px solid #D1FAF5; border-top-color:#0F766E; border-radius:9999px;"></div>
            <div>
                <div style="font-weight:700; color:#0F172A;">Importando personal...</div>
                <div style="font-size:13px; color:#475569;">Estamos procesando el archivo. Esto puede tardar unos segundos.</div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let importLocked = false;
let importSubmitting = false;
let loadingSpinnerAnimation = null;

function openFilePicker() {
    if (importLocked) return;

    const input = document.getElementById('file');
    if (!input) return;
    input.click();
}

function updateFileName(input) {
    if (!input || importLocked) return;

    if (input.files && input.files[0]) {
        showSelectedFile(input.files[0].name);
    }
}

function handleDragOver(event) {
    event.preventDefault();
    if (importLocked) return;

    const dropzone = document.getElementById('dropzone');
    dropzone.style.borderColor = '#19D3C5';
    dropzone.style.background = '#ECFEFF';
}

function handleDragLeave(event) {
    event.preventDefault();
    if (importLocked) return;

    const dropzone = document.getElementById('dropzone');
    dropzone.style.borderColor = '#E2E8F0';
    dropzone.style.background = '#F8FAFC';
}

function handleDrop(event) {
    event.preventDefault();
    if (importLocked) return;

    const dropzone = document.getElementById('dropzone');
    const input = document.getElementById('file');

    dropzone.style.borderColor = '#E2E8F0';
    dropzone.style.background = '#F8FAFC';

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
    const dropzone = document.getElementById('dropzone');
    const selectFileButton = document.getElementById('selectFileButton');
    const dropzoneTitle = document.getElementById('dropzoneTitle');
    const dropzoneDescription = document.getElementById('dropzoneDescription');
    const inlineActions = document.getElementById('inlineActions');
    const submitBtnInline = document.getElementById('submitBtnInline');
    const fileName = document.getElementById('fileName');

    fileName.textContent = name;
    state.style.display = 'inline-flex';
    submitBtnInline.disabled = false;

    importLocked = true;
    dropzone.style.cursor = 'not-allowed';
    dropzone.style.borderColor = '#A7F3D0';
    dropzone.style.background = '#F0FDF4';
    selectFileButton.style.display = 'none';
    inlineActions.style.display = 'inline-flex';
    dropzoneTitle.textContent = 'Archivo cargado correctamente';
    dropzoneDescription.textContent = 'Si deseas reemplazarlo usa el botón Cambiar archivo.';
}

function clearSelectedFileState() {
    const state = document.getElementById('fileState');
    const input = document.getElementById('file');
    const dropzone = document.getElementById('dropzone');
    const selectFileButton = document.getElementById('selectFileButton');
    const dropzoneTitle = document.getElementById('dropzoneTitle');
    const dropzoneDescription = document.getElementById('dropzoneDescription');
    const inlineActions = document.getElementById('inlineActions');
    const submitBtnInline = document.getElementById('submitBtnInline');

    input.value = '';
    importLocked = false;

    state.style.display = 'none';
    submitBtnInline.disabled = true;
    inlineActions.style.display = 'none';
    selectFileButton.style.display = 'inline-flex';

    dropzone.style.cursor = 'pointer';
    dropzone.style.borderColor = '#E2E8F0';
    dropzone.style.background = '#F8FAFC';
    dropzoneTitle.textContent = 'Arrastra tu archivo aquí';
    dropzoneDescription.innerHTML = 'También puedes seleccionarlo manualmente. Formatos aceptados: <strong>.xlsx</strong> y <strong>.xls</strong>.';
}

function changeSelectedFile() {
    clearSelectedFileState();
    openFilePicker();
}

function setLoadingState(isLoading) {
    const overlay = document.getElementById('importLoadingOverlay');
    const spinner = document.getElementById('loadingSpinner');
    const input = document.getElementById('file');
    const submitBtnInline = document.getElementById('submitBtnInline');
    const changeFileButton = document.getElementById('changeFileButton');
    const selectFileButton = document.getElementById('selectFileButton');
    const dropzone = document.getElementById('dropzone');

    if (!overlay || !spinner || !input || !submitBtnInline || !changeFileButton || !selectFileButton || !dropzone) {
        return;
    }

    if (isLoading) {
        importSubmitting = true;
        importLocked = true;

        overlay.style.display = 'flex';

        input.readOnly = true;
        submitBtnInline.disabled = true;
        changeFileButton.disabled = true;
        selectFileButton.disabled = true;

        dropzone.style.pointerEvents = 'none';
        submitBtnInline.textContent = 'Importando...';

        if (loadingSpinnerAnimation) {
            loadingSpinnerAnimation.cancel();
        }

        loadingSpinnerAnimation = spinner.animate(
            [
                { transform: 'rotate(0deg)' },
                { transform: 'rotate(360deg)' }
            ],
            {
                duration: 900,
                iterations: Infinity,
                easing: 'linear'
            }
        );
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('importForm');
    if (!form) return;

    form.addEventListener('submit', function (event) {
        const input = document.getElementById('file');

        if (importSubmitting) {
            event.preventDefault();
            return;
        }

        if (!input || !input.files || !input.files.length) {
            return;
        }

        setLoadingState(true);
    });
});
</script>
@endpush
