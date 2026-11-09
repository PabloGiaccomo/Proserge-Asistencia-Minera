# Despliegue FTPS del 9 de noviembre de 2026

## Version publicada

Commit de aplicacion: `abf94a1bee07b3c7c9e0a0ee05ff70e645451738`, rama
`codex/pending-work-2026-10-05`. Se instalaron 862 archivos de ejecucion desde
el snapshot Git, con sus tres assets Vite completos y el manifiesto. Las
dependencias Composer existentes coincidieron con el lock y se conservaron.

La publicacion comprende las correcciones de modulos y RR. HH., actualizacion
de fichas, contratos e ingresos masivos, transporte, experiencia movil,
calendario por fechas y alta multiple de usuarios con exportacion privada.

## Respaldo y migraciones

Se inspeccionaron las 103 tablas existentes, todas InnoDB. La estructura SQL
real se reprodujo en una base local aislada, sin importar registros de
trabajadores; las once migraciones de esta entrega pasaron ese ensayo.

Durante mantenimiento se obtuvieron respaldos completos de MySQL, archivos
privados, codigo y archivos publicos administrados. Los respaldos quedaron
bajo el directorio privado de la cuenta, fuera de todo `public_html`, con
permisos restrictivos. Se verificaron compresion, listados de archivos y SHA256
antes de instalar codigo o ejecutar DDL. La copia documental comprimida ocupa
aproximadamente 2,45 GB. Esto acredita integridad y ensayo de esquema; no un
ensayo de restauracion completa de los documentos y datos.

Se ejecutaron exclusivamente las once migraciones nuevas de la entrega: la
alineacion de notificaciones del 21 de octubre, las nueve del 4 de noviembre
y la de lotes de usuarios del 6 de noviembre. Se conservaron las columnas
preexistentes y los conteos de negocio; se comprobaron catalogos, indices y
claves foraneas. No se ejecutaron semillas, reparaciones historicas ni rollback.

La configuracion de conexion, APP_KEY y archivos privados se conservaron.
El hosting mantiene su configuracion heredada APP_ENV=local y
APP_URL=http://localhost, con APP_DEBUG=false. Las comprobaciones HTTP se
dirigieron expresamente al dominio real, `https://sistema.proserge.com`.

## Instalacion y comprobacion

Los archivos se copiaron con temporales y renombrado, verificando contenido y
tamano. El build se publico tanto en Laravel como en el document root separado;
se conservaron los index y .htaccess del alojamiento y los assets anteriores.

Se reconstruyeron las caches mediante Artisan y se refresco OPcache de PHP FPM.
PHP FPM 8.2.35 cargo las clases nuevas y confirmo las once migraciones. Los tres
assets respondieron HTTP 200 con MIME y SHA256 correctos; `/up` y `/login`
respondieron 200, y el alta multiple redirigio al login sin autenticacion.

El instalador temporal exigio POST, clave aleatoria con caducidad, hashes del
paquete y herramientas, acciones cerradas y un bloqueo de despliegue. Los
accesos web auxiliares y su clave se retiraron al finalizar. La evidencia y los
respaldos permanecen privados y no se versionan.

## Tareas automaticas de esta entrega

La inspeccion del crontab encontro un scheduler de otra aplicacion, que se
conservo. Se agregaron exclusivamente estas tres tareas de Proserge:

- Reintento de avisos de Roles: cada cinco minutos.
- Purga de credenciales de lotes vencidos: cada cinco minutos.
- Limpieza de PDFs temporales vencidos: cada treinta minutos.

Dos de esos nombres son callbacks de `Schedule`, no comandos Artisan. Las
entradas nuevas usan un ejecutor CLI privado con tres modos permitidos que
reutilizan los servicios existentes; cada tarea usa `flock` y omite ejecucion
durante mantenimiento. La lista de cron anterior y el plan nuevo se respaldaron
y verificaron antes de instalarlos. No se habilito un `schedule:run` general.

Conservar el ejecutor
`storage/app/private/deployment-backups/release-20261109-abf94a1/maintenance-jobs.php`
y su `manifest.json`: el cron apunta a ellos. No retirar ese directorio como
si fuera un temporal. Al migrar a un scheduler general, retirar primero las
tres entradas identificadas por `PROSERGE_RELEASE_TASKS_V1` para mantener una
sola configuracion de tareas.

Las tres tareas registraron su primera ejecucion real correcta a las 09:30 del
9 de noviembre de 2026, America/Lima. No fue necesario ejecutarlas manualmente.

## Limites de la validacion

La comprobacion remota verifico instalacion, esquema, conservacion de datos,
caches, acceso y recursos. No se crearon trabajadores, usuarios, contratos ni
solicitudes reales como prueba. Los recorridos funcionales de escritorio y
movil se habian validado con datos sinteticos antes de publicar; la aceptacion
con sesiones y casos reales de las areas usuarias queda a su operacion habitual.
