<script>
document.addEventListener('DOMContentLoaded', function () {
    const byKey = (key) => document.querySelector('[data-ficha-key="' + key + '"]');
    const fieldWrap = (key) => document.querySelector('[data-ficha-field="' + key + '"]');
    const verifyInput = (key) => document.querySelector('[data-ficha-verify="' + key + '"]');
    let ubigeo = {};

    const loadUbigeo = async function () {
        try {
            const response = await fetch(@json(asset('data/ubigeo-peru.json')), { cache: 'force-cache' });
            const payload = await response.json();
            ubigeo = payload.data || {};
        } catch (error) {
            ubigeo = {};
        }
    };

    const setVisible = function (key, visible) {
        const node = fieldWrap(key);
        if (node) node.style.display = visible ? '' : 'none';
    };

    const setEnabled = function (key, enabled) {
        const input = byKey(key);
        if (input) input.disabled = !enabled;
        const verify = verifyInput(key);
        if (verify) verify.disabled = !enabled;
    };

    const fillSelect = function (select, values) {
        if (!select) return;
        const selected = select.dataset.currentValue || select.value;
        const matched = values.find(value => String(value).toUpperCase() === String(selected).toUpperCase()) || selected;
        select.innerHTML = '<option value="">Seleccionar</option>' + values.map(function (value) {
            const safe = String(value).replace(/"/g, '&quot;');
            return '<option value="' + safe + '">' + value + '</option>';
        }).join('');
        if (matched) select.value = matched;
        select.dataset.currentValue = select.value;
    };

    const bindUbigeo = function (prefix) {
        const dep = byKey(prefix + '_departamento') || byKey('departamento_' + prefix);
        const prov = byKey(prefix + '_provincia') || byKey('provincia_' + prefix);
        const dist = byKey(prefix + '_distrito') || byKey('distrito_' + prefix);
        if (!dep || !prov || !dist) return;

        fillSelect(dep, Object.keys(ubigeo));
        const updateProvince = function () {
            fillSelect(prov, Object.keys(ubigeo[dep.value] || {}));
            updateDistrict();
        };
        const updateDistrict = function () {
            fillSelect(dist, ubigeo[dep.value]?.[prov.value] || []);
        };

        dep.addEventListener('change', updateProvince);
        prov.addEventListener('change', updateDistrict);
        updateProvince();
    };

    const applyConditionals = function () {
        const estadoCivilOtro = byKey('estado_civil')?.value === 'Otro';
        setVisible('estado_civil_otro', estadoCivilOtro);
        setEnabled('estado_civil_otro', estadoCivilOtro);

        const nacionalidadOtra = byKey('nacionalidad')?.value === 'Otra';
        setVisible('nacionalidad_otra', nacionalidadOtra);
        setEnabled('nacionalidad_otra', nacionalidadOtra);

        const nacimientoPeru = (byKey('pais_nacimiento')?.value || 'Peru') === 'Peru';
        ['departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento'].forEach(key => {
            setVisible(key, nacimientoPeru);
            setEnabled(key, nacimientoPeru);
        });
        ['pais_nacimiento_otro', 'lugar_nacimiento_extranjero'].forEach(key => {
            setVisible(key, !nacimientoPeru);
            setEnabled(key, !nacimientoPeru);
        });

        const domicilioPeru = (byKey('domicilio_tipo')?.value || 'Peru') === 'Peru';
        ['domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion'].forEach(key => {
            setVisible(key, domicilioPeru);
            setEnabled(key, domicilioPeru);
        });
        ['domicilio_pais_otro', 'domicilio_extranjero'].forEach(key => {
            setVisible(key, !domicilioPeru);
            setEnabled(key, !domicilioPeru);
        });
        setVisible('domicilio_referencia', true);
        setEnabled('domicilio_referencia', true);

        const banco = byKey('banco')?.value || '';
        const bancoConCuenta = banco === 'BCP' || banco === 'Interbank';
        setVisible('numero_cuenta', bancoConCuenta);
        setEnabled('numero_cuenta', bancoConCuenta);
        ['banco_otro', 'cci'].forEach(key => {
            setVisible(key, banco === 'Otro');
            setEnabled(key, banco === 'Otro');
        });

        const spp = byKey('sistema_pensionario')?.value === 'Sistema Privado de Pensiones';
        ['tipo_comision', 'tipo_afp', 'cuspp'].forEach(key => {
            setVisible(key, spp);
            setEnabled(key, spp);
        });

        const otroEmpleador = byKey('quinta_empleador_principal')?.value === 'Otra empresa';
        ['quinta_otra_empresa', 'quinta_otra_empresa_ruc'].forEach(key => {
            setVisible(key, otroEmpleador);
            setEnabled(key, otroEmpleador);
        });

        const contrato = byKey('contrato')?.value || '';
        const contratoConFin = contrato === 'FIJO' || contrato === 'INTER' || contrato === 'REG';
        setVisible('fecha_fin_contrato', contratoConFin);
        setEnabled('fecha_fin_contrato', contratoConFin);
        setVisible('fecha_cese', contrato === 'INDET');
        setEnabled('fecha_cese', contrato === 'INDET');
    };

    ['estado_civil', 'nacionalidad', 'pais_nacimiento', 'domicilio_tipo', 'banco', 'sistema_pensionario', 'quinta_empleador_principal', 'contrato'].forEach(function (key) {
        byKey(key)?.addEventListener('change', applyConditionals);
    });

    applyConditionals();
    loadUbigeo().then(function () {
        bindUbigeo('nacimiento');
        bindUbigeo('domicilio');
        applyConditionals();
    });
});
</script>
