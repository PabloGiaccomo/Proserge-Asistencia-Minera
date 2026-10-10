# Sincronizacion Git y FTP: verificacion previa

Fecha: 2026-10-10. Alcance solicitado: todos los cambios del proyecto.

## Estado del corte

El codigo se conserva en la rama de trabajo
`codex/pending-work-2026-10-05` como checkpoint, no como release aprobada.
No se mezcla con `main` ni se publica una version activa en produccion mientras
persistan los bloqueos descritos abajo.

Se excluyen credenciales, bases exportadas, documentos privados, respaldos,
temporales, dependencias instaladas y mapas Graphify generados. Los assets
compilados y la plantilla oficial de Kardex si forman parte del proyecto.

## Verificacion ejecutada

- Sintaxis PHP: 167 archivos nuevos/modificados comprobados sin errores.
- `npm run build`: correcto; manifiesto y assets generados juntos.
- `php artisan view:cache`: correcto.
- `git diff --check`: correcto antes de preparar el checkpoint.
- Suite completa, base aislada `proserge_app_test`: 1397 pruebas,
  11241 assertions, 3 errores y 42 fallos. No es una suite global verde.
- Reproduccion focalizada `MiningTransactionVisibilityTest`: 18 pruebas,
  2 errores. Fallan `test_sync_preserves_new_approval_committed_after_outer_snapshot`
  y `test_attempt_number_uses_current_read_after_outer_snapshot`.

La reproduccion encuentra respectivamente una colision del indice
`uq_examen_intento_numero` y un rechazo de registro concurrente. Hay que revisar
la numeracion persistida y el consumo operativo del ciclo antes de desplegar;
este checkpoint no cambia reglas para ocultar esos fallos.

## Produccion

- Inspeccion FTP de solo lectura completada mediante FTPS explicito con
  certificado validado para el hostname del proveedor.
- Acceso directo remoto MySQL rechazado (1045). No se pudo verificar el esquema
  ni obtener un respaldo nuevo de la base con ese acceso.
- El `composer.lock` remoto no contiene las dependencias locales
  `bacon/bacon-qr-code`, `dasprid/enum` y `endroid/qr-code`. Revisar la instalacion
  Composer de produccion antes del corte; copiar solo PHP no la actualiza.
- El usuario autorizo las migraciones pendientes, incluida la reparacion
  EMO/NO_APLICA, condicionadas a un respaldo verificable previo.
- No se ejecutaron migraciones, reparaciones ni cambios de datos en produccion.
- No se reemplazaron archivos servidos, caches ni documentos del hosting.

## Pendiente antes de publicar

1. Corregir y volver a probar los bloqueos de integridad reproducidos.
2. Clasificar los otros fallos de la suite, sin asumir que son inocuos.
3. Disponer de terminal cPanel/SSH o acceso administrativo autorizado equivalente
   para revisar esquema, dependencias y comandos del despliegue.
4. Respaldar y verificar MySQL y `storage/app/private`, ensayar las migraciones
   sobre una copia aislada y confirmar el corte.
5. Desplegar una sola version de codigo/dependencias/assets, con respaldo de
   archivos reemplazados y verificacion posterior. No borrar historial.
