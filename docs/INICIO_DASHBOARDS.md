# Inicio y dashboards por modulo

## Uso y permisos

En `/inicio`, cada tarjeta es un enlace a una vista de consulta en
`/inicio/dashboards/<modulo>`. El boton **Volver a Inicio** aparece cuando el
usuario conserva `inicio.ver`. Todas las rutas exigen `web.auth` y el permiso
directo de dashboard del modulo; no conceden acceso a listados ni escrituras.
Los permisos se recargan en cada peticion y se combinan solo los roles activos.
Retirar Dashboard impide entrar tambien mediante la URL directa.

Se usa `<modulo>.dashboards` para Personal, Mi Asistencia, Man Power, RQ Mina,
RQ Proserge, Bienestar, Evaluaciones, Asistencias, Faltas y Catalogos. En
Logistica se usa `logistica.ver_logistica_dashboard`, el mismo permiso
especifico de su pestana. El catalogo incorpora el check Dashboard a Mi
Asistencia y Catalogos; no se modifican las matrices persistidas ni se ejecuta
una migracion de roles. Los falsos explicitos siguen denegando acceso conforme
a `PermissionMatrix`.

## Contenido

- Personal: estado laboral, pendientes de regularizacion y principales cargos.
  Es una fotografia del estado actual; no usa filtro temporal.
- Mi Asistencia: grupos, personal programado, presentes y pendientes. Conserva
  responsable propio o la consulta ampliada autorizada. Si falta vincular la
  cuenta a Personal, se explica el siguiente paso. Consultar este resumen no
  ejecuta la generacion automatica de ausencias de la pantalla operativa.
- RQ Mina: requerimientos por estado y hasta 20 paradas que se solapan con el
  periodo. Cada parada abre el mismo Dashboard KPI existente, con filtros y
  retorno al resumen. Esa vista independiente no contiene recalculo, descarga
  ni enlaces operativos.
- RQ Proserge: requerimientos, objetivos, titulares cubiertos y brecha. El
  solapamiento del periodo se aplica en SQL y se cargan detalles/asignaciones
  en conjunto. `RQProsergeCoverageService` conserva sus reglas de titulares y
  respaldo. Las minas explicitas siguen limitando incluso a roles con
  privilegios de alcance, conforme a `RQProsergePolicy`.
- Bienestar: solo bloqueos activos, visibles para planificacion y solapados
  con el periodo; se cuenta personal unico y motivos.
- Catalogos: cantidades y estados actuales de minas, talleres y oficinas.
  Los catalogos individuales conservan sus permisos propios.
- Asistencias y Faltas: reutilizan sus consultas, indicadores y graficos. La
  tabla operativa y enlaces de detalle exigen el permiso Ver del modulo.
- Evaluaciones: reutiliza el dashboard, filtros y actualizacion parcial. El
  permiso Dashboard sigue separado de consultar cada tipo de evaluacion; sin
  tipos habilitados se muestra una explicacion. Se conservan propietario,
  origen, mina y visibilidad de puntajes del servicio existente.
- Man Power: reutiliza Resumen, Turnos, Cargos y Cobertura SAIT. Permite elegir
  fecha, parada, plan y actividad dentro del alcance. El servidor fuerza la
  vista Dashboard; no renderiza formularios de escritura ni las vistas de
  Seleccion/Grupos aunque se soliciten por query string.
- Logistica: reutiliza exclusivamente su partial de Dashboard con el actor
  autenticado y sus filtros de alcance. No ejecuta los recordatorios del
  controlador operativo ni permite seleccionar otra pestana por parametros.

Los resumenes con periodo obligatorio admiten hasta 12 meses; Logistica
conserva sus filtros temporales opcionales existentes. Personal y Bienestar
mantienen su alcance global existente; las consultas operativas reutilizan
los alcances propios de cada modulo. Un permiso de dashboard no concede
privilegios de alcance ni acciones de gestion.

## Archivos y comprobacion

Las tarjetas se definen en
`app/Modules/Dashboard/Support/HomeDashboardCatalog.php`. Las rutas estan en
`routes/web.php`, los estilos en `resources/css/modules/home-dashboards.css` y
el retorno compartido en `resources/views/layouts/app.blade.php`.

Pruebas focalizadas (MySQL aislado `proserge_app_test`):

```powershell
php artisan test --compact tests/Feature/HomeDashboardsTest.php tests/Feature/HomeSummaryDashboardServiceTest.php tests/Feature/ExistingHomeDashboardsTest.php
php artisan view:cache
npm run build
git diff --check
```

`tests/Browser/home-dashboards.cjs` requiere Playwright y Chrome. Genera HTML
mediante las rutas reales bajo transacciones de la base de pruebas y comprueba
tarjetas, retorno, filtros, actualizacion de Evaluaciones y tamanos 1440/390.
No realiza peticiones a datos operativos. Los renders y capturas quedan en
`storage/app/testing/home-dashboards` y no deben versionarse.

## Contrato de Dashboard API

`/api/v1/dashboard/*` exige `inicio.ver`. Las rutas de RQ Mina, RQ Proserge, Man Power, Asistencias, Faltas y Evaluaciones requieren también el permiso directo `dashboards` del módulo. Una sección restringida responde `403 DASHBOARD_FORBIDDEN` al consultar su ruta. `principal` conserva sus claves y envía `null` en las secciones restringidas. `resumen` y `alertas` envían `null` en cada indicador cuyo permiso de origen falta, incluso cuando el indicador combina módulos.

Evaluaciones conserva el acceso por tipo, propietario y mina del dashboard web. Los indicadores de un tipo restringido son `null`. Los promedios y el ranking de desempeño usan sólo notas entre 0 y 20. Los clientes que consuman el dashboard deben aceptar `null` para datos restringidos y distinguirlo del cero de una consulta autorizada sin resultados.

## Despliegue

No requiere migracion ni cambios de dependencias. Desplegar los controladores,
servicios, catalogo de permisos, rutas y Blade modificados, junto con el
`public/build/manifest.json` y todos los assets de ese build. Limpiar cache de
rutas/vistas segun el procedimiento habitual (`php artisan optimize:clear`)
y verificar Inicio con un rol limitado y otro administrativo. Para habilitar
un dashboard a un rol, usar sus checks en Roles y permisos; no ampliar Ver o
acciones operativas para hacer accesible un resumen.

`public/build` esta ignorado por Git: comprobar los hashes nuevos antes de
desplegar mediante Git, o transferir la carpeta compilada completa por el
procedimiento WinSCP documentado en `docs/OPERACION_DESPLIEGUE.md`.
