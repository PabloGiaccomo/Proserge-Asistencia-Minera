<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <title>Actualizar ficha del colaborador</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; color: #172033; background: #f4f6fa; }
        main { max-width: 720px; margin: 2rem auto; padding: 1.5rem; background: #fff; border: 1px solid #dfe4ec; border-radius: .75rem; }
        label { display: block; font-weight: 600; }
        input, select, textarea { box-sizing: border-box; width: 100%; font: inherit; padding: .6rem; border: 1px solid #6b788e; border-radius: .3rem; }
        .field { display: grid; gap: .3rem; margin: 1rem 0; }
        .actions { display: flex; flex-wrap: wrap; gap: .65rem; }
        button { font: inherit; border: 1px solid #144780; padding: .65rem .9rem; border-radius: .3rem; color: #fff; background: #144780; cursor: pointer; }
        button:disabled { opacity: .6; cursor: wait; }
        #status { white-space: pre-line; }
        fieldset { min-width: 0; border: 1px solid #c9d2df; border-radius: .5rem; padding: 1rem; margin: 1rem 0; }
        fieldset legend { font-weight: 700; }
        .check { display: flex; align-items: center; gap: .6rem; }
        .check input { width: auto; }
        [hidden] { display: none !important; }
        @media (max-width: 760px) { main { margin: 0; border-radius: 0; } }
    </style>
</head>
<body>
<main>
    <h1>Actualizar ficha del colaborador</h1>
    <p>Completa los datos solicitados y envíalos para revisión de RR. HH.</p>
    <p id="status" role="status" aria-live="polite">Abriendo solicitud…</p>
    <form id="update-form" hidden>
        <div id="fields"></div>
        <section id="families-section" hidden>
            <h2>Familiares</h2>
            <p>Agrega los familiares que necesites registrar. RR. HH. revisará los documentos para evitar duplicados. La ficha conserva los familiares ya registrados.</p>
            <div id="family-rows"></div>
            <button type="button" id="add-family">Agregar familiar</button>
        </section>
        <div class="actions">
            <button type="button" id="save-draft">Guardar borrador</button>
            <button type="submit">Enviar actualización</button>
        </div>
    </form>
</main>
<script>
    const form = document.getElementById('update-form');
    const fields = document.getElementById('fields');
    const status = document.getElementById('status');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    @php
        $updateRoutes = [
            'exchange' => route('personal.actualizacion.public.exchange'),
            'draft' => route('personal.actualizacion.public.draft'),
            'submit' => route('personal.actualizacion.public.submit'),
        ];
    @endphp
    const routes = {{ \Illuminate\Support\Js::from($updateRoutes) }};
    const submissionUuid = '{{ \Illuminate\Support\Str::uuid() }}';
    const changed = new Map();
    const familyRows = document.getElementById('family-rows');
    let familiesChanged = false;
    let definitions = {};
    let revision;
    let sessionHandle = history.state?.updateSessionHandle;

    async function send(action, body) {
        const response = await fetch(routes[action], {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body),
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
            const details = Object.values(result.errors ?? {}).flat();
            throw new Error(details.join('\n') || result.message || 'No se pudo continuar. Intenta de nuevo.');
        }
        return result;
    }

    function showDraft(draft) {
        revision = draft.revision;
        sessionHandle = draft.session_handle;
        definitions = draft.definitions;
        history.replaceState({ updateSessionHandle: sessionHandle }, '', location.pathname);
        let section = '';
        for (const [key, definition] of Object.entries(definitions)) {
            if (definition.section && definition.section !== section) {
                const heading = document.createElement('h2');
                heading.textContent = definition.section;
                fields.append(heading);
                section = definition.section;
            }
            const wrapper = document.createElement('div');
            wrapper.className = 'field';
            const label = document.createElement('label');
            label.htmlFor = key;
            label.textContent = definition.label + (definition.required ? ' *' : '');
            const options = Object.entries(definition.options ?? {});
            const input = document.createElement(options.length ? 'select' : definition.type === 'textarea' ? 'textarea' : 'input');
            input.id = key;
            input.name = key;
            if (input.tagName === 'SELECT') {
                input.add(new Option('Selecciona…', ''));
                options.forEach(([value, text]) => input.add(new Option(text, value)));
            } else if (input.tagName === 'INPUT') {
                input.type = ['email', 'tel', 'date'].includes(definition.type) ? definition.type : 'text';
            }
            input.dataset.fieldKey = key;
            input.value = draft.fields[key] ?? '';
            input.addEventListener('input', () => {
                changed.set(key, input.value);
                syncConditionalFields();
            });
            wrapper.append(label, input);
            fields.append(wrapper);
        }
        if (draft.families_enabled) {
            document.getElementById('families-section').hidden = false;
            for (const family of draft.familiares ?? []) addFamily(family);
        }
        syncConditionalFields();
        form.hidden = false;
        status.textContent = 'Puedes guardar y continuar antes del vencimiento del enlace.';
    }

    async function saveDraft() {
        if (changed.size === 0 && !familiesChanged) return;
        const result = await send('draft', {
            session_handle: sessionHandle, revision, fields: Object.fromEntries(changed),
            ...(familiesChanged ? { familiares: readFamilies() } : {}),
        });
        revision = result.revision;
        changed.clear();
        familiesChanged = false;
    }

    function syncConditionalFields() {
        const value = (key) => document.getElementById(key)?.value ?? '';
        const conditions = {
            estado_civil_otro: value('estado_civil') === 'Otro',
            nacionalidad_otra: value('nacionalidad') === 'Otra',
            pais_nacimiento_otro: value('pais_nacimiento') === 'Otro',
            lugar_nacimiento_extranjero: value('pais_nacimiento') === 'Otro',
            departamento_nacimiento: value('pais_nacimiento') !== 'Otro',
            provincia_nacimiento: value('pais_nacimiento') !== 'Otro',
            distrito_nacimiento: value('pais_nacimiento') !== 'Otro',
            domicilio_pais_otro: value('domicilio_tipo') === 'Extranjero',
            domicilio_extranjero: value('domicilio_tipo') === 'Extranjero',
            domicilio_departamento: value('domicilio_tipo') !== 'Extranjero',
            domicilio_provincia: value('domicilio_tipo') !== 'Extranjero',
            domicilio_distrito: value('domicilio_tipo') !== 'Extranjero',
            domicilio_direccion: value('domicilio_tipo') !== 'Extranjero',
        };
        for (const [key, visible] of Object.entries(conditions)) {
            const input = document.getElementById(key);
            if (input) input.closest('.field').hidden = !visible;
        }
    }

    const familyDefinitions = [
        ['nombres_apellidos', 'Apellidos y nombres', 'text'],
        ['parentesco', 'Parentesco', 'text'],
        ['fecha_nacimiento', 'Fecha de nacimiento', 'date'],
        ['tipo_documento', 'Tipo de documento', 'select'],
        ['numero_documento', 'Número de documento', 'text'],
        ['telefono', 'Teléfono', 'tel'],
        ['vive_con_trabajador', 'Vive conmigo', 'checkbox'],
        ['estudia', 'Estudia', 'checkbox'],
        ['contacto_emergencia', 'Contacto de emergencia', 'checkbox'],
    ];

    function addFamily(data = {}) {
        const row = document.createElement('fieldset');
        row.dataset.familyKey = data.key ?? newFamilyKey();
        const legend = document.createElement('legend');
        legend.textContent = 'Familiar propuesto';
        row.append(legend);
        for (const [key, title, type] of familyDefinitions) {
            const label = document.createElement('label');
            label.className = type === 'checkbox' ? 'field check' : 'field';
            const input = document.createElement(type === 'select' ? 'select' : 'input');
            input.dataset.familyField = key;
            input.name = 'familiares[' + row.dataset.familyKey + '][' + key + ']';
            if (type === 'select') {
                for (const [value, title] of Object.entries({ DNI: 'DNI', CE: 'Carné de extranjería', PASAPORTE: 'Pasaporte', OTRO: 'Otro' })) {
                    input.add(new Option(title, value));
                }
            } else {
                input.type = type;
            }
            if (type === 'checkbox') {
                input.checked = Boolean(data[key]);
            } else {
                input.value = data[key] ?? (key === 'tipo_documento' ? 'DNI' : '');
            }
            input.addEventListener('input', () => { familiesChanged = true; });
            label.append(title, input);
            row.append(label);
        }
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = 'Quitar de esta propuesta';
        remove.addEventListener('click', () => {
            row.remove();
            familiesChanged = true;
        });
        row.append(remove);
        familyRows.append(row);
    }

    function readFamilies() {
        return [...familyRows.children].map((row) => {
            const values = [...row.querySelectorAll('[data-family-field]')].map((input) => [
                input.dataset.familyField, input.type === 'checkbox' ? input.checked : input.value,
            ]);
            return { key: row.dataset.familyKey, ...Object.fromEntries(values) };
        });
    }

    function newFamilyKey() {
        if (globalThis.crypto?.randomUUID) return crypto.randomUUID();
        if (!globalThis.crypto?.getRandomValues) throw new Error('Abre la solicitud en un navegador actualizado para agregar familiares.');
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    document.getElementById('add-family').addEventListener('click', () => {
        try {
            addFamily();
            familiesChanged = true;
        } catch (error) {
            status.textContent = error.message;
        }
    });

    async function run(operation) {
        const controls = [...form.querySelectorAll('button, input, select, textarea')];
        controls.forEach((control) => { control.disabled = true; });
        try {
            await operation();
        } catch (error) {
            status.textContent = error.message;
        } finally {
            controls.forEach((control) => { control.disabled = false; });
        }
    }

    document.getElementById('save-draft').addEventListener('click', () => run(async () => {
        await saveDraft();
        status.textContent = 'Borrador guardado.';
    }));

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        run(async () => {
            await saveDraft();
            const result = await send('submit', { session_handle: sessionHandle, revision, submission_uuid: submissionUuid });
            form.hidden = true;
            fields.replaceChildren();
            familyRows.replaceChildren();
            status.textContent = result.message;
        });
    });

    const token = location.hash.slice(1);
    if (location.hash) {
        sessionHandle = undefined;
        history.replaceState(null, '', location.pathname);
    }
    window.addEventListener('hashchange', () => {
        if (location.hash) location.reload();
    });
    send('exchange', token ? { token } : { session_handle: sessionHandle }).then((result) => {
        if (result.submitted) {
            status.textContent = result.message;
            return;
        }
        showDraft(result);
    }).catch((error) => { status.textContent = error.message; });
</script>
</body>
</html>
