# Inicio: dashboards funcionales por permisos

## Resultado

Las 11 tarjetas de Inicio abren dashboards independientes. Se reutilizan los
paneles existentes y se agregan los resumenes faltantes con retorno a Inicio.
RQ Mina incluye acceso al KPI existente por parada. Las vistas de consulta
mantienen los permisos efectivos, el alcance propio de cada modulo y las
restricciones de acceso a detalles y acciones operativas.

Contrato y despliegue: [INICIO_DASHBOARDS.md](../../INICIO_DASHBOARDS.md).

## Cambios

- `app/Modules/Dashboard`: catalogo comun, dos controladores de vistas y servicio
  de resumenes. `PersonalPageController::home` consume el catalogo.
- `PermissionCatalog` y `routes/web.php`: checks Dashboard para Mi Asistencia
  y Catalogos, y rutas autenticadas con permiso independiente por modulo.
- Servicios de Asistencia, Faltas, RQ Mina y Man Power: consultas de dashboard
  que conservan el alcance sin ampliar policies operativas. Mi Asistencia
  reutiliza la lectura sin ejecutar marcaciones automaticas.
- RQ Proserge: periodo aplicado en SQL y cobertura cargada en conjunto.
- Blade de Inicio, layout, dashboards y vistas reutilizadas: tarjetas con
  enlaces, filtros en rutas independientes y retorno visible. Se agregan
  estilos en `resources/css/modules/home-dashboards.css` y se compila Vite.
- Documentacion canonica: `ARCHITECTURE_MAP.md`, `MAPA_ARCHIVOS.md` y la guia
  de Inicio. Se preservan los cambios previos del repositorio.

## Verificacion

- **290 pruebas, 4320 assertions, todas aprobadas** en una ejecucion conjunta
  de 17 archivos Feature: HomeDashboards, HomeSummaryDashboardService,
  ExistingHomeDashboards, WebPermissionMiddleware, RolePermissionCheckbox,
  MiAsistenciaWeb, AsistenciaFaltasWeb, LogisticaDashboardService,
  RQProsergeApi, ManPowerApi, RQMinaApi, AsistenciaApi, FaltasApi,
  EvaluacionesWeb, RbacOperationalScope, DashboardApi y MiAsistenciaDatabaseError.
  Se uso exclusivamente MySQL `proserge_app_test` y transacciones.
- Navegador: `tests/Browser/home-dashboards.cjs` aprobado. Comprueba las 11
  tarjetas, regreso a Inicio, filtros, actualizacion parcial de Evaluaciones,
  Man Power con contexto poblado y KPI por parada en 1440 y 390 pixeles.
  Los HTML provienen de peticiones por las rutas reales en la base de pruebas;
  la fase de navegador reproduce esos HTML y usa los assets compilados reales.
  No hubo desbordamiento horizontal de pagina ni errores de JavaScript.
- `php -l`: 24 archivos PHP comprobados, sin errores.
- `php artisan view:cache`, `npm run build` y `git diff --check`: aprobados.
- Laravel Pint: aprobados todos los archivos PHP nuevos y las pruebas nuevas.
  Un control adicional del directorio Dashboard detecto formato previo en
  `Controllers/DashboardController.php` y `Services/DashboardService.php`.
  Ambos archivos permanecen sin cambios; no se hizo un reformateo ajeno.
- Revision independiente completada. Se corrigieron el limite de fecha del
  selector independiente de Man Power y la carga completa del historial de
  RQ Proserge. La revision final no encontro nuevas ampliaciones de permiso,
  alcance ni escrituras durante la consulta.

## Limites y despliegue

No se ejecuto toda la suite del repositorio; la bateria cubre los modulos,
servicios y controles de acceso afectados. No se desplego al servidor remoto.
No requiere migraciones ni dependencias nuevas. El build esta compilado en
`public/build`; sus hashes nuevos quedan ignorados por Git segun la regla
existente. Para produccion transferir la carpeta completa, junto con los
archivos de codigo/vistas, y limpiar caches segun la guia de despliegue.

Evaluaciones conserva permisos por tipo: Dashboard por si solo permite abrir
la vista, pero no habilita la consulta de tipos que el rol no tiene concedidos.
