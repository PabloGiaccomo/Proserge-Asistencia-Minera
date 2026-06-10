# Deuda tecnica y riesgos detectados

Auditoria: 2026-06-10.

## Servicios demasiado grandes

- `PersonalFichaService.php` (~99 KB): mezcla links, ficha, documentos,
  notificaciones, PDF/export, regularizacion y helpers.
- `PersonalContratoService.php` (~74 KB): mezcla ciclo contractual,
  renovacion, reingreso, cierre, snapshots y activacion laboral.
- `PersonalMinaHabilitacionService.php` (~66 KB): mezcla catalogo de examenes,
  asignaciones, intentos, recalculo, convalidacion y validaciones.
- `ImportPersonalService.php` (~65 KB): importacion de personal y reglas de
  ciclo laboral.
- `PersonalMinaExcelImportService.php` (~45 KB): lectura, preview y confirmacion
  de master Excel.
- `RQMinaService.php` y `RQMinaPageController.php` superan 40 KB.

Riesgo: cambios pequenos pueden romper reglas transversales. Refactor solo con
tests por modulo.

## Blade demasiado cargados

- `resources/views/personal/index.blade.php` (~201 KB).
- `resources/views/personal/fichas/temporales.blade.php` (~97 KB).
- `resources/views/personal/habilitacion-minera/index.blade.php` (~70 KB).
- `resources/views/ficha-public/show.blade.php` (~59 KB).
- `resources/views/personal/edit.blade.php` (~43 KB).
- `resources/views/rq-mina/index.blade.php` (~42 KB).

Riesgo: mucha logica JavaScript/Blade embebida, dificil de probar y mantener.
Priorizar extraccion a componentes, partials y view models.

## Duplicacion y responsabilidades mezcladas

- Validaciones de estados laborales aparecen en varios servicios.
- Estados de documentos, contratos y personal se muestran en vistas con logica
  local.
- Autocompletados y buscadores de personal se repiten en varias pantallas.
- Importadores tienen patrones similares pero no un contrato comun de preview,
  confirmacion y resumen.
- Confirmaciones `confirm()` nativas siguen existiendo en algunas vistas.

## Seguridad y permisos

- Muchas acciones sensibles usan permisos generales como `personal.actualizar`.
  A futuro conviene separar:
  - ver remuneracion;
  - aprobar documentos;
  - descargar documentos;
  - modificar contratos;
  - cerrar/no renovar;
  - importar master de habilitacion;
  - administrar examenes.
- Scope por mina no esta garantizado en todas las rutas web.
- Descargas masivas deben auditarse si se vuelven frecuentes.

## Migraciones y base de datos

- Conviven `database/setup/*.sql` y migraciones Laravel; aclarar estrategia para
  instalaciones nuevas.
- Migracion de asistencia con indice `uq_asistencia_grupo` tuvo conflicto local.
- Muchas migraciones recientes son aditivas y dependen de tablas previas.
- Revisar indices para grandes volumenes:
  - `personal.numero_documento`, `dni`, `estado`;
  - `personal_contratos.personal_id`, `estado`, `fecha_fin`;
  - `personal_fichas.personal_id`, `estado`, `submitted_at`;
  - `personal_ficha_archivos.personal_ficha_id`, `tipo`;
  - `personal_mina.personal_id`, `mina_id`, `activo`;
  - `personal_mina_examenes.personal_mina_id`, `estado`, `fecha_vencimiento`;
  - `notification_recipients.usuario_id`, `read_at`, `archived_at`.

## Rendimiento

- Vista de habilitacion minera e importacion master ya muestran riesgo por
  volumen.
- Importacion Excel grande puede superar tiempos web; evaluar jobs en cola y
  pantalla de progreso.
- Listados grandes deben paginarse desde base de datos, no cargar todo al DOM.
- Exportaciones y ZIP masivos deben tener limites, jobs o procesamiento por
  lotes.

## Almacenamiento documental

- Archivos privados crecen con fichas, documentos, contratos y examenes.
- Falta politica formal de archivado/retencion.
- Falta checksum o verificacion de integridad documental.
- Falta auditoria especifica de descargas/restauraciones masivas.

## UX/UI

- Algunas pantallas son muy tecnicas para usuarios operativos.
- Formularios largos y tablas grandes requieren simplificacion.
- Acciones administrativas deben agruparse mejor.
- Botones de importacion/sincronizacion/recalculo deben mostrar resumen claro.
- Usar patrones tipo Excel donde ayuden: filtros por columna, columnas fijas,
  paginacion, exportacion filtrada.

## Tests faltantes o a reforzar

- Browser/Playwright para ficha publica movil y vistas grandes.
- Pruebas de performance basicas para importaciones grandes.
- Tests de permisos finos por acciones documentales y contractuales.
- Tests de scope por mina en rutas web.
- Tests de archivado/restauracion cuando se implemente.
- Tests de compatibilidad de `database/setup` contra migraciones.

## Riesgos operativos

- Modificar contrato o ficha sin snapshot puede afectar historial.
- Automatizar cese, renovacion o habilitacion sin decision humana puede romper
  operacion real.
- Sobrescribir datos desde Excel sin preview puede destruir informacion vigente.
- Exponer descargas documentales sin permiso fino implica riesgo de privacidad.

## Partes tecnicamente correctas pero operativamente debiles

- Acciones llamadas "sincronizar", "recalcular" o "importar" pueden ser validas
  tecnicamente, pero deben explicar resultado en lenguaje de negocio.
- Formularios de configuracion en habilitacion minera son necesarios, pero deben
  mantenerse ocultos por defecto para no intimidar al usuario operativo.
- Descargar o exportar datos sin filtros puede ser correcto para un admin, pero
  riesgoso para usuarios de area.
- Mantener datos historicos en tablas activas facilita consulta inicial, pero a
  largo plazo exige indices, filtros y estrategia de archivado.
- Usar `personal.actualizar` para muchas acciones acelera desarrollo, pero hace
  dificil controlar permisos por responsabilidad real.
