# Checklist antes de implementar

Usar esta lista antes de cada nueva tarea.

## Comprension

- [ ] Lei `AGENTS.md`.
- [ ] Lei `docs/CODEX_CONTEXT.md`.
- [ ] Lei la regla o mapa del modulo relacionado.
- [ ] Identifique si aplica a personal antiguo, nuevo o ambos.
- [ ] Identifique area responsable: RR.HH., operaciones, logistica, SSOMA,
      contabilidad, gerencia, administracion u otra.
- [ ] Identifique si hay datos historicos o inamovibles afectados.

## Alcance

- [ ] No estoy implementando funciones fuera del pedido.
- [ ] Liste archivos que probablemente voy a tocar.
- [ ] Revise servicios existentes antes de crear nuevos.
- [ ] Revise tests existentes del modulo.
- [ ] Detecte preguntas ambiguas y las hice antes de asumir.

## Reglas criticas

- [ ] Nadie queda `ACTIVO` sin contrato firmado vigente.
- [ ] Contratos historicos no se editan ni eliminan fisicamente.
- [ ] Renovaciones crean contrato nuevo.
- [ ] Habilitacion minera no cambia estado laboral ni contratos.
- [ ] Documentos respetan estados documentales.
- [ ] Personal antiguo no se duplica por DNI/documento.
- [ ] Importaciones no sobrescriben sin preview/confirmacion.

## Permisos y seguridad

- [ ] Rutas nuevas tienen `web.auth` y `web.permission`.
- [ ] Acciones criticas revisan permiso en backend.
- [ ] Botones se ocultan o bloquean sin permiso.
- [ ] Descargas/exportaciones respetan permiso.
- [ ] Datos sensibles no se exponen en vistas innecesarias.

## UX/UI

- [ ] La pantalla usa lenguaje operativo.
- [ ] No agregue formularios administrativos abiertos desde el inicio.
- [ ] El usuario entiende siguiente accion.
- [ ] Los errores son claros.
- [ ] La vista soporta volumen: paginacion, filtros, busqueda.
- [ ] Si se parece a Excel, mantiene columnas/filtros entendibles.

## Historial y trazabilidad

- [ ] Se conserva estado anterior/nuevo cuando aplica.
- [ ] Se registra usuario, fecha y observacion cuando aplica.
- [ ] Se conserva snapshot si el dato puede cambiar en el futuro.
- [ ] No se pierde referencia a archivos.
- [ ] Si hay archivado, se mantiene recuperacion y auditoria.

## Rendimiento y almacenamiento

- [ ] Consulte indices y filtros para tablas grandes.
- [ ] Evite cargar todo el personal si se puede paginar.
- [ ] Para Excel/ZIP/export masivo, considere jobs o lotes.
- [ ] Evalua impacto en `storage/app/private`.
- [ ] No mueve ni borra archivos sin politica clara.

## Pruebas

- [ ] Agregue o actualice tests cuando cambio reglas.
- [ ] Ejecute tests focalizados.
- [ ] Ejecute `php -l` en PHP tocado.
- [ ] Ejecute `php artisan view:cache` si toque Blade.
- [ ] Ejecute `npm run build` si toque assets compilables.
- [ ] Ejecute `git diff --check`.

## Cierre

- [ ] Resumi que cambie.
- [ ] Liste archivos modificados.
- [ ] Liste pruebas ejecutadas.
- [ ] Indique riesgos y revision manual.
- [ ] Indique siguiente etapa recomendada.
