# Operacion, instalacion y despliegue

## 1. Requisitos

Para la convalidacion con equivalencias y nota opcional, aplicar antes de usar
el flujo `2026_10_05_000500_add_mining_exam_convalidation_equivalences.php`.
Agrega columnas JSON y auditoria sin cambiar resultados existentes. Desplegar
tambien `public/build/manifest.json` y los assets generados por `npm run build`.
Las reglas existentes mantienen sus valores; activar `Aceptar resultados de
otras minas` y elegir equivalentes por requisito en la configuracion. Las
convalidaciones historicas no se reescriben ni reciben snapshots inventados.

- PHP 8.2 o superior con `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`
  y extensiones requeridas por Laravel/PhpSpreadsheet.
- Composer.
- MySQL.
- Node.js y npm para compilar Vite.
- Servidor web apuntando a `public/`.
- Permisos de escritura para `storage/` y `bootstrap/cache/`.

## 2. Variables de ambiente

No copiar `.env` entre equipos por canales inseguros ni versionarlo. Como
minimo revisar:

```dotenv
APP_NAME=Proserge
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sistema.proserge.com

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
```

La plantilla `.env.example` actual aun usa valores genericos de Laravel y debe
corregirse antes de usarla para una instalacion nueva.

Laravel reporta actualmente la zona horaria `UTC` y `config/app.php` la tiene
escrita directamente, sin variable de ambiente. Antes de cambiarla, revisar
fechas ya almacenadas y probar los calculos de semana, vencimientos, alertas y
asistencia. Luego definir la zona acordada en `config/app.php` o adaptar esa
configuracion para leer una variable de ambiente.

## 3. Instalacion local recomendada

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm install
npm run build
php artisan serve
```

En Windows, si PowerShell bloquea npm, usar `npm.cmd`. El archivo
`PASOS_INSTALACION_LOCAL.txt` documenta el entorno Laragon que se uso
originalmente, pero sus rutas son especificas de una computadora.

Para desarrollo:

```bash
composer dev
```

Esto inicia servidor, queue listener, Pail y Vite mediante `concurrently`.

## 4. Base de datos

Para una instalacion existente:

```bash
php artisan migrate --force
```

Para una instalacion totalmente nueva, validar primero si se usara el esquema
de `database/setup` mas migraciones o solo migraciones. Actualmente conviven
ambas estrategias y no debe asumirse que `migrate:fresh` reproduce todos los
datos maestros requeridos.

Antes de migrar produccion:

1. Crear backup de MySQL.
2. Crear backup de `storage/app/private`.
3. Revisar SQL de la nueva migracion.
4. Probar sobre una copia.
5. Ejecutar despliegue.
6. Verificar rutas, login y flujo modificado.

Riesgo conocido: la migracion
`2026_06_01_000200_add_grupo_trabajo_id_to_asistencia_encabezado.php` produjo
un conflicto de indice duplicado en una instalacion local.

## 5. Assets frontend

Toda vista que usa `@vite` requiere:

```text
public/build/manifest.json
public/build/assets/*
```

Compilar:

```bash
npm ci
npm run build
```

Si el manifiesto falta, Laravel lanza `ViteManifestNotFoundException` y la
aplicacion responde 500. En el cPanel actual `npm` no esta disponible, por lo
que hay dos opciones correctas:

1. Compilar en CI o en una maquina controlada y desplegar `public/build`.
2. Habilitar una version compatible de Node/npm en cPanel.

No ejecutar `php artisan view:cache` antes de asegurar que el build existe.

## 6. Despliegue cPanel actual

Ruta conocida:

```text
~/public_html/sistema.proserge.com/Proserge-Asistencia-Minera
```

Comando usado cuando el build ya esta incluido en el repositorio/despliegue:

```bash
cd ~/public_html/sistema.proserge.com/Proserge-Asistencia-Minera && git pull origin main && php artisan migrate --force && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Despues ejecutar comprobaciones:

```bash
git log -1 --oneline
test -f public/build/manifest.json && echo "Vite OK"
php artisan migrate:status
php artisan route:list
php artisan about
```

No incluir `npm ci && npm run build` en el comando de cPanel mientras `npm` no
exista en ese servidor.

## 7. Cron y tareas programadas

Configurar un cron cada minuto:

```cron
* * * * * cd /home/trjytmzf/public_html/sistema.proserge.com/Proserge-Asistencia-Minera && php artisan schedule:run >> /dev/null 2>&1
```

El scheduler ejecuta:

- 02:10: limpieza de notificaciones vencidas.
- 07:30: alertas de herramientas por parada.
- Cada minuto: ausencias por horario de asistencia.
- 00:10: sincronizacion de vigencias de habilitacion minera.
- Cada minuto: sincronizacion por lotes de requisitos agregados o quitados de minas (`habilitacion-minera:sincronizar-requisitos`). Antes de habilitar la configuracion de requisitos, aplicar `2026_10_05_000100_create_mina_requisito_syncs.php` y comprobar que este cron corre. Si el cron no corre, las minas quedan en `Actualizando habilitaciones` y los estados conservadores `EN_PROCESO` no se recalculan.
- Cada 10 minutos: vencimiento de enlaces temporales de ficha de Personal.
- Cada 30 minutos: conciliacion de contratos vencidos y estados laborales de Personal.

La entrada a Personal ya no ejecuta estas dos ultimas tareas. Verificar que el
cron `schedule:run` funcione en produccion antes de desplegar esta mejora; si
no corre, enlaces y contratos vencidos no se actualizaran automaticamente.

Comprobar manualmente:

```bash
php artisan schedule:list
php artisan notifications:cleanup-expired
php artisan herramientas-parada:alertas-vencimiento
```

## 8. Cola

El script `composer dev` levanta `queue:listen`, pero el codigo actual usa
principalmente ejecucion sincrona. Si se migra correo, exportaciones o
notificaciones a jobs, produccion necesitara un worker persistente administrado
por Supervisor, systemd o una funcion equivalente de cPanel.

## 9. Backups

Respaldar como una sola unidad consistente:

- base MySQL;
- `storage/app/private`;
- `.env` en almacen seguro;
- plantillas de contrato y recursos publicados.

El backup de base sin archivos deja fichas y contratos incompletos. El backup
de archivos sin base pierde relaciones y metadata.

Probar restauracion periodicamente en un ambiente aislado.

## 10. Checklist posterior al despliegue

1. `GET /up` responde correctamente.
2. Login web funciona.
3. La cabecera, sidebar y favicon cargan.
4. `public/build/manifest.json` existe.
5. La ultima migracion aparece ejecutada.
6. Un usuario no administrador conserva permisos correctos.
7. Personal lista y abre documentos autorizados.
8. RQ Mina lista, abre y conserva plan operativo.
9. Herramientas por parada abre.
10. La campana de notificaciones aparece para un usuario permitido.
11. Scheduler y logs no muestran errores nuevos.

## 11. Diagnostico de incidentes frecuentes

### Error 500 por Vite

Sintoma: `ViteManifestNotFoundException`.

Accion: restaurar o publicar `public/build`, luego limpiar cache.

### Una ruta no existe despues del pull

```bash
php artisan optimize:clear
php artisan route:list
php artisan route:cache
```

Verificar que el commit esperado este realmente desplegado.

### Un usuario recibe 403

Revisar:

- estado del usuario;
- rol principal y roles adicionales;
- accion de la ruta en `routes/web.php`;
- matriz efectiva;
- `usuario_mina_scope`;
- log `web.permission_denied`.

### No aparecen notificaciones

Revisar:

- `notification_user_settings.in_app_enabled`;
- preferencia por rol y por tipo;
- permiso requerido del tipo;
- scope de mina;
- tipo activo;
- logs `notificaciones.*`.

### No se entrega correo de ficha

El flujo actual usa Outlook en Windows o devuelve `mailto:`. No asumir que
cPanel envia automaticamente. Revisar el resultado del servicio y considerar
migrar a SMTP.

### Documento no disponible

Confirmar fila de metadata, ruta registrada, existencia dentro de
`storage/app/private` y permisos del directorio. No mover documentos a `public`
como solucion rapida.

## Organizacion de examenes mineros por FTP

Seguir `docs/FTP_ORGANIZAR_EXAMENES.md`: nueva estructura incremental (o SQL
equivalente en phpMyAdmin), archivos PHP/Blade y `public/build` completo. La
pagina no ejecuta migraciones; si falta esquema muestra aviso y bloquea
escrituras. No copiar la base local ni asociar cursos automaticamente al subir
archivos. Respaldo y autorizacion preceden a cambios en bases operativas.

## Corte de citas EMO y control de asistencia

Migracion: `2026_10_07_000100_create_emo_appointments_and_clinics.php`.
Es aditiva y no asigna clinicas a intentos historicos. La categoria existente
queda `OTRO` hasta su clasificacion autorizada. El rollback operativo conserva
las tablas y columnas nuevas; `down()` no elimina historial medico.

Antes del corte, ensayar sobre una copia realista aislada: contar filas, medir
duracion de ALTER/indices, bloqueos y espacio, comparar intentos/resultados y
referencias a archivos antes/despues. La suite de `proserge_app_test` no
sustituye ese ensayo. La consulta agrupada usa union/temporary/filesort;
medir filtro por fecha y exportacion cercana al limite con volumen realista.

Orden de despliegue:

1. Respaldar MySQL y `storage/app/private`; confirmar restauracion disponible.
2. Activar mantenimiento. Una estrategia sin mantenimiento requiere ensayo
   escrito de compatibilidad de ambas versiones.
3. Aplicar primero la migracion aditiva con `php artisan migrate --force`.
4. Desplegar codigo y `public/build` compilado de la misma version.
5. Ejecutar `php artisan optimize:clear` y reconstruir los caches usados.
6. Registrar clinicas oficiales y clasificar categorias desde Acciones con
   usuario autorizado. No deducirlas de nombres/tipo/lugar ni del Master.
7. En staging comprobar dos clinicas/dos minas, cita compartida, resultado con
   detalle, regularizacion, filtros y Excel con/sin ambos permisos, usando
   fixtures ficticios. En produccion usar smoke no mutante; una escritura de
   prueba requiere fixture expresamente autorizado y reconciliacion auditada.
8. Salir de mantenimiento y vigilar errores, tiempos y auditoria.

Si falla, volver al codigo anterior conservando esquema e historial aditivos.
Reconciliar y documentar cualquier cita creada antes de reabrir la operacion.
No usar rollback destructivo, `migrate:fresh` ni reclasificaciones masivas como
solucion de emergencia. El ensayo y el despliegue requieren ambiente/backup
identificados; este runbook no acredita que ya se hayan ejecutado.

### Reparacion automatica de legado EMO y NO_APLICA (2026-10-10)

Desplegar junto al codigo `2026_10_10_000100_add_attempt_cycle_boundary_to_personal_mina_examenes.php`
y `2026_10_10_000200_repair_legacy_mining_emo_and_not_applicable.php`, y el servicio
`LegacyMiningDeployRepairService`. El comando habitual `php artisan migrate --force`
aplica la reparacion; no depende de visitar una pagina ni ejecutar un comando
posterior con actor humano. Respaldar y ensayar antes del despliegue, siguiendo
el orden anterior y publicando los assets compilados de la misma version.

Solo `OTRO` con nombre normalizado exacto `EXAMEN MEDICO` se clasifica como EMO
con auditoria de sistema. Se conservan categorias explicitas y nombres parecidos.
Para esos catalogos EMO, corrige resultados medicos habilitantes inequivocos
del legado, usando fecha Lima y recalculando la asignacion. Los pendientes
contradictorios de `NO_APLICA` se anulan sin borrar historial y cierran su ciclo.
Las transacciones por catalogo/examen permiten reintentar despues de una ejecucion
parcial sin duplicar auditoria ni mover otra vez el limite de ciclo.

Los esquemas historicos sin columnas/tablas indispensables se omiten sin
modificacion no auditada. Si falta el esquema de citas/auditoria, se omite un
pendiente con enlace EMO; los pendientes sin enlace siguen siendo reparables.
`down()` no revierte datos de negocio ni elimina evidencia. Tras desplegar,
verificar categoria/historial, avance, vigencia y nuevas programaciones desde
`NO_APLICA`, ademas del smoke autenticado habitual. Esta documentacion no
acredita que el despliegue o el ensayo sobre copia realista ya se hayan realizado.

## 12. Seguridad operativa

- Mantener `APP_DEBUG=false` en produccion.
- Rotar credenciales compartidas y la cuenta local por defecto.
- No publicar `.env`, backups ni documentos.
- Restringir rutas API de catalogos que escriben sin `auth.token`.
- Auditar la exposicion automatica de `storage/{path}`.
- Mantener Laravel, PHP y dependencias actualizadas tras probar regresiones.
- Registrar quien descarga, aprueba, cesa o elimina datos sensibles.
