@extends('layouts.public')

@section('title', 'Ingreso de colaborador - Proserge')

@section('content')
@php
    $errors = $errors ?? new \Illuminate\Support\ViewErrorBag();
    $fichaSentSuccessfully = str_contains((string) session('success'), 'Ficha enviada correctamente');
@endphp
<style>
    .ingreso-public-container {
        width: min(1120px, calc(100vw - 28px));
        margin: 18px auto 42px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .ingreso-public-hero {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 18px 20px;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
    }

    .ingreso-public-hero h1 {
        margin: 0;
        color: #0f172a;
        font-size: 26px;
        line-height: 1.15;
    }

    .ingreso-public-hero p,
    .ingreso-public-copy {
        margin: 6px 0 0;
        color: #64748b;
        font-size: 14px;
        line-height: 1.5;
    }

    .ingreso-key-form {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: end;
        padding: 18px;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #fff;
    }

    .ingreso-guide-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .ingreso-guide-step {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr);
        gap: 10px;
        padding: 14px;
        border: 1px solid #dbeafe;
        border-radius: 10px;
        background: #f8fbff;
    }

    .ingreso-guide-number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 999px;
        background: #0f766e;
        color: #fff;
        font-size: 13px;
        font-weight: 800;
    }

    .ingreso-guide-step strong {
        display: block;
        margin-bottom: 5px;
        color: #0f172a;
        font-size: 13px;
    }

    .ingreso-guide-step p,
    .ingreso-doc-list li {
        margin: 0;
        color: #475569;
        font-size: 13px;
        line-height: 1.55;
    }

    .ingreso-doc-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin: 0;
        padding-left: 18px;
    }

    .ingreso-readonly {
        height: auto;
        min-height: 42px;
        display: flex;
        align-items: center;
        white-space: pre-wrap;
    }

    .ingreso-file-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
        margin-bottom: 8px;
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        background: #f8fafc;
        color: #334155;
        font-size: 13px;
    }

    .ingreso-declarations {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .ingreso-submit-status {
        display: grid;
        grid-template-columns: 38px minmax(0, 1fr);
        gap: 12px;
        align-items: center;
        padding: 14px 16px;
        margin-top: 14px;
        border: 1px solid #99f6e4;
        border-radius: 10px;
        background: #ecfeff;
        color: #0f766e;
    }

    .ingreso-submit-status[hidden] {
        display: none;
    }

    .ingreso-submit-status strong {
        display: block;
        color: #0f766e;
        font-size: 14px;
    }

    .ingreso-submit-status p {
        margin: 3px 0 0;
        color: #475569;
        font-size: 13px;
        line-height: 1.45;
    }

    .ingreso-submit-spinner {
        width: 30px;
        height: 30px;
        border: 3px solid #ccfbf1;
        border-top-color: #0f766e;
        border-radius: 999px;
        animation: ingreso-spin 0.8s linear infinite;
    }

    @keyframes ingreso-spin {
        to {
            transform: rotate(360deg);
        }
    }

    [data-uppercase="1"],
    input[data-uppercase="1"],
    textarea[data-uppercase="1"] {
        text-transform: uppercase;
    }

    .signature-pad {
        touch-action: none !important;
    }

    .ficha-file-compress-hint {
        font-size: 12px;
        margin-top: 5px;
        font-weight: 600;
        display: block;
    }

    .ficha-file-compress-hint.is-loading {
        color: #0f766e;
    }

    .ficha-file-compress-hint.is-success {
        color: #15803d;
    }

    @media (max-width: 760px) {
        .ingreso-public-hero,
        .ingreso-key-form {
            grid-template-columns: 1fr;
            flex-direction: column;
            align-items: stretch;
        }

        .ingreso-guide-grid,
        .ingreso-doc-list {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="ingreso-public-container ficha-workspace" data-ingreso-page data-ingreso-submitted="{{ $fichaSentSuccessfully ? '1' : '0' }}">
    <div class="ingreso-public-hero">
        <div>
            <h1>Ficha del colaborador</h1>
            <p>Completa tu informacion con calma y revisala antes de enviarla.</p>
        </div>
        <span class="ficha-status {{ $authorized ? 'ficha-status-pending' : 'ficha-status-expired' }}">
            {{ $authorized ? 'Clave validada' : 'Requiere clave diaria' }}
        </span>
    </div>

    @if(session('success'))
        <div class="ficha-alert">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="ficha-alert ficha-alert-danger">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="ficha-alert ficha-alert-danger">
            <strong>No se pudo enviar la informacion.</strong>
            <ul style="margin:8px 0 0 18px;padding:0;">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(!$authorized)
        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Ingresa la clave diaria</h2>
                    <p class="ficha-card-subtitle">Solicita la clave al trabajador interno que te envio el enlace.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('personal.ingresos.public.key') }}" class="ingreso-key-form">
                @csrf
                <div class="ficha-field">
                    <label class="ficha-label" for="clave">Clave diaria</label>
                    <input id="clave" class="ficha-input" name="clave" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary">Validar clave</button>
            </form>
        </div>
    @else
        <div class="ficha-card">
            <div class="ficha-card-header">
                <div>
                    <h2 class="ficha-card-title">Guia rapida</h2>
                    <p class="ficha-card-subtitle">Sigue estos pasos para evitar observaciones.</p>
                </div>
            </div>
            <div class="ficha-card-body">
                @include('ficha-public.partials.quick-guide')
            </div>
        </div>

        <form method="POST" action="{{ route('personal.ingresos.public.submit') }}" enctype="multipart/form-data" data-ingreso-submit>
            @csrf
            @php($submissionUuid = old('submission_uuid') ?: (string) \Illuminate\Support\Str::uuid())
            <input type="hidden" name="submission_uuid" value="{{ $submissionUuid }}" data-ingreso-submission-uuid>
            @include('personal.fichas.partials.ingreso-form-fields', [
                'readonly' => false,
                'formMode' => 'public',
                'archivos' => collect(),
                'firmaBase64' => old('firma_base64', ''),
            ])
            <div class="ingreso-submit-status" data-ingreso-submit-status hidden>
                <span class="ingreso-submit-spinner" aria-hidden="true"></span>
                <div>
                    <strong>Enviando ficha...</strong>
                    <p>Primero se guardan tus datos y luego los archivos. No cierres esta ventana hasta ver la confirmacion.</p>
                </div>
            </div>
            <div class="ficha-actions-bar">
                <button type="submit" class="btn btn-primary" data-loading-text="Enviando ficha...">Enviar ficha</button>
            </div>
        </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const page = document.querySelector('[data-ingreso-page]');
        if (!page) {
            return;
        }

        const storagePrefix = 'proserge:personal-ingreso-draft:';
        const activeSubmissionKey = storagePrefix + 'active';

        if (page.dataset.ingresoSubmitted === '1') {
            try {
                Object.keys(window.localStorage || {})
                    .filter(function (key) {
                        return key.indexOf(storagePrefix) === 0;
                    })
                    .forEach(function (key) {
                        window.localStorage.removeItem(key);
                    });
            } catch (error) {
                // El borrador local es una ayuda; si el navegador lo bloquea, la ficha sigue funcionando.
            }

            return;
        }

        const form = document.querySelector('form[data-ingreso-submit]');
        if (!form) {
            return;
        }

        const uuidInput = form.querySelector('[data-ingreso-submission-uuid]');
        if (!uuidInput) {
            return;
        }

        let activeSubmissionUuid = null;
        try {
            activeSubmissionUuid = window.localStorage.getItem(activeSubmissionKey);
        } catch (error) {
            activeSubmissionUuid = null;
        }

        if (activeSubmissionUuid && /^[A-Za-z0-9._-]{1,64}$/.test(activeSubmissionUuid)) {
            uuidInput.value = activeSubmissionUuid;
        }

        if (!uuidInput.value) {
            uuidInput.value = window.crypto && typeof window.crypto.randomUUID === 'function'
                ? window.crypto.randomUUID()
                : String(Date.now()) + '-' + Math.random().toString(16).slice(2);
        }

        try {
            window.localStorage.setItem(activeSubmissionKey, uuidInput.value);
        } catch (error) {
            // La ficha puede enviarse aunque el navegador no permita almacenamiento local.
        }

        const storageKey = storagePrefix + uuidInput.value;
        const fieldsForDraft = function () {
            return Array.from(form.elements).filter(function (field) {
                if (!field.name || field.name === '_token' || field.name === 'submission_uuid' || field.type === 'file') {
                    return false;
                }

                return ['INPUT', 'SELECT', 'TEXTAREA'].indexOf(field.tagName) !== -1;
            });
        };

        const fieldSelector = function (name) {
            return '[name="' + String(name).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]';
        };

        const readDraft = function () {
            try {
                return JSON.parse(window.localStorage.getItem(storageKey) || '{}') || {};
            } catch (error) {
                return {};
            }
        };

        const writeDraft = function () {
            const draft = {};
            fieldsForDraft().forEach(function (field) {
                if (field.type === 'checkbox' || field.type === 'radio') {
                    draft[field.name] = field.checked;
                    return;
                }

                draft[field.name] = field.value;
            });

            try {
                window.localStorage.setItem(storageKey, JSON.stringify(draft));
            } catch (error) {
                // No bloqueamos el envio si el almacenamiento local no esta disponible.
            }
        };

        const restoreDraft = function () {
            const draft = readDraft();
            Object.keys(draft).forEach(function (name) {
                form.querySelectorAll(fieldSelector(name)).forEach(function (field) {
                    if (field.type === 'checkbox' || field.type === 'radio') {
                        field.checked = Boolean(draft[name]);
                        return;
                    }

                    if (!field.value) {
                        field.value = draft[name];
                    }
                });
            });
        };

        restoreDraft();

        let saveTimer = null;
        const scheduleSave = function () {
            window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(writeDraft, 250);
        };

        form.addEventListener('input', scheduleSave, true);

        let activeCompressions = 0;
        let submitPending = false;

        const updateFileHint = function (input, text, type) {
            let hint = input.nextElementSibling;
            if (!hint || !hint.matches('[data-file-compress-hint]')) {
                hint = document.createElement('span');
                hint.setAttribute('data-file-compress-hint', '');
                hint.className = 'ficha-file-compress-hint';
                input.insertAdjacentElement('afterend', hint);
            }
            hint.textContent = text;
            hint.className = 'ficha-file-compress-hint ' + (type === 'loading' ? 'is-loading' : (type === 'success' ? 'is-success' : ''));
            hint.style.display = text ? 'block' : 'none';
        };

        const compressImageFile = function (file) {
            return new Promise(function (resolve) {
                if (!file) {
                    return resolve(file);
                }

                const isImage = (file.type && file.type.indexOf('image/') === 0)
                    || /\.(jpe?g|png|webp)$/i.test(file.name);

                if (!isImage) {
                    return resolve(file);
                }

                if (file.type === 'image/svg+xml' || file.type === 'image/gif') {
                    return resolve(file);
                }

                const maxDimension = 1920;
                const quality = 0.82;
                const objectUrl = URL.createObjectURL(file);
                const img = new Image();

                img.onload = function () {
                    URL.revokeObjectURL(objectUrl);
                    let width = img.naturalWidth || img.width;
                    let height = img.naturalHeight || img.height;

                    if (file.size <= 1.2 * 1024 * 1024 && width <= maxDimension && height <= maxDimension) {
                        return resolve(file);
                    }

                    if (width > maxDimension || height > maxDimension) {
                        if (width > height) {
                            height = Math.round((height * maxDimension) / width);
                            width = maxDimension;
                        } else {
                            width = Math.round((width * maxDimension) / height);
                            height = maxDimension;
                        }
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, width, height);
                    ctx.drawImage(img, 0, 0, width, height);

                    canvas.toBlob(function (blob) {
                        if (!blob || blob.size >= file.size) {
                            return resolve(file);
                        }

                        const baseName = file.name.replace(/\.[^.]+$/, '');
                        const fileName = (baseName || 'foto') + '.jpg';
                        let compressedFile;
                        try {
                            compressedFile = new File([blob], fileName, {
                                type: 'image/jpeg',
                                lastModified: Date.now()
                            });
                        } catch (e) {
                            blob.name = fileName;
                            blob.lastModifiedDate = new Date();
                            compressedFile = blob;
                        }

                        resolve(compressedFile);
                    }, 'image/jpeg', quality);
                };

                img.onerror = function () {
                    URL.revokeObjectURL(objectUrl);
                    resolve(file);
                };

                img.src = objectUrl;
            });
        };

        const processFileInput = function (input) {
            const file = input.files && input.files[0];
            if (!file) {
                updateFileHint(input, '', '');
                delete input.dataset.compressed;
                delete input.dataset.compressing;
                return Promise.resolve();
            }

            const isImage = (file.type && file.type.indexOf('image/') === 0)
                || /\.(jpe?g|png|webp)$/i.test(file.name);

            if (!isImage) {
                updateFileHint(input, '', '');
                input.dataset.compressed = '1';
                delete input.dataset.compressing;
                return Promise.resolve();
            }

            const origKb = Math.round(file.size / 1024);
            if (file.size <= 1.2 * 1024 * 1024) {
                updateFileHint(input, '✓ Foto lista (' + origKb + ' KB)', 'success');
                input.dataset.compressed = '1';
                delete input.dataset.compressing;
                return Promise.resolve();
            }

            const token = Date.now() + '-' + Math.random().toString(36).slice(2);
            input._compressToken = token;
            input.dataset.compressing = '1';
            delete input.dataset.compressed;

            activeCompressions++;
            updateFileHint(input, '⏳ Optimizando foto de ' + (origKb > 1024 ? (origKb / 1024).toFixed(1) + ' MB' : origKb + ' KB') + '...', 'loading');

            return compressImageFile(file).then(function (compressedFile) {
                if (input._compressToken !== token) {
                    return;
                }

                if (compressedFile && compressedFile !== file) {
                    try {
                        const dt = new DataTransfer();
                        dt.items.add(compressedFile);
                        input.files = dt.files;
                        const newKb = Math.round(compressedFile.size / 1024);
                        updateFileHint(input, '✓ Foto optimizada de ' + (origKb > 1024 ? (origKb / 1024).toFixed(1) + ' MB' : origKb + ' KB') + ' a ' + newKb + ' KB', 'success');
                    } catch (e) {
                        updateFileHint(input, '✓ Archivo seleccionado (' + origKb + ' KB)', 'success');
                    }
                } else {
                    updateFileHint(input, '✓ Archivo listo (' + origKb + ' KB)', 'success');
                }

                input.dataset.compressed = '1';
            }).catch(function () {
                updateFileHint(input, '✓ Archivo seleccionado (' + origKb + ' KB)', 'success');
                input.dataset.compressed = '1';
            }).finally(function () {
                if (input._compressToken === token) {
                    delete input.dataset.compressing;
                }
                activeCompressions = Math.max(0, activeCompressions - 1);
                if (activeCompressions === 0 && submitPending) {
                    submitPending = false;
                    triggerFinalSubmit();
                }
            });
        };

        const showSubmitStatus = function (title, message) {
            const status = form.querySelector('[data-ingreso-submit-status]');
            if (status) {
                status.hidden = false;
                const statusTitle = status.querySelector('strong');
                const statusText = status.querySelector('p');
                if (statusTitle) statusTitle.textContent = title;
                if (statusText) statusText.textContent = message;
                status.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        };

        const hideSubmitStatus = function () {
            const status = form.querySelector('[data-ingreso-submit-status]');
            if (status) {
                status.hidden = true;
            }
        };

        const setSubmitButtonState = function (disabled, text) {
            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = disabled;
                button.textContent = text || button.dataset.defaultText || 'Enviar ficha';
            }
        };

        const initialSubmitButton = form.querySelector('button[type="submit"]');
        if (initialSubmitButton) {
            initialSubmitButton.dataset.defaultText = initialSubmitButton.textContent.trim();
        }

        const triggerFinalSubmit = function () {
            if (!form.checkValidity()) {
                hideSubmitStatus();
                setSubmitButtonState(false);
                form.reportValidity();
                return;
            }

            if (typeof form._validateSignature === 'function' && !form._validateSignature()) {
                hideSubmitStatus();
                setSubmitButtonState(false);
                return;
            }

            form.querySelectorAll('[data-uppercase="1"]').forEach(function (el) {
                if (el && typeof el.value === 'string') {
                    const upper = el.value.toLocaleUpperCase('es-PE');
                    if (el.value !== upper) el.value = upper;
                }
            });

            writeDraft();
            form.dataset.submitting = '1';

            showSubmitStatus('Enviando ficha...', 'Primero se guardan tus datos y luego los archivos. No cierres esta ventana hasta ver la confirmacion.');
            setSubmitButtonState(true, form.querySelector('button[type="submit"]')?.dataset.loadingText || 'Enviando ficha...');

            HTMLFormElement.prototype.submit.call(form);
        };

        form.addEventListener('change', function (event) {
            scheduleSave();
            if (event.target && event.target.type === 'file') {
                delete event.target.dataset.compressed;
                delete event.target.dataset.compressing;
                processFileInput(event.target);
            }
        }, true);

        form.addEventListener('submit', function (event) {
            if (form.dataset.submitting === '1') {
                return;
            }

            if (!form.checkValidity()) {
                return;
            }

            if (typeof form._validateSignature === 'function' && !form._validateSignature()) {
                event.preventDefault();
                return;
            }

            if (activeCompressions > 0) {
                event.preventDefault();
                submitPending = true;
                showSubmitStatus('Optimizando fotos...', 'Estamos reduciendo el peso de tus fotos para que suban rapido. Espera unos segundos...');
                setSubmitButtonState(true, 'Optimizando fotos...');
                return;
            }

            const pendingFiles = [];
            form.querySelectorAll('input[type="file"]').forEach(function (inp) {
                if (inp.dataset.compressed === '1') return;
                const f = inp.files && inp.files[0];
                if (f && ((f.type && f.type.indexOf('image/') === 0) || /\.(jpe?g|png|webp)$/i.test(f.name)) && f.size > 1.2 * 1024 * 1024) {
                    pendingFiles.push(inp);
                }
            });

            if (pendingFiles.length > 0) {
                event.preventDefault();
                submitPending = true;
                showSubmitStatus('Optimizando fotos...', 'Estamos reduciendo el peso de tus fotos para que suban rapido. Espera unos segundos...');
                setSubmitButtonState(true, 'Optimizando fotos...');
                pendingFiles.forEach(processFileInput);
                return;
            }

            form.querySelectorAll('[data-uppercase="1"]').forEach(function (el) {
                if (el && typeof el.value === 'string') {
                    const upper = el.value.toLocaleUpperCase('es-PE');
                    if (el.value !== upper) el.value = upper;
                }
            });

            writeDraft();
            form.dataset.submitting = '1';

            showSubmitStatus('Enviando ficha...', 'Primero se guardan tus datos y luego los archivos. No cierres esta ventana hasta ver la confirmacion.');
            setSubmitButtonState(true, form.querySelector('button[type="submit"]')?.dataset.loadingText || 'Enviando ficha...');
        });
    })();
</script>
@endpush
