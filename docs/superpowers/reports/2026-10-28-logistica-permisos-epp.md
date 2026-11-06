# Logistica: permisos para registrar entregas y devoluciones

## Diagnostico

El rol LOGISTICA tenia todas las acciones generales de Logistica marcadas y
todos los permisos EPP desmarcados. Se verifico en la copia local (solo lectura)
y en Seguridad > Roles del servidor. Los botones del flujo Entregas y cambios
dependen de `epps.registrar`, no de `logistica.registrar`.

## Cambio aplicado en el servidor

El 2026-10-28, mediante la sesion ADMIN abierta en el navegador y con la
confirmacion del usuario de conceder solo lo necesario, se guardaron en el rol
LOGISTICA (4 usuarios mostrados en el listado) estos permisos:

- `epps.ver`: busqueda de trabajadores y consultas del formulario.
- `epps.registrar`: preparar entregas y devoluciones del flujo habitual.
- `epps.confirmar_entrega`: completar la entrega firmada.
- `epps.confirmar_devolucion`: completar la devolucion firmada.

La respuesta mostro `Permisos guardados correctamente.`. La comparacion de
todos los checks antes y despues confirmo que solo cambiaron esos cuatro.
No se modificaron otros roles ni el alcance por mina; no se concedieron
administracion EPP, inventario ni excepciones de firma. La copia local del rol
no fue modificada. No se registraron entregas o devoluciones reales de prueba.

## Cambios locales en codigo

- `resources/views/seguridad/roles/_form.blade.php`: enlace desde Logistica a
  EPPs, explicacion de los checks necesarios, nombre operativo de Registrar y
  busqueda por Logistica/Registrar entrega/Registrar devolucion sin depender
  de tildes. Navegar no marca permisos.
- `tests/Feature/RolePermissionCheckboxTest.php`: regresion de guardado,
  visibilidad de ambos botones, rutas protegidas y revocacion sin cerrar sesion.
- `tests/Browser/logistics-role-permissions.cjs`: busqueda y navegacion en
  escritorio/movil, checks independientes y formulario sin grants accidentales.
- `docs/EPP_AUTORIZACION.md`: configuracion del flujo y despliegue de la vista.

La mejora del editor permanece local. Para subirla por WinSCP basta transferir
la vista Blade indicada y ejecutar `php artisan view:clear`. No requiere build
ni migracion. El cambio del rol ya se guardo mediante la aplicacion del servidor.

## Validacion

- MySQL aislado `proserge_app_test`: RolePermissionCheckboxTest,
  EppAuthorizationTest y LogisticaEppIntegrationTest: 74 tests, 3169 assertions.
- Browser: logistics-role-permissions.cjs y role-permissions.cjs, ambos correctos
  en 1440 y 390 px. Se reviso la captura movil del editor.
- `php -l` de la vista y del test modificado: correcto.
- `php artisan view:cache`: correcto.
- `git diff --check` de los archivos modificados en esta tarea: correcto.

Pendiente comprobar visualmente los botones tras recargar en una cuenta
afectada del servidor: la sesion disponible era ADMIN. Las pruebas locales
confirman que el grant muestra ambos botones y su revocacion oculta los botones
y bloquea las rutas. La firma requiere datos reales y no se ejecuto en produccion.
