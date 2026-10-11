# Sincronizacion Git y FTP: verificacion previa

Fecha: 2026-10-10. Alcance solicitado: todos los cambios del proyecto.

Este documento conserva la secuencia de verificaciones. El estado mas reciente
se encuentra en la ultima seccion; los resultados anteriores no son el estado
final del paquete corregido.

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

## Subida preparada por FTP (2026-10-10)

Ante la solicitud de subir lo restante sin activar los errores, se preparo una
version aislada. Estado confirmado: `PREPARED_NOT_ACTIVE`; no es un despliegue
funcional ni acredita la resolucion de las pruebas pendientes.

Ruta FTP:

```text
/sistema.proserge.com/Proserge-Asistencia-Minera/storage/app/private/deployment-releases/625c4a0-20261010/
```

- `proserge-625c4a0.zip`: snapshot del commit completo
  `625c4a0635cb90f46fa75baee92c975f4168c0dd`, 991 archivos fuente/recursos,
  6723340 bytes. No incluye .env, vendor, node_modules, tests ni datos privados.
- SHA-256 del ZIP:
  `D7A15418F70CC09FED01F7904C368B8D60D755BE1579FD2C232D4EA22404D401`.
- La carpeta incluye `.htaccess` restrictivo, instrucciones y lista de archivos.
  El ZIP conserva tambien el codigo pendiente de corregir; no debe extraerse
  sobre la aplicacion activa ni ejecutar migraciones de forma automatica.
- Tres assets con nombre hash precargados en cada directorio de build: el de la
  aplicacion y el document root. No se sustituyeron los manifiestos activos.
- Diez destinos verificados mediante descarga y SHA-256: ocho archivos nuevos
  y dos assets que ya coincidieron. No se sobreescribieron archivos existentes.
- Los dos manifiestos activos y `routes/web.php` conservaron su hash previo.
- `GET` no ejecutado sobre el ZIP; comprobacion `HEAD` de acceso web: 403.
  `HEAD /up` y `HEAD /login`: 200 despues de la subida.
- Build y compilacion Blade correctos; cinco pruebas unitarias seleccionadas
  pasan. Esto no convierte en verde la suite completa anterior.
- No se ejecutaron migraciones, cambios de datos ni limpieza de caches remotos.

La ruta local con evidencia de transferencia es
`storage/app/private/deployment-backups/sync-20261010/ftp-prepared-625c4a0/upload-result.json`.
Esa evidencia permanece privada y fuera de Git. La subida aislada no sustituye
el respaldo verificable de MySQL/documentos requerido para activar la version.

## Paquete corregido con activacion controlada

Se corrigio la numeracion concurrente de intentos mediante una lectura actual
con bloqueo, conservando el indice UNIQUE y los locks del flujo. Tambien se
alineo el filtro servidor de Personal con el comportamiento existente del
cliente: lista negra exceptua solo el estado, no busqueda, puesto ni contrato.
No se cambiaron formulas, permisos productivos ni reglas de convalidacion.

Las pruebas heredadas se actualizaron para usar permisos explicitos actuales,
criterios completos, fixtures aislados de paginacion y fechas America/Lima.
Las comprobaciones negativas de autorizacion se conservaron.

El nuevo paquete incluye 8579 archivos de ejecucion y vendor de produccion
instalado desde composer.lock, sin dependencias dev, .env ni documentos.
Incluye Logistica/EPP, assets compilados y las migraciones pendientes.
SHA-256 del ZIP:
`6f9201a264e416f59ca62b1b3847955184b4254991a5af12685ba489993905a8`.

Destino de esta revision (no activar los paquetes anteriores):

```text
/sistema.proserge.com/Proserge-Asistencia-Minera/storage/app/private/deployment-releases/release-20261010-fixed-r3/
```

La activacion requiere consola del hosting:

```bash
cd ~/public_html/sistema.proserge.com/Proserge-Asistencia-Minera
bash storage/app/private/deployment-releases/release-20261010-fixed-r3/deploy.sh
```

El instalador bloquea ejecuciones simultaneas, valida hashes, PHP/extensiones,
espacio y configuracion nueva sin cache frente a la configuracion activa. Exige
misma conexion MySQL, debug desactivado, mantenimiento por archivo, disco
privado correcto y tablas InnoDB antes del respaldo transaccional.

En mantenimiento respalda MySQL, codigo/dependencias, .env y storage/app fuera
del document root; comprueba integridad comprimida y hashes. No equivale a
un ensayo de restauracion. Solo entonces reemplaza codigo/vendor y ejecuta
package:discover, optimize:clear, migrate --force, config:cache, route:cache,
view:cache y up. Publica tambien el build en el document root separado.
Verifica tablas requeridas y conteos de historial. No ejecuta fresh, seed,
truncate, rollback ni borra documentos. Las migraciones de reparacion
autorizadas si pueden actualizar datos; MySQL DDL no es atomicamente reversible.

Si falla antes del reemplazo, conserva la version anterior; si falla despues,
mantiene mantenimiento y conserva respaldos para diagnostico. Los errores del
helper devuelven codigo no cero, sin SQL ni credenciales. --check permite
comprobar requisitos sin activar ni migrar.

Verificacion local adicional:

- Extraccion y hashes de los 8579 archivos: correctos.
- Autoload de Laravel, PhpSpreadsheet, QR y EppKardexService sin dev: correcto.
- Ocho casos del helper: correctos, incluyendo rechazo de .env, traversal,
  corrupcion, archivos no listados, limpieza acotada de cache y preflight sin
  cache sobre proserge_app_test; APP_DEBUG=true aborta con codigo 1.
- Sintaxis de 19 archivos PHP modificados y bash -n: correctas.
- Build Vite, compilacion Blade y git diff --check: correctos.
- Pint detecta estilo historico en archivos extensos; no se aplico un formateo
  general ajeno al arreglo.

La subida FTP no activa esta version. No se ejecutaron respaldo, migraciones,
reparaciones ni caches de produccion desde este equipo. Falta la activacion por
el operador y la prueba autenticada de Logistica/EPP y los flujos operativos.

Transferencia r2 confirmada: siete archivos subidos y verificados mediante
descarga y SHA-256. routes/web.php y el manifiesto activo conservaron sus hashes.
HEAD /up y /login: 200; acceso HTTP al ZIP y deploy.sh privados: 403.
Estado: UPLOADED_CLI_ACTIVATION_REQUIRED. Evidencia privada local:
storage/app/private/deployment-backups/release-20261010/upload-result.json.

La revision final r3 conserva el mismo ZIP y aclara expresamente en LEEME.md
que las migraciones autorizadas pueden reparar datos existentes. No activar r2.
Transferencia final r3 confirmada: siete archivos descargados y cotejados por
SHA-256, con codigo y manifiesto activos sin cambios. HEAD /up y /login: 200;
ZIP y script privados: 403. Esta es la revision que debe ejecutar el operador.

## Resultado final de pruebas

Suite completa ejecutada despues de todos los arreglos sobre MySQL aislado
proserge_app_test, APP_ENV=testing y DB_URL vacio: **1398 pruebas, 11437
assertions, 0 errores, 0 fallos y 0 omitidas** (340.59 segundos).
Se uso memory_limit=1536M; el limite predeterminado de 512M no alcanza para
la suite completa con importaciones Excel. Evidencia local no versionada:
.codex-tmp/full-verified-20261010.xml.

Compilacion Blade y git diff --check repetidos al finalizar: correctos.
Los cambios se sincronizan en codex/pending-work-2026-10-05, sin merge a main.
La suite verde no sustituye la prueba de migraciones sobre copia de produccion
ni la validacion autenticada posterior a la activacion del hosting.
