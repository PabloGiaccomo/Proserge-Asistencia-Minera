@extends('layouts.app')

@section('title', 'Evaluación de Supervisores')

@section('header_title')
    Evaluación de Supervisores
@endsection

@section('header_breadcrumb')
    <span class="header-breadcrumb-sep">/</span>
    <span>Inicio</span>
    <span class="header-breadcrumb-sep">/</span>
    <span>Evaluación Supervisor</span>
@endsection

@section('content')
<div class="mb-4">
    <!-- API Configuration -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                </svg>
                Configuración API
            </h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-3 gap-4">
                <div class="form-group mb-0">
                    <label class="form-label">Token API</label>
                    <input type="text" id="token" class="form-control" placeholder="Bearer token...">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Base API</label>
                    <input type="text" id="base" class="form-control" value="/api/v1">
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">ID Evaluación (para cargar)</label>
                    <div class="flex gap-2">
                        <input type="text" id="evalId" class="form-control" placeholder="UUID">
                        <button class="btn btn-secondary" id="loadBtn">Cargar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Context Form -->
<div class="mb-4">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                Contexto de la Evaluación
            </h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-3 gap-4">
                <div class="form-group">
                    <label class="form-label">Evaluador (personal_id)</label>
                    <input type="text" id="evaluador_id" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Evaluado (personal_id)</label>
                    <input type="text" id="evaluado_id" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha</label>
                    <input type="date" id="fecha" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Destino tipo</label>
                    <select id="destino_tipo" class="form-control">
                        <option value="MINA">MINA</option>
                        <option value="TALLER">TALLER</option>
                        <option value="OFICINA">OFICINA</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Destino ID</label>
                    <input type="text" id="destino_id" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Mina ID (opcional)</label>
                    <input type="text" id="mina_id" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Grupo trabajo ID (opcional)</label>
                    <input type="text" id="grupo_trabajo_id" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Asistencia encabezado ID (opcional)</label>
                    <input type="text" id="asistencia_encabezado_id" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Estado</label>
                    <select id="estado" class="form-control">
                        <option value="REGISTRADA">REGISTRADA</option>
                        <option value="REVISADA">REVISADA</option>
                        <option value="CERRADA">CERRADA</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Evaluation Questions -->
@foreach($items as $section => $questions)
<div class="mb-4">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Sección {{ $section }}</h3>
        </div>
        <div class="card-body">
            @foreach($questions as $key => $text)
            <div class="eval-question">
                <div class="eval-question-text">
                    <strong>{{ $key }}</strong>
                    <span class="text-muted" style="display: block; margin-top: 4px;">{{ $text }}</span>
                </div>
                <div class="eval-options">
                    <label class="eval-option">
                        <input type="radio" name="{{ $key }}" value="1">
                        <span class="eval-option-label">1</span>
                    </label>
                    <label class="eval-option">
                        <input type="radio" name="{{ $key }}" value="2">
                        <span class="eval-option-label">2</span>
                    </label>
                    <label class="eval-option">
                        <input type="radio" name="{{ $key }}" value="3">
                        <span class="eval-option-label">3</span>
                    </label>
                    <label class="eval-option">
                        <input type="radio" name="{{ $key }}" value="4">
                        <span class="eval-option-label">4</span>
                    </label>
                    <label class="eval-option">
                        <input type="radio" name="{{ $key }}" value="5">
                        <span class="eval-option-label">5</span>
                    </label>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endforeach

<!-- Observations -->
<div class="mb-4">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                </svg>
                Observaciones
            </h3>
        </div>
        <div class="card-body">
            <div class="grid grid-cols-2 gap-4">
                <div class="form-group">
                    <label class="form-label">Comentarios finales</label>
                    <textarea id="comentarios_finales" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Aspectos positivos</label>
                    <textarea id="aspectos_positivos" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Capacitaciones recomendadas</label>
                    <textarea id="capacitaciones_recomendadas" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Firma</label>
                    <input type="text" id="firma" class="form-control" placeholder="Nombre o firma digital">
                </div>
            </div>
            
            <div class="flex items-center justify-between mt-4 p-4" style="background: var(--bg-hover); border-radius: var(--radius-md);">
                <div>
                    <div class="text-muted" style="font-size: 12px;">Puntaje final (backend oficial)</div>
                    <div class="kpi-value" id="score" style="font-size: 32px;">0.00%</div>
                </div>
                <div class="flex gap-3">
                    <button class="btn btn-success" id="createBtn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Crear evaluación
                    </button>
                    <button class="btn btn-secondary" id="updateBtn">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        Actualizar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent evaluations list -->
<div class="mb-4">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="8" y1="6" x2="21" y2="6"></line>
                    <line x1="8" y1="12" x2="21" y2="12"></line>
                    <line x1="8" y1="18" x2="21" y2="18"></line>
                    <line x1="3" y1="6" x2="3.01" y2="6"></line>
                    <line x1="3" y1="12" x2="3.01" y2="12"></line>
                    <line x1="3" y1="18" x2="3.01" y2="18"></line>
                </svg>
                Listado Reciente
            </h3>
            <button class="btn btn-sm btn-secondary" id="refreshBtn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"></polyline>
                    <polyline points="1 20 1 14 7 14"></polyline>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                </svg>
                Refrescar
            </button>
        </div>
        <div class="card-body-no-padding">
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Evaluado</th>
                            <th>Destino</th>
                            <th>Puntaje</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="rows">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const WEIGHTS = @json($weights);
const KEYS = Object.keys(WEIGHTS);

const scoreEl = document.getElementById('score');
const tokenEl = document.getElementById('token');
const baseEl = document.getElementById('base');

function val(id) { return document.getElementById(id).value.trim(); }

function objResponses() {
    const r = {};
    KEYS.forEach(k => { 
        const el = document.querySelector(`input[name="${k}"]:checked`);
        if (el) r[k] = Number(el.value); 
    });
    return r;
}

function liveScore() {
    let weighted = 0;
    KEYS.forEach(k => { 
        const el = document.querySelector(`input[name="${k}"]:checked`);
        const value = el ? Number(el.value) : 0;
        weighted += WEIGHTS[k] * value;
    });
    const percentage = ((weighted / 5) * 100).toFixed(2);
    scoreEl.innerText = percentage + '%';
}

KEYS.forEach(k => {
    document.querySelectorAll(`input[name="${k}"]`).forEach(el => {
        el.addEventListener('change', liveScore);
    });
});

async function api(path, method = 'GET', body = null) {
    const headers = { 'Accept': 'application/json' };
    if (tokenEl.value.trim()) headers['Authorization'] = 'Bearer ' + tokenEl.value.trim();
    if (body) { headers['Content-Type'] = 'application/json'; }
    const r = await fetch(baseEl.value.trim() + path, { method, headers, body: body ? JSON.stringify(body) : null });
    return await r.json();
}

function payload() {
    return {
        evaluador_id: val('evaluador_id'),
        evaluado_id: val('evaluado_id'),
        fecha: val('fecha'),
        destino_tipo: val('destino_tipo'),
        destino_id: val('destino_id'),
        mina_id: val('mina_id') || null,
        grupo_trabajo_id: val('grupo_trabajo_id') || null,
        asistencia_encabezado_id: val('asistencia_encabezado_id') || null,
        respuestas: objResponses(),
        comentarios_finales: val('comentarios_finales') || null,
        aspectos_positivos: val('aspectos_positivos') || null,
        capacitaciones_recomendadas: val('capacitaciones_recomendadas') || null,
        firma: val('firma') || null,
        estado: val('estado') || null,
    };
}

function loadForm(d) {
    document.getElementById('evaluador_id').value = d.evaluador_id || '';
    document.getElementById('evaluado_id').value = d.evaluado_id || '';
    document.getElementById('fecha').value = d.fecha || '';
    document.getElementById('destino_tipo').value = d.destino_tipo || 'MINA';
    document.getElementById('destino_id').value = d.destino_id || '';
    document.getElementById('mina_id').value = d.mina_id || '';
    document.getElementById('grupo_trabajo_id').value = d.grupo_trabajo_id || '';
    document.getElementById('asistencia_encabezado_id').value = d.asistencia_encabezado_id || '';
    document.getElementById('comentarios_finales').value = d.comentarios_finales || '';
    document.getElementById('aspectos_positivos').value = d.aspectos_positivos || '';
    document.getElementById('capacitaciones_recomendadas').value = d.capacitaciones_recomendadas || '';
    document.getElementById('firma').value = d.firma || '';
    document.getElementById('estado').value = d.estado || 'REGISTRADA';
    
    KEYS.forEach(k => {
        document.querySelectorAll(`input[name="${k}"]`).forEach(el => {
            el.checked = (d.respuestas && d.respuestas[k]) ? (d.respuestas[k] == el.value) : false;
        });
    });
    
    scoreEl.innerText = ((d.puntaje_final || 0).toFixed ? d.puntaje_final.toFixed(2) : Number(d.puntaje_final || 0).toFixed(2)) + '%';
}

document.getElementById('createBtn').onclick = async () => {
    const res = await api('/evaluaciones/supervisor', 'POST', payload());
    alert(res.message || res.code);
    if (res.ok && res.data) { 
        document.getElementById('evalId').value = res.data.id; 
        scoreEl.innerText = Number(res.data.puntaje_final).toFixed(2) + '%'; 
        refresh(); 
    }
};

document.getElementById('updateBtn').onclick = async () => {
    const id = val('evalId');
    if (!id) { alert('Ingresa ID a actualizar'); return; }
    const res = await api('/evaluaciones/supervisor/' + id, 'PUT', payload());
    alert(res.message || res.code);
    if (res.ok && res.data) { 
        scoreEl.innerText = Number(res.data.puntaje_final).toFixed(2) + '%'; 
        refresh(); 
    }
};

document.getElementById('loadBtn').onclick = async () => {
    const id = val('evalId');
    if (!id) { alert('Ingresa ID'); return; }
    const res = await api('/evaluaciones/supervisor/' + id);
    if (!res.ok) { alert(res.message || res.code); return; }
    loadForm(res.data);
};

document.getElementById('refreshBtn').onclick = async () => refresh();

async function refresh() {
    const res = await api('/evaluaciones/supervisor');
    const rows = document.getElementById('rows');
    rows.innerHTML = '';
    if (!res.ok || !Array.isArray(res.data)) return;
    res.data.slice(0, 20).forEach(item => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><code style="font-size: 11px;">${item.id.substring(0, 8)}...</code></td>
            <td>${item.fecha || '-'}</td>
            <td>${item.evaluado_id || '-'}</td>
            <td><span class="badge badge-info">${item.destino_tipo || '-'}</span></td>
            <td><strong>${item.puntaje_final || 0}%</strong></td>
            <td><span class="badge badge-${item.estado === 'CERRADA' ? 'success' : item.estado === 'REVISADA' ? 'warning' : 'secondary'}">${item.estado || '-'}</span></td>
        `;
        tr.style.cursor = 'pointer';
        tr.onclick = () => { document.getElementById('evalId').value = item.id; loadForm(item); };
        rows.appendChild(tr);
    });
}

liveScore();
</script>
@endpush