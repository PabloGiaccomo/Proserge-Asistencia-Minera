@extends('layouts.public')

@section('title', 'Ingreso de colaborador - Proserge')

@section('content')
@php
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
                <div class="ingreso-guide-grid">
                    <div class="ingreso-guide-step">
                        <span class="ingreso-guide-number">1</span>
                        <div>
                            <strong>Completa tus datos.</strong>
                            <p>Escribe nombres, apellidos, DNI, telefono, correo, domicilio, banco, cargo y tipo de contrato. Usa mayusculas cuando corresponda.</p>
                        </div>
                    </div>
                    <div class="ingreso-guide-step">
                        <span class="ingreso-guide-number">2</span>
                        <div>
                            <strong>Registra tu experiencia.</strong>
                            <p>Completa el reporte de experiencia indicando cuantas veces realizaste cada trabajo.</p>
                            <a href="https://docs.google.com/forms/d/e/1FAIpQLSejSTKeugA4BE7zxbP3Za1bJNaiDMqPWfh47JO0vWrPA_hs0Q/formResponse" target="_blank" rel="noopener">Abrir reporte</a>
                        </div>
                    </div>
                    <div class="ingreso-guide-step">
                        <span class="ingreso-guide-number">3</span>
                        <div>
                            <strong>Adjunta documentos si los tienes.</strong>
                            <p>Los documentos no bloquean el envio, pero subirlos ahora evita retrasos en RRHH.</p>
                        </div>
                    </div>
                    <div class="ingreso-guide-step">
                        <span class="ingreso-guide-number">4</span>
                        <div>
                            <strong>Firma, sube tu huella y envia.</strong>
                            <p>Firma dentro del recuadro y adjunta una foto clara de tu huella marcada en papel.</p>
                        </div>
                    </div>
                </div>

                <details class="public-guide-details">
                    <summary>Ver documentos y enlaces de apoyo</summary>
                    <ul class="ingreso-doc-list">
                        <li>CV documentado con certificados de trabajo y estudios.</li>
                        <li>DNI vigente.</li>
                        <li>Certiadulto o Certijoven.</li>
                        <li>Recibo de luz o agua.</li>
                        <li>Renta de quinta o certificado de retenciones.</li>
                        <li>Declaracion jurada de Vida Ley.</li>
                        <li>Foto tipo carnet con fondo blanco.</li>
                        <li>Documentos familiares solo si corresponde.</li>
                    </ul>
                </details>
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
        form.addEventListener('change', scheduleSave, true);
        form.addEventListener('submit', function (event) {
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }

            writeDraft();
            form.dataset.submitting = '1';

            const status = form.querySelector('[data-ingreso-submit-status]');
            if (status) {
                status.hidden = false;
                status.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            const button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
                button.textContent = button.dataset.loadingText || 'Enviando ficha...';
            }
        }, true);
    })();
</script>
@endpush
