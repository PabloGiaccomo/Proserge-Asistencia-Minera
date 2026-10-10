# Organizar examenes mineros: local y FTP

## Uso operativo

Ingresar a Habilitacion minera > Acciones > Organizar examenes. Requiere el
permiso `habilitacion_minera,configurar`. Ruta relativa:
`/personal/habilitacion-minera/organizar-examenes`.

1. Buscar nombres parecidos y comparar minas, nota, vigencia, precio e historial.
2. Elegir el examen principal y marcar las variantes que realmente representan
   el mismo curso. No agrupar teorico/practico u otros cursos diferentes por
   parecerse sus nombres.
3. Agregar nombres alternativos que aparecen en Excel, globales o para una mina.
   Se puede corregir el nombre principal; el anterior queda como alias global.
4. Escribir el motivo, revisar el impacto y confirmar explicitamente. La revision
   vence a los 15 minutos y se invalida si cambia el catalogo. Si falta certeza,
   registrar pendiente sin asociar.

No se borran ni trasladan IDs, intentos, resultados, precios o requisitos. Se
registra actor, motivo, fecha y estado antes/despues. Las diferencias de reglas
se conservan: agrupar no activa convalidacion ni aprueba al trabajador.

Excel reconoce grupos y aliases confirmados y usa el requisito activo de la mina
de destino. Dos requisitos activos del mismo grupo en una mina son ambiguos:
revisar cual debe quedar activo mediante la configuracion habitual. No se elige
el primero. Nombres parecidos sin confirmar quedan observados; el Master no
crea un duplicado por esa similitud. Un curso realmente distinto debe registrarse
con su nombre propio antes de importar cuando genera una sugerencia de parecido.

El Master conserva el nombre administrado y las reglas de convalidacion de
identidades organizadas. Para cursos sin identidad administrada conserva su
flujo anterior de reemplazo de catalogo, con preview y confirmacion. El historial
sin mina de origen sigue observado; esta herramienta no inventa su procedencia.

## Instalar estructura (en cada ambiente)

Antes de modificar una base operativa: respaldo MySQL, respaldo de
`storage/app/private`, prueba sobre copia realista y autorizacion del responsable.
No copiar la base local a produccion. FTP sube archivos, no actualiza MySQL ni
sincroniza las asociaciones revisadas en otro ambiente.

Elegir una sola via:

- Con terminal y Laravel preparado:

  ```bash
  php artisan migrate --force --path=database/migrations/2026_10_05_000600_create_mining_exam_catalog_identity_tables.php
  ```

- Sin terminal: importar en phpMyAdmin, seleccionando primero la base correcta,
  `database/setup/mining_exam_catalog_identity.sql`. Revisar que
  `examenes_mineros.id` y `minas.id` sean `char(36)` con
  `utf8mb4_unicode_ci`, InnoDB, como las nuevas referencias. Si las tres tablas
  ya existen, comprobar sus columnas e indices; `IF NOT EXISTS` no repara tablas
  parcialmente creadas ni esquemas incompatibles. Resolver esa diferencia con
  el administrador, no repetir importaciones a ciegas.

Las tres tablas nuevas empiezan vacias: `examen_minero_asociaciones`,
`examen_minero_aliases`, `examen_minero_catalogo_cambios`. No hay backfill,
migracion web ni DDL al abrir la pagina. Si faltan, la pagina avisa y bloquea
escrituras; el reconocimiento exacto anterior sigue disponible.

La convalidacion configurable requiere ademas la migracion anterior
`2026_10_05_000500_add_mining_exam_convalidation_equivalences.php` si ese ambiente
no la tiene. Instalarla mediante el procedimiento habitual despues de revisar
respaldo y esquema; no confundirla con las tres tablas de organizacion.

## Archivos del despliegue

Subir los archivos fuente correspondientes a esta version:

- Los tres modelos `app/Models/ExamenMinero{Asociacion,Alias,CatalogoCambio}.php`.
- `app/Modules/Personal/Services/MiningExamIdentityResolver.php`,
  `MiningExamCatalogOrganizationService.php`, `MiningExamCatalogAuditService.php`.
- `PersonalMinaBulkPreviewService.php`, `PersonalMinaExcelImportService.php`,
  `PersonalMinaHabilitacionService.php` en ese mismo directorio.
- `app/Modules/Personal/Controllers/MiningExamCatalogPageController.php`.
- `routes/web.php`, la vista `catalog-organization.blade.php` y el acceso en
  `resources/views/personal/habilitacion-minera/index.blade.php`.
- `resources/js/app.js`, `resources/js/modules/mining-catalog-organization.js`,
  `resources/css/app.css`, `resources/css/modules/mining-catalog-organization.css`.
- Migracion y SQL de estructura segun la via elegida.
- **Todo `public/build` de un mismo `npm run build` local**, incluido manifest y
  assets referidos. cPanel no necesita Node ni npm. No mezclar manifest de una
  version con assets de otra.

Si se despliega junto con la convalidacion previa, incluir tambien sus modelos,
controlador, vista parcial y `resources/js/modules/mining-convalidation.js`.
Tomar los archivos completos de la misma version, no fragmentos de este documento.

No subir `.env`, `public/hot`, bases exportadas, backups, pruebas, `node_modules`,
`.superpowers`, Graphify ni scripts de diagnostico locales. Conservar `.env`,
documentos privados y sesiones del hosting. No cambiaron dependencias Composer.

## Caches y comprobacion

Con terminal cPanel, desde la raiz Laravel:

```bash
php artisan optimize:clear
php artisan view:cache
```

Sin terminal, con el sitio en mantenimiento, retirar solo archivos generados
de vistas `storage/framework/views/*.php` (conservar `.gitignore`) y caches
generados de rutas `bootstrap/cache/routes-*.php`. Verificar sus nombres reales
antes de retirarlos; no borrar directorios ni caches de sesiones/documentos.
No se agregaron proveedores ni configuracion; no hace falta borrar config cache.
Si el hosting mantiene OPcache sin validar timestamps, solicitar reinicio PHP
desde cPanel o al proveedor. No crear endpoint publico para limpiar caches.

Comprobar inicio de sesion, permiso, acceso a la vista, filtros en desktop/movil,
preview sin guardar y confirmacion de una correccion previamente revisada. Revisar
que el historial permanezca y que un segundo preview Excel use el examen esperado.
Si se instala via SQL, una migracion posterior sigue siendo idempotente cuando
las tablas completas ya existen. No ejecutar rollback de estas tablas con datos:
el down elimina asociaciones, aliases y auditoria; volver a codigo anterior
dejando las tablas y respaldos preservados es el rollback preferido.
