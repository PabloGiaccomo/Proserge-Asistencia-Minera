# Entrega de citas EMO y control de asistencia

Alcance: especificacion del 2026-10-07 y su plan de nueve tareas. Se conserva
el trabajo heredado del repositorio; no se realizaron commits, push ni despliegue.

Tasks 0-8 implementadas y revisadas. Task 9 tiene completadas la integracion,
documentacion, verificacion, revision final y preparacion del despliegue.
Su ensayo sobre copia realista permanece pendiente por indicacion del usuario.

## Funcionalidad

- Clinicas administrables y categoria operativa explicita de los examenes.
- Citas EMO compartidas por minas con identidad exacta, revision de concurrencia,
  anulacion, reprogramacion, regularizacion e historial.
- Cuatro resultados medicos estructurados; detalle obligatorio para restriccion
  u observacion. Sin cambios de estado laboral ni contratos.
- Carga masiva v2 con clinica, conflictos en preview y confirmacion transaccional.
  El Master conserva aprobaciones vigentes ante instrucciones de programacion.
- Control de asistencia con filtros SQL, paginacion y Excel completo hasta
  10 000 filas agrupadas, permisos independientes y auditoria minima.
- Formularios operativos, acciones segun estado y presentacion adaptable.

La ruta antigua de completar intentos rechaza EMO. Para cambiar fecha/clinica,
primero se anula la cita y despues se reprograma con su revision vigente.

## Validacion

La evidencia detallada de pruebas y revisiones se conserva en el registro local
`.superpowers/sdd/2026-10-07-habilitacion-minera-clinicas-emo-control-asistencia/`.

- Integracion backend inicial: 237 pruebas, 1656 aserciones, sin fallos.
- Web/vistas tras las correcciones finales: 55 pruebas, 285 aserciones, sin fallos.
- Suite completa: 1049 casos, 6282 aserciones, 45 fallos y un error (217.75 s).
  De ellos, 44 incidencias coinciden por clase/nombre con la referencia de Task 6.
  Los otros dos eran fixtures de vencimiento con fecha UTC; tras alinearlos a
  Lima, la repeticion de los dos archivos mineros arrojo 154 casos, 966 aserciones,
  dos fallos previos y ningun error. No se afirma suite general verde.
- En la suite completa, las 242 pruebas asociadas a esquema, resultados,
  compatibilidad, citas, web/vistas, consulta, exportacion, visibilidad
  transaccional, identidad y preview pasan con 1727 aserciones.
- Chrome/Playwright pasa a 1440 y 390 px; se inspeccionaron capturas del control
  y modal. Se corrigio el ancho minimo heredado que recortaba acciones en movil
  y se comprueban los limites de cada boton, ademas del scroll de la pagina.
- Sintaxis de 55 PHP, compilacion Blade, build Vite final (8.43 s) y
  `git diff --check`: correctos. Pint de nuevos tests pasa; los archivos
  heredados conservan desviaciones de formato documentadas, sin formato masivo.

La comprobacion general inicial mediante Artisan se interrumpio al imprimir
errores extensos; el conteo anterior procede de la repeticion completa con
PHPUnit `--no-output --log-junit`. Las pruebas MySQL se ejecutaron contra
`proserge_app_test`; una colision entre procesos de diagnostico se descarto y
las comprobaciones finales se ejecutaron en serie.

La revision independiente final aprobo especificacion y calidad dentro del
alcance revisado. Cerro los dos hallazgos P2: cierre EMO por la ruta antigua y
accion Reprogramar ofrecida para citas activas. Registro local:
`final-fix-review.md`. Se mantiene como mejora menor la cobertura exhaustiva de
`DELETE_RULE` de las claves foraneas; el esquema fue inspeccionado y sus pruebas
de variantes pasan.

La prueba de navegador usa fixtures ficticios renderizados y Chrome. Las
transiciones HTTP se prueban separadamente; no equivale a un ensayo autenticado
completo en staging.

## Preparacion del despliegue

El usuario solicito preparar el despliegue y dejar pendiente el ensayo. Antes
de produccion faltan la copia aislada con volumen realista, mediciones de
migracion/bloqueos/espacio y consulta/exportacion, y el smoke en staging.

El runbook canonico esta en `docs/OPERACION_DESPLIEGUE.md`, apartado
"Corte de citas EMO y control de asistencia". Exige backups de MySQL y archivos
privados, mantenimiento, migracion aditiva, codigo y assets de la misma version,
configuracion autorizada y recuperacion sin eliminar historial.

Los archivos en `public/build` fueron generados con Vite; no se editaron a mano.
No se ejecutaron migraciones contra produccion ni se importaron datos reales.

## Actualizacion local autorizada

El usuario reporto un error de tabla `clinicas_emo` inexistente y autorizo
respaldar su base importada local y aplicar exclusivamente la migracion EMO.
Se genero un respaldo SQL completo privado de 56 537 009 bytes con SHA-256
registrado en `storage/app/private/mining-emo-local-backup/latest.json`.
La migracion `2026_10_07_000100_create_emo_appointments_and_clinics` quedo
aplicada en batch 34 (aproximadamente cuatro segundos).

Se comprobaron las cinco tablas nuevas, las columnas de categoria/enlace/revision
y las consultas de clinicas y control SQL. Los conteos se conservaron:
950 trabajadores, 15 usuarios, 50 examenes, 23 269 intentos y seis registros
globales. No se ejecutaron otras migraciones ni se modifico produccion.
La comprobacion autenticada de la pantalla en el navegador del usuario no
se realizo: el navegador disponible para el agente no tenia esa sesion abierta.
Esta instalacion local no sustituye el ensayo de volumen y restauracion pendiente.
