# Habilitar prueba historica local Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Execute task-by-task; never confirm an Excel import in this plan.

**Goal:** Dejar disponible la carga historica en el entorno local existente, sin perder datos ni alterar produccion.

**Architecture:** Reutilizar las cinco migraciones ya implementadas. Dos tablas nuevas separan lote/auditoria y asistencia antigua; las tres tablas actuales de evaluaciones reciben origen y trazabilidad. La habilitacion temporal sigue usando configuracion y permiso administrativo existentes.

**Tech Stack:** Laravel 12, MySQL/MariaDB local, mysqldump, Blade, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-05-evaluaciones-asistencia-historica-design.md`

## Alcance autorizado

Solicitud del usuario: crear el plan de tablas y comenzar su ejecucion para poder probar localmente.
Destino verificado: host `127.0.0.1`, base `proserge_app_import_20260831_151029`.
No ejecutar sobre FTP/cPanel ni confirmar la importacion real. Preservar cambios concurrentes de Personal/habilitacion minera.

## Tablas

- `evaluaciones_import_lotes`: archivo/manifiesto privado, hash, resumen, actor, estado y candado UNIQUE de confirmacion unica.
- `asistencia_historica`: trabajador por DNI resuelto, fecha de presencia efectiva y origen; sin relacion operativa a mina/parada.
- `evaluacion_desempeno`: origen, token de envio, lote/fuente y clave UNIQUE; permitir datos de contexto historicos desconocidos.
- `evaluacion_supervisor`: lote/fuente, JSON original, version de calculo historica y clave UNIQUE; conservar puntajes existentes.
- `evaluacion_residente`: lote/fuente, JSON original, version de calculo y clave UNIQUE; componentes antiguos desconocidos admiten NULL.

## Tarea 1: respaldo y preflight

- [x] Verificar host/base, herramientas y esquema real.
- [x] Generar dump completo privado con esquema/datos/rutinas/triggers/eventos; comprobar salida, tamano y SHA256.
- [x] Guardar conteos y huellas de filas actuales de Personal/usuarios/evaluaciones antes de migrar.
- [x] Detenerse si el respaldo falla o si el destino no es local.

## Tarea 2: esquema incremental

- [x] Ejecutar solo estas migraciones en orden, usando `--path` individual:
  - `2026_10_05_000100_add_origen_and_submission_key_to_evaluacion_desempeno.php`
  - `2026_10_05_000200_create_evaluaciones_historical_import_tables.php`
  - `2026_10_05_000300_add_historical_source_to_supervisor_and_resident_evaluations.php`
  - `2026_10_05_000400_allow_unknown_historical_evaluation_components.php`
  - `2026_10_05_000500_guard_one_confirmed_historical_import.php`
- [x] Comprobar columnas, nullabilidad e indices; comparar filas originales y conteos. No usar migrate:fresh/rollback.

## Tarea 3: activacion y ensayo

- [x] Activar `EVALUACIONES_HISTORICAL_IMPORT_ENABLED=true` solo en `.env` local y limpiar cache de configuracion.
- [x] Comprobar permiso `evaluaciones.administrar` sin ampliar roles.
- [x] Ejecutar lectura/conciliacion de ambos Excel sin llamar a confirm ni escribir registros historicos.
- [x] Ejecutar suite focalizada solo contra `proserge_app_test`.
- [x] Abrir acceso y entregar URL/proceso. El navegador redirige a login: queda pendiente verificar el boton con la sesion real del usuario. Las pruebas web del boton/formulario pasaron en base aislada.

## Resultado esperado

Boton `Cargar datos historicos` disponible para el usuario autorizado, endpoint protegido,
tablas preparadas, datos anteriores conservados y cero importaciones confirmadas.
El usuario revisa los archivos y confirma manualmente cuando todos los bloqueantes esten corregidos.

## Evidencia de ejecucion local

- Respaldo privado: `storage/app/private/deployment-backups/historical-local-20261005-191939/`.
- Dump: 56.529.857 bytes, SHA256 `2fee4a263f66848879bfc59975b0b3f07eb5c19c8e87248f884c1f7dedffc573`.
- Cinco migraciones aplicadas; indices UNIQUE comprobados. Datos originales de Personal
  (950), usuarios (15), evaluacion diaria (0), supervisor (1) y residente (0)
  conservan conteos y huellas de todas sus columnas anteriores.
- Ensayo real de solo lectura: 10.074 filas; 5.751 propuestas, 1.351 sin cambios,
  2.972 bloqueadas y 1.588 advertencias (estas pueden coincidir con otras categorias).
- Bloqueos: 1.949 DNI inexistentes/ambiguos; 365 DNI/ID interno no reconocidos;
  354 referencias de asistencia incompatibles; 223 conflictos entre libros;
  62 fechas no interpretables y 19 filas con notas fuera de 1..4.
- Ensayo: 10,68 segundos, 110 MB pico bajo limite 256 MB local. No demuestra
  rendimiento/limites de cPanel; produccion no se modifico.
- Regresion: 88 pruebas, 510 aserciones, todas aprobadas en `proserge_app_test`.
- Cero lotes confirmados y cero asistencias historicas guardadas en la base local.
- URL local: `http://127.0.0.1:8000/evaluaciones/historico/importar`.
- `.env` y respaldo estan excluidos de Git. No se alteraron permisos/roles ni formulas.

## Continuacion del 2026-10-06

Diagnostico auxiliar, no una ampliacion del importador: lectura de `DATA G` y
`CESE` en los snapshots de abril/diciembre por DNI exacto. No se analizaron
otras hojas ni se asociaron personas por parecido de nombre.

- 2.578 DNI distintos encontrados en los snapshots.
- 1.693 filas bloqueadas tienen DNI respaldado por DATA: revisar identidad y
  existencia/unicidad en Personal mediante su flujo habitual.
- 621 filas bloqueadas no tienen identidad respaldada en las hojas examinadas.
- 658 filas requieren revisar fechas, notas o referencias entre libros.
- Son cantidades de filas, no de trabajadores distintos. La evidencia DATA
  no valida automaticamente una persona ni elimina los bloqueantes.
- Reporte privado con hoja/fila y evidencia:
  `storage/app/private/historical-diagnostics/2026-10-06/revision-bloqueos.csv`.
- CSV verificado: 2.972 filas, estructura consistente y textos protegidos
  contra interpretacion de formulas.
- Cero escrituras de negocio y cero lotes confirmados. No se cambiaron logica,
  permisos, migraciones ni Excel originales durante este diagnostico.
- El navegador disponible sigue en login: la comprobacion de la pantalla con
  la cuenta real del usuario requiere que inicie sesion.
