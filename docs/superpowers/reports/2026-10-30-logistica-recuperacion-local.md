# Logistica: recuperacion local y estado del servidor

Fecha: 2026-10-30.

## Local

Apache de XAMPP servia `C:/xampp/htdocs` en el puerto 8000, tambien usado por
`php artisan serve --host=127.0.0.1 --port=8000`. El registro de Apache mostro
un 404 de `/logistica` y la redireccion de `/` a `/dashboard/`.
Se detuvo Apache desde XAMPP. MySQL sigue iniciado y el puerto 8000 queda
exclusivamente en Laravel. No se modifico la configuracion de Apache.

El primer acceso devolvio el aviso de sesion caducada. Tras renovar el login
y el ingreso manual del usuario, se verificaron Inicio, Logistica y la
identificacion de items. Escribir `cas` muestra CASCO sin pulsar Buscar.

Se encontro ademas una consulta de notificaciones de 2 038 191 bytes contra
un `max_allowed_packet` local de 1 048 576 bytes. El SQL repetia la matriz de
permisos por cada modulo de tipos. `NotificationInboxService` ahora comparte
la condicion contextual entre los grupos con y sin alcance de mina, dejando
el filtro de tipo y de mina individual por modulo. El SQL medido queda en
453 532 bytes. Conteo y cabecera respondieron en 443 y 454 ms respectivamente.
No se cambiaron permisos, usuarios ni el limite de MySQL.

## Verificacion

- Logistica/EPP: 40 tests, 347 assertions.
- Buscador: pruebas de navegador desktop/mobile aprobadas.
- Regresion de paquete: antes fallaba con error MySQL 1153 en ADMIN,
  SUPERADMIN y un rol personalizado con todos los permisos; despues pasan
  los tres casos, incluidos contexto desconocido y limite de 1 MiB.
- Notificaciones: 84 tests, 340 assertions.
- Se aplico solamente en `proserge_app_test` la migracion pendiente
  `2026_10_21_000100_align_notification_destination_permissions`; antes de
  prepararla fallaban 17 casos por el catalogo antiguo de destinos.
- PHP lint, Pint y `git diff --check` aprobados. Blade compilado.
- Revision independiente sin hallazgos en el cambio de la consulta.
- `composer test`: no finalizo porque Composer detuvo el proceso al alcanzar
  su limite de 300 segundos. No se afirma que la suite completa este aprobada.
  Registro: `outputs/local-recovery-2026-10-30/full-suite.log`.

Evidencia: `outputs/local-recovery-2026-10-30/logistica-local.png`.

## Produccion recuperada

Tras el aviso del usuario se reutilizo su unica sesion WinSCP conectada.
No se abrieron sesiones adicionales. El respaldo original de la vista,
198 079 bytes y SHA256
`C731BB52EBA0CBE1370422F5055FC73BC298FEF0A584836904D37E9952FFB288`,
se subio con nombre temporal y se verifico por descarga antes de activarlo.
La copia truncada quedo conservada como
`index.blade.php.truncated-20261030`. La sesion web autenticada confirmo
que desaparecio el 500 y abrio el dashboard de Logistica.

Se completo la entrega del buscador y de la ayuda de permisos:

| Archivo | Bytes | SHA256 verificado por descarga |
|---|---:|---|
| LogisticaDashboardService.php | 107252 | B6603740BBED11281F5563C1270CB9CA920308A3EC0ACC113BB4DE48274F3CF4 |
| index.blade.php | 201954 | E52B2F929E941A19AD35B58A1EE7ABE905448F33F1F1A1B84DCBD6A65094E34E |
| item-search-script.blade.php | 2527 | 0A9FDFB24A6D4B741BB27273B839CD25629F866F0AFF7533C3ED62160591B804 |
| roles/_form.blade.php | 18172 | 6D0AA02D1D9F821A4EB394C557D88D873DA37A368FC7F4DD519D23907773A44C |

El servicio y la parcial JavaScript ya coincidian y no se volvieron a subir.
La vista principal y el formulario se activaron por renombrado despues de
verificar sus temporales. La vista seguia mostrando la version anterior al
conservar fechas; se resolvio usando Binary y `Do not preserve timestamp`
para tomar la fecha del servidor. No se editaron caches de Laravel.
Se conservaron respaldos remotos `index.blade.php.before-live-search-20261030`
y `_form.blade.php.before-refresh-20261030`.

Comprobacion en ADMIN: 28 items iniciales; `casco` encuentra uno; borrar
restaura 28; despues de recargar la URL filtrada, `guantes` encuentra 4;
una consulta inexistente muestra cero y la indicacion operativa. La nueva
ayuda del rol LOGISTICA aparece y se verificaron solo cuatro checks EPP
activos: ver, registrar, confirmar_entrega y confirmar_devolucion. No se
guardaron roles ni movimientos de negocio durante esta transferencia.

Evidencia: `outputs/logistics-recovery-2026-10-30/buscador-produccion.png`.
La optimizacion de notificaciones sigue siendo local; no forma parte de
esta entrega FTP de Logistica. No hubo migraciones en produccion.
