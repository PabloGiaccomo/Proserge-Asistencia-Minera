@php
    $contractFormatCanDownload = (bool) ($contractFormatCanDownload ?? false);
    $contractFormatSelectedWorkerSelector = $contractFormatSelectedWorkerSelector ?? '[data-contract-format-worker-check]:checked';
@endphp

@if($contractFormatCanDownload)
    @once
        
    @endonce

    <div id="contractFormatModal" class="modal" style="display:none;" onclick="if (event.target === this) closeContractFormatModal()">
        <div class="modal-backdrop" onclick="closeContractFormatModal()"></div>
        <div id="contractFormatContent" class="modal-content personal-contract-modal">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Descargar formato de contrato</h2>
                    <p class="modal-subtitle" id="contractFormatSubtitle">Primero escoge el formato que quieres descargar.</p>
                </div>
                <button type="button" class="modal-close" onclick="closeContractFormatModal()" aria-label="Cerrar">X</button>
            </div>
            <div class="modal-body">
                <section id="contractFormatStepTemplates" class="contract-step is-active">
                    <div>
                        <label class="contract-format-field-label">Escoge un formato</label>
                        <p class="modal-subtitle" style="margin:4px 0 0;">Selecciona una plantilla para continuar.</p>
                    </div>
                    <div id="contractFormatTemplateList" class="contract-format-template-grid">
                        <div class="contract-preview-empty">Cargando formatos...</div>
                    </div>
                </section>
                <section id="contractFormatStepWorkers" class="contract-step">
                    <div class="contract-step-header">
                        <div>
                            <label class="contract-format-field-label">Formato seleccionado</label>
                            <div id="contractSelectedTemplateLabel" class="contract-selected-template">-</div>
                        </div>
                        <button type="button" class="btn btn-outline btn-sm" onclick="showContractFormatStep('templates')">Cambiar formato</button>
                    </div>
                    <div class="contract-worker-picker">
                        <div>
                            <label class="contract-format-field-label" for="contractWorkerSearch">Seleccionar personal</label>
                            <input id="contractWorkerSearch" class="contract-format-input" type="search" autocomplete="off" placeholder="Nombre, DNI o puesto">
                            <div id="contractWorkerSearchResults" class="contract-search-results"></div>
                        </div>
                        <div>
                            <label class="contract-format-field-label">Personal seleccionado</label>
                            <div id="contractSelectedWorkers" class="contract-selected-workers"></div>
                        </div>
                    </div>
                    <div class="contract-preview-section">
                        <label class="contract-format-field-label">Vista previa del Excel</label>
                        <div id="contractPreviewWrap" class="contract-preview-wrap">
                            <div class="contract-preview-empty">Selecciona al menos un trabajador.</div>
                        </div>
                    </div>
                </section>
            </div>
            <form id="contractFormatDownloadForm" method="POST" action="{{ route('personal.contrato-formatos.download') }}">
                @csrf
                <input type="hidden" name="template_id" id="contractDownloadTemplateId">
                <div id="contractDownloadWorkerInputs"></div>
            </form>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeContractFormatModal()">Cancelar</button>
                <button type="button" class="btn btn-outline" id="contractFormatBackButton" onclick="showContractFormatStep('templates')" style="display:none;">Volver</button>
                <button type="submit" form="contractFormatDownloadForm" class="btn btn-primary" id="contractFormatDownloadButton" style="display:none;" disabled>Descargar Excel</button>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
        (function () {
            const canDownloadContractFormats = @json($contractFormatCanDownload);
            const contractFormatEndpoints = {
                templates: @json(route('personal.contrato-formatos.templates')),
                workers: @json(route('personal.contrato-formatos.personal')),
                preview: @json(route('personal.contrato-formatos.preview')),
            };
            const selectedWorkerSelector = @json($contractFormatSelectedWorkerSelector);
            const csrfToken = @json(csrf_token());
            let templates = [];
            let templateId = '';
            let currentStep = 'templates';
            let selectedWorkers = new Map();
            let searchTimer = null;

            function escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, function (char) {
                    return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
                });
            }

            function showModal(modalId) {
                if (typeof window.openModal === 'function') {
                    window.openModal(modalId);
                    return;
                }

                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                }
            }

            function hideModal(modalId) {
                if (typeof window.closeModal === 'function') {
                    window.closeModal(modalId);
                    return;
                }

                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }

            function workerSummary(worker) {
                return {
                    id: String(worker?.id || '').trim(),
                    nombre: String(worker?.nombre || worker?.nombre_completo || 'Trabajador').trim(),
                    documento: String(worker?.documento || worker?.numero_documento || worker?.dni || '').trim(),
                    puesto: String(worker?.puesto || '').trim(),
                    correo: String(worker?.correo || worker?.email || '').trim(),
                };
            }

            function parseWorkerPayload(input) {
                const row = input.closest('tr');
                let worker = {};

                try {
                    worker = JSON.parse(input.dataset.workerPayload || row?.dataset.contractWorker || '{}');
                } catch (error) {
                    worker = {};
                }

                worker.id = worker.id || input.value;
                worker.nombre = worker.nombre || input.dataset.workerName || row?.dataset.workerName || 'Trabajador';
                worker.documento = worker.documento || row?.dataset.workerDocument || '';
                worker.puesto = worker.puesto || row?.dataset.workerPosition || '';

                return workerSummary(worker);
            }

            function selectedWorkersFromPage() {
                return Array.from(document.querySelectorAll(selectedWorkerSelector))
                    .filter(function (input) {
                        return !input.disabled && String(input.value || '').trim() !== '';
                    })
                    .map(parseWorkerPayload)
                    .filter(function (worker) {
                        return worker.id;
                    });
            }

            window.openContractFormatModal = async function (worker) {
                if (!canDownloadContractFormats) return;

                selectedWorkers = new Map();
                templateId = '';
                const initialWorker = workerSummary(worker || {});
                if (initialWorker.id) {
                    selectedWorkers.set(initialWorker.id, initialWorker);
                } else {
                    selectedWorkersFromPage().forEach(function (selectedWorker) {
                        selectedWorkers.set(selectedWorker.id, selectedWorker);
                    });
                }

                const search = document.getElementById('contractWorkerSearch');
                if (search) {
                    search.value = '';
                }

                renderSearchResults([]);
                renderSelectedWorkers();
                showContractFormatStep('templates');
                updateDownloadForm();
                showModal('contractFormatModal');
                await loadTemplates();
                renderTemplates();
            };

            window.closeContractFormatModal = function () {
                hideModal('contractFormatModal');
            };

            window.showContractFormatStep = function (step) {
                currentStep = step === 'workers' ? 'workers' : 'templates';
                const templatesStep = document.getElementById('contractFormatStepTemplates');
                const workersStep = document.getElementById('contractFormatStepWorkers');
                const subtitle = document.getElementById('contractFormatSubtitle');
                const backButton = document.getElementById('contractFormatBackButton');
                const downloadButton = document.getElementById('contractFormatDownloadButton');
                const content = document.getElementById('contractFormatContent');

                templatesStep?.classList.toggle('is-active', currentStep === 'templates');
                workersStep?.classList.toggle('is-active', currentStep === 'workers');
                content?.classList.toggle('is-preview', currentStep === 'workers');

                if (subtitle) {
                    subtitle.textContent = currentStep === 'templates'
                        ? 'Primero escoge el formato que quieres descargar.'
                        : 'Selecciona el personal y revisa la vista previa antes de descargar.';
                }
                if (backButton) {
                    backButton.style.display = currentStep === 'workers' ? 'inline-flex' : 'none';
                }
                if (downloadButton) {
                    downloadButton.style.display = currentStep === 'workers' ? 'inline-flex' : 'none';
                }

                updateDownloadForm();
            };

            async function loadTemplates() {
                if (templates.length > 0) {
                    return;
                }

                const list = document.getElementById('contractFormatTemplateList');
                if (list) {
                    list.innerHTML = '<div class="contract-preview-empty">Cargando formatos...</div>';
                }

                try {
                    const response = await fetch(contractFormatEndpoints.templates, {
                        headers: {'Accept': 'application/json'},
                    });
                    const data = await response.json();
                    templates = Array.isArray(data.templates) ? data.templates : [];
                } catch (error) {
                    templates = [];
                }
            }

            function renderTemplates() {
                const list = document.getElementById('contractFormatTemplateList');
                if (!list) return;

                if (templates.length === 0) {
                    list.innerHTML = '<div class="contract-preview-empty">No se encontraron formatos disponibles.</div>';
                    return;
                }

                list.innerHTML = templates.map(function (template) {
                    const selectedClass = template.id === templateId ? ' is-selected' : '';
                    return `
                        <button type="button" class="contract-format-template${selectedClass}" data-contract-template-id="${escapeHtml(template.id)}">
                            <span>
                                <strong>${escapeHtml(template.title || template.label || 'Formato')}</strong>
                                <span>${escapeHtml(template.date || '')}</span>
                            </span>
                            <span class="contract-format-template-pill">${escapeHtml((template.columns || []).length)} col.</span>
                        </button>
                    `;
                }).join('');

                list.querySelectorAll('[data-contract-template-id]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        selectTemplate(button.dataset.contractTemplateId || '');
                    });
                });
            }

            function selectTemplate(selectedTemplateId) {
                templateId = selectedTemplateId;
                const hidden = document.getElementById('contractDownloadTemplateId');
                const label = document.getElementById('contractSelectedTemplateLabel');
                const template = templates.find(function (item) {
                    return item.id === selectedTemplateId;
                });

                if (hidden) {
                    hidden.value = selectedTemplateId;
                }
                if (label) {
                    label.innerHTML = template
                        ? '<strong>' + escapeHtml(template.title || template.label || 'Formato') + '</strong><span>' + escapeHtml((template.date || '') + ' · ' + ((template.columns || []).length) + ' columnas') + '</span>'
                        : '<strong>Formato seleccionado</strong>';
                }

                renderTemplates();
                showContractFormatStep('workers');
                refreshPreview();
            }

            function renderSelectedWorkers() {
                const target = document.getElementById('contractSelectedWorkers');
                if (!target) return;

                const workers = Array.from(selectedWorkers.values());
                if (workers.length === 0) {
                    target.innerHTML = '<div class="contract-preview-empty" style="padding:8px 0;">Sin personal seleccionado.</div>';
                    updateDownloadForm();
                    return;
                }

                target.innerHTML = workers.map(function (worker) {
                    return `
                        <div class="contract-worker-chip">
                            <div>
                                <strong>${escapeHtml(worker.nombre)}</strong>
                                <span>${escapeHtml(worker.documento || '-')} · ${escapeHtml(worker.puesto || '-')}</span>
                            </div>
                            <button type="button" aria-label="Quitar trabajador" data-remove-contract-worker="${escapeHtml(worker.id)}">X</button>
                        </div>
                    `;
                }).join('');

                target.querySelectorAll('[data-remove-contract-worker]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        selectedWorkers.delete(button.dataset.removeContractWorker || '');
                        renderSelectedWorkers();
                        refreshPreview();
                    });
                });

                updateDownloadForm();
            }

            function addWorker(worker) {
                const summary = workerSummary(worker);
                if (!summary.id) return;

                selectedWorkers.set(summary.id, summary);
                renderSelectedWorkers();
                refreshPreview();
            }

            function renderSearchResults(workers) {
                const target = document.getElementById('contractWorkerSearchResults');
                if (!target) return;

                if (!Array.isArray(workers) || workers.length === 0) {
                    target.style.display = 'none';
                    target.innerHTML = '';
                    return;
                }

                target.style.display = 'block';
                target.innerHTML = workers.map(function (worker) {
                    return `
                        <button type="button" class="contract-search-result" data-worker-payload="${escapeHtml(JSON.stringify(worker))}">
                            <strong>${escapeHtml(worker.nombre || 'Trabajador')}</strong>
                            <span>${escapeHtml(worker.documento || '-')} · ${escapeHtml(worker.puesto || '-')}</span>
                        </button>
                    `;
                }).join('');

                target.querySelectorAll('.contract-search-result').forEach(function (button) {
                    button.addEventListener('click', function () {
                        try {
                            addWorker(JSON.parse(button.dataset.workerPayload || '{}'));
                        } catch (error) {
                            // noop
                        }

                        const search = document.getElementById('contractWorkerSearch');
                        if (search) {
                            search.value = '';
                            search.focus();
                        }
                        renderSearchResults([]);
                    });
                });
            }

            async function searchWorkers(query) {
                const value = String(query || '').trim();
                if (value.length < 2) {
                    renderSearchResults([]);
                    return;
                }

                try {
                    const url = contractFormatEndpoints.workers + '?q=' + encodeURIComponent(value);
                    const response = await fetch(url, {headers: {'Accept': 'application/json'}});
                    const data = await response.json();
                    renderSearchResults(data.workers || []);
                } catch (error) {
                    renderSearchResults([]);
                }
            }

            async function refreshPreview() {
                const wrap = document.getElementById('contractPreviewWrap');
                const workerIds = Array.from(selectedWorkers.keys());
                if (!wrap) return;

                if (!templateId || workerIds.length === 0) {
                    wrap.innerHTML = '<div class="contract-preview-empty">Selecciona un formato y al menos un trabajador.</div>';
                    updateDownloadForm();
                    return;
                }

                wrap.innerHTML = '<div class="contract-preview-empty">Actualizando vista previa...</div>';

                try {
                    const response = await fetch(contractFormatEndpoints.preview, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            template_id: templateId,
                            personal_ids: workerIds,
                        }),
                    });
                    const data = await response.json();
                    if (!response.ok) {
                        throw new Error(data.message || 'No se pudo generar la vista previa.');
                    }

                    (data.workers || []).forEach(function (worker) {
                        const summary = workerSummary(worker);
                        if (summary.id) {
                            selectedWorkers.set(summary.id, summary);
                        }
                    });

                    renderSelectedWorkers();
                    renderPreviewTable(data.template?.columns || [], data.rows || []);
                } catch (error) {
                    wrap.innerHTML = '<div class="contract-preview-empty">' + escapeHtml(error.message || 'No se pudo generar la vista previa.') + '</div>';
                }

                updateDownloadForm();
            }

            function renderPreviewTable(columns, rows) {
                const wrap = document.getElementById('contractPreviewWrap');
                if (!wrap) return;

                if (!columns.length) {
                    wrap.innerHTML = '<div class="contract-preview-empty">El formato no tiene columnas reconocibles.</div>';
                    return;
                }

                const bodyRows = rows.length > 0 ? rows : [columns.map(function () { return ''; })];
                wrap.innerHTML = `
                    <table class="contract-preview-table">
                        <thead>
                            <tr>${columns.map(function (column) { return '<th>' + escapeHtml(column) + '</th>'; }).join('')}</tr>
                        </thead>
                        <tbody>
                            ${bodyRows.map(function (row) {
                                return '<tr>' + columns.map(function (_column, index) {
                                    return '<td title="' + escapeHtml(row[index] || '') + '">' + escapeHtml(row[index] || '') + '</td>';
                                }).join('') + '</tr>';
                            }).join('')}
                        </tbody>
                    </table>
                `;
            }

            function updateDownloadForm() {
                const templateInput = document.getElementById('contractDownloadTemplateId');
                const idsTarget = document.getElementById('contractDownloadWorkerInputs');
                const button = document.getElementById('contractFormatDownloadButton');
                const workerIds = Array.from(selectedWorkers.keys());

                if (templateInput) {
                    templateInput.value = templateId;
                }
                if (idsTarget) {
                    idsTarget.innerHTML = workerIds.map(function (id) {
                        return '<input type="hidden" name="personal_ids[]" value="' + escapeHtml(id) + '">';
                    }).join('');
                }
                if (button) {
                    button.disabled = currentStep !== 'workers' || !templateId || workerIds.length === 0;
                    button.style.display = currentStep === 'workers' ? 'inline-flex' : 'none';
                }
            }

            document.addEventListener('DOMContentLoaded', function () {
                const search = document.getElementById('contractWorkerSearch');
                const downloadForm = document.getElementById('contractFormatDownloadForm');

                search?.addEventListener('input', function () {
                    window.clearTimeout(searchTimer);
                    searchTimer = window.setTimeout(function () {
                        searchWorkers(search.value);
                    }, 220);
                });

                downloadForm?.addEventListener('submit', function (event) {
                    updateDownloadForm();
                    if (!templateId || selectedWorkers.size === 0) {
                        event.preventDefault();
                    }
                });
            });
        })();
        </script>
    @endpush
@endif
