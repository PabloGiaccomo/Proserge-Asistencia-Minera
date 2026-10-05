# Project Overview

Sistema Proserge es una aplicacion corporativa interna para RR.HH.,
reclutamiento, operaciones mineras, logistica, SSOMA, contabilidad, gerencia y
administracion. Gestiona datos reales y sensibles de trabajadores, fichas,
contratos, documentos, minas, habilitaciones, requerimientos, Man Power,
asistencia, faltas, evaluaciones, EPP, transporte, usuarios, permisos y
notificaciones.

Los usuarios incluyen personal de oficina, supervisores y trabajadores con poca
experiencia tecnologica. Los cambios deben conservar trazabilidad y usar
lenguaje operativo conocido por las areas usuarias.

Antes de implementar:

1. Leer este archivo y `docs/CODEX_CONTEXT.md`.
2. Leer el mapa o regla del modulo en `docs/`, empezando por
   `docs/ARCHITECTURE_MAP.md` y `docs/MAPA_ARCHIVOS.md` cuando aplique.
3. Revisar `git status` y preservar cambios no relacionados.
4. Buscar con `rg` antes de asumir que una funcion, servicio o componente no
   existe.
5. Revisar servicios y tests existentes del flujo afectado.
6. Aclarar ambiguedades operativas que puedan cambiar reglas o datos.
7. Explicar brevemente los archivos que se modificaran y por que.

# Tech Stack

- Backend: PHP 8.2+, Laravel 12 y Eloquent.
- Base de datos: MySQL; la mayoria de tablas de negocio usa UUID de texto.
- Web: Blade renderizado en servidor, JavaScript nativo y CSS propio.
- Build: Vite 7 y Tailwind CSS 4 sobre `resources/css/app.css` y
  `resources/js/app.js`.
- API: `/api/v1`, tokens Bearer propios y respuestas mediante `ApiResponse`.
- Web auth: sesion propia con `web.auth`; no asumir el guard Laravel por defecto.
- Permisos: `web.permission`, policies y matriz en `app/Support/Rbac`.
- Archivos: disco privado `local` en `storage/app/private`.
- Excel: PhpSpreadsheet 5.
- PDF: Dompdf, FPDF y FPDI.
- Pruebas: PHPUnit 11, principalmente Feature sobre MySQL.
- Calidad PHP: Laravel Pint.

Las versiones exactas viven en `composer.lock` y `package-lock.json`. No cambiar
dependencias sin revisar compatibilidad, build y despliegue en cPanel.

# Architecture

Es un monolito modular Laravel con dos superficies HTTP:

```text
Web -> routes/web.php -> web.auth + web.permission -> PageController
API -> routes/api.php -> auth.token (+ mina.scope cuando aplica) -> Controller
Controller -> Request/Policy -> Service -> Eloquent/MySQL o almacenamiento
PageController -> Blade -> Vite/CSS/JavaScript
```

Convencion de `app/Modules/<Modulo>`:

- `Controllers/*PageController.php`: interfaz web Blade.
- `Controllers/*Controller.php`: API.
- `Requests/`: validacion de entrada.
- `Resources/`: contrato de salida API.
- `Policies/`: autorizacion de dominio.
- `Services/`: reglas, consultas y transacciones.
- `Support/`: normalizadores o catalogos propios del modulo.

Los controladores deben ser delgados. No duplicar reglas entre web, API, Blade
o JavaScript. Las relaciones y constantes simples pertenecen a modelos; los
flujos, transacciones y cambios de estado pertenecen a servicios. Las pantallas
complejas deben recibir datos preparados por servicios, Resources o view models.

Modulos principales:

- `Personal`: trabajadores, fichas, documentos, contratos, regularizacion,
  import/export y habilitacion minera. Es el dominio mas amplio y sensible.
- `RQMina`: paradas, requerimientos, plan operativo, actividades y transporte.
- `RQProserge`: respuesta y asignacion de personal a requerimientos.
- `ManPower`: grupos de trabajo, responsables, destinos y personal asignado.
- `Asistencia` y `Faltas`: ejecucion diaria, cierre, reapertura y correcciones.
- `Evaluaciones`: evaluacion diaria, supervisores, residentes y analitica.
- `Logistica`, `Epps`, `Transporte` y `ParadaHerramientas`: recursos operativos.
- `Seguridad`, `Notificaciones` y `Catalogos`: capacidades transversales.
- `Dashboard`, `Bienestar`, `MiAsistencia`, `Perfil` y `System`: consulta y
  soporte al usuario.

Cadena operativa principal:

```text
Personal -> RQ Mina -> RQ Proserge -> Man Power -> Asistencia -> Faltas
                  \-> Plan operativo / transporte / herramientas
Personal -> Habilitacion minera -> requisitos -> intentos -> resultado
```

# Repository Structure

- `app/Models`: modelos Eloquent compartidos por los dominios.
- `app/Modules`: codigo organizado por dominio.
- `app/Shared`: respuestas, concerns y servicios reutilizables.
- `app/Support/Rbac`: catalogo, normalizacion y alcance de permisos.
- `bootstrap/app.php`: rutas, aliases de middleware y excepciones globales.
- `routes/web.php`: superficie web y permisos por accion.
- `routes/api.php`: API v1 y autenticacion Bearer.
- `routes/console.php`: scheduler y comandos programados.
- `database/migrations`: evolucion incremental de ambientes existentes.
- `database/setup`: esquema SQL historico para instalaciones antiguas.
- `database/seeders`: catalogos y datos controlados de inicializacion.
- `resources/views`: Blade por modulo.
- `resources/css`: estilos globales y por dominio.
- `resources/js`: comportamientos compartidos; algunas vistas aun tienen JS
  inline.
- `resources/contract-templates`: plantillas Excel de contratos.
- `public/build`: salida generada de Vite requerida en produccion.
- `storage/app/private`: documentos y archivos sensibles.
- `tests/Feature`, `tests/Unit`, `tests/Browser`: pruebas automatizadas.
- `docs`: reglas, arquitectura, operacion, UX y mapas por modulo.

No editar manualmente `vendor`, `node_modules`, caches de Laravel ni assets con
hash en `public/build/assets`.

# Development Commands

Preparacion local:

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --force
npm ci
npm run build
```

Desarrollo:

```bash
composer dev
php artisan serve
npm run dev
```

Calidad y diagnostico:

```bash
vendor/bin/pint --test
php artisan route:list
php artisan about
php artisan schedule:list
php artisan optimize:clear
```

No ejecutar comandos destructivos, importaciones masivas ni migraciones contra
produccion sin backup y confirmacion explicita.

# Testing

`phpunit.xml` usa MySQL `proserge_app_test`. Nunca ejecutar pruebas apuntando a
una base con informacion real. La base de pruebas debe existir y ser aislada.

```bash
composer test
php artisan test --filter NombreDelTest
php -l ruta/al/archivo.php
php artisan view:cache
npm run build
git diff --check
```

Reglas de validacion:

- Ejecutar primero tests focalizados del modulo y luego la suite relevante.
- Usar `DatabaseTransactions` siguiendo los tests Feature existentes.
- Agregar regresion cuando cambie una regla de negocio o se corrija un defecto.
- Si se toca Blade, verificar compilacion de vistas y el flujo en navegador.
- Si se toca CSS/JS compilable, ejecutar `npm run build` y revisar desktop y
  mobile.
- Los scripts de `tests/Browser` requieren Playwright/Chrome disponibles; no
  asumir que la dependencia esta instalada por `package.json`.
- Indicar claramente toda prueba no ejecutada y la razon.

# Coding Conventions

- Seguir `.editorconfig`: UTF-8, LF, cuatro espacios; YAML usa dos.
- Preferir ASCII en archivos que ya lo usan, salvo texto de usuario que requiera
  caracteres propios del idioma.
- Reutilizar servicios, modelos, Resources, Requests, policies y componentes.
- No quemar nombres de personas, minas, examenes o reglas circunstanciales.
- Usar transacciones para cambios coordinados y `lockForUpdate` donde exista
  riesgo de concurrencia.
- Importadores deben reportar creados, actualizados, omitidos, errores y
  advertencias; deben tener preview cuando afectan datos sensibles.
- No colocar reglas extensas de negocio en Blade o JavaScript.
- Extraer gradualmente JS/CSS inline cuando se amplie una vista compleja, sin
  mezclarlo con refactors ajenos al pedido.
- Mantener lenguaje operativo, filtros claros, paginacion y siguiente accion
  visible para usuarios no tecnicos.
- No depender solo del color para comunicar estados.

# Database / Migrations

Conviven `database/setup` y `database/migrations`. Para cualquier cambio de
tabla, buscar primero en ambos y en modelos, servicios y tests:

```bash
rg "nombre_tabla" app database tests
```

- Crear migraciones incrementales, compatibles con datos existentes y, cuando
  sea necesario, idempotentes frente a esquemas historicos.
- No usar `migrate:fresh` como estrategia de produccion ni asumir que reproduce
  todos los datos maestros.
- Antes de produccion: backup de MySQL y `storage/app/private`, revisar SQL,
  probar sobre copia realista y ejecutar `php artisan migrate --force`.
- No borrar fisicamente historial requerido para auditoria.
- Conservar snapshots cuando precios, contratos o datos de contexto puedan
  cambiar en el futuro.
- La migracion
  `2026_06_01_000200_add_grupo_trabajo_id_to_asistencia_encabezado.php` tuvo un
  conflicto conocido de indice duplicado; revisarla antes de cambios cercanos.
- Los archivos de reparacion de esquema deben probar variantes de esquema, no
  solo una base recien creada.

# Security Constraints

- Toda accion critica web debe pasar por `web.auth` y
  `web.permission:modulo,accion`.
- Toda escritura API debe requerir `auth.token`; aplicar `mina.scope` o filtro
  de servicio cuando el dominio sea dependiente de mina.
- Ocultar botones sin permiso no reemplaza la autorizacion backend.
- Descargas, exportaciones e importaciones deben validar permiso y alcance.
- No exponer `.env`, credenciales, tokens, backups ni documentos privados.
- No mover archivos personales a `public` como solucion rapida.
- Auditar cualquier uso de rutas automaticas `storage/{path}`.
- Registrar actor, fecha, origen, motivo y contexto en acciones historicas.
- Mantener `APP_DEBUG=false` en produccion.
- No registrar en logs datos sensibles completos si basta un identificador.

# Important Architectural Decisions

## Ciclo laboral y contratos

- Nadie puede quedar `ACTIVO` sin contrato firmado vigente.
- Contratos historicos, cerrados, cesados, no renovados o anulados son
  inamovibles.
- Una renovacion o reingreso crea un contrato nuevo; nunca sobreescribe el
  anterior.
- Preparar una renovacion futura no desactiva al trabajador con contrato vigente.
- Solo Personal/Contratos modifica estado laboral y contratos.
- Personal antiguo se registra o regulariza sin duplicarse por DNI/documento.

## Habilitacion minera

- No modifica estado laboral, contrato, cargo, supervisor, ficha, documentos ni
  renovaciones.
- Sigue trabajador -> mina -> requisitos/examenes -> intentos -> resultado ->
  estado de habilitacion.
- Una mina no queda `HABILITADO` sin examenes configurados, generados y resueltos.
- Textos operativos de Excel como `PROGRAMAR EMO` representan `EN_PROCESO` mas
  una accion pendiente, no un estado final.
- El maximo configurado de intentos es referencia; se permiten intentos
  adicionales y se muestra el consumo real, por ejemplo `3/2`.
- Si alcanza o supera el maximo con resultado desaprobado, mostrar
  `NO_HABILITADO`, pero permitir nuevos intentos y asignaciones.
- `NO_APLICA` cuenta como resuelto y no exige observacion obligatoria.
- La convalidacion se sugiere y confirma por usuario; no se aplica en silencio.
- Un curso o EMO global confirmado se refleja en minas activas que requieran el
  mismo `examen_id`. No convalida examenes o resultados diferentes y conserva
  trazabilidad por mina.
- Precios de intentos se guardan como snapshot.
- El master Excel es apoyo operativo: no crea trabajadores ni sobreescribe datos
  sensibles sin preview, validacion y confirmacion.

## Documentos, importaciones e historial

- Estados documentales: `PENDIENTE`, `CARGADO`, `OBSERVADO`, `APROBADO`,
  `NO_APLICA`.
- La descarga masiva mantiene carpetas por trabajador.
- Excel historicos no son fuente absoluta de verdad.
- No perder referencias a archivos ni eliminar historial necesario.
- Archivado requiere referencia MySQL, permisos, auditoria y recuperacion.
- Antes de procesos masivos evaluar indices, volumen, tiempo y espacio en disco.

## Operacion y UX

- Interfaces operativas antes que formularios tecnicos expuestos.
- Configuracion administrativa vive en Acciones, modales o vistas dedicadas.
- Tablas grandes usan busqueda, filtros, contadores y paginacion sin recarga
  completa cuando el flujo lo permita.
- Estados deben indicar que falta y cual es el siguiente paso.
- Evaluar formatos Excel/PDF historicos antes de redisenar flujos.

# Generated Files

- `public/build/manifest.json` y `public/build/assets/*`: generados por
  `npm run build`; no editar a mano. Produccion cPanel no tiene npm, por lo que
  deben llegar compilados desde un entorno controlado.
- `vendor` y `node_modules`: dependencias instaladas, no codigo fuente del
  proyecto.
- `storage/framework`, `storage/logs`, caches y resultados de render: artefactos
  locales, salvo que una tarea indique lo contrario.
- `.phpunit.result.cache`: cache local de pruebas.
- `graphify-out`: mapa estructural generado; no es fuente de verdad ni debe
  versionarse sin una decision explicita del proyecto.
- Backups, ZIP, PDF/DOCX generados y archivos temporales no deben agregarse al
  repositorio accidentalmente.

# Definition of Done

Antes de cerrar una tarea:

- Confirmar que el cambio respeta las invariantes del dominio.
- Revisar `git diff` y no incluir archivos ajenos.
- Ejecutar tests focalizados y controles proporcionales al riesgo.
- Ejecutar `php -l` en PHP tocado y `git diff --check`.
- Ejecutar `php artisan view:cache` si se modifico Blade.
- Ejecutar `npm run build` si se modificaron assets compilables.
- Validar permisos, alcance por mina y datos sensibles.
- Actualizar documentacion canonica si cambio comportamiento, configuracion,
  arquitectura, comandos o despliegue.
- Para frontend, revisar que no haya solapamientos y que el texto sea entendible
  en desktop y mobile.
- Resumir archivos modificados, pruebas ejecutadas, pruebas pendientes, riesgos
  manuales y pasos de despliegue.

# Graphify

Si existen `graphify-out/GRAPH_REPORT.md` o `graphify-out/graph.json`,
consultarlos antes de busquedas amplias y verificar conclusiones criticas contra
el codigo fuente.

Al generar o actualizar el grafo:

- Excluir `vendor`, `node_modules`, `storage`, backups y salidas generadas.
- Priorizar `app`, `routes`, `database`, `resources`, `tests`, `docs`, `config`
  y `bootstrap`.
- No instalar hooks ni regenerar automaticamente sin solicitud explicita.
- No versionar `graphify-out` por defecto.

Si Graphify no esta disponible o falla, continuar con `rg`, los mapas en `docs`
y lectura dirigida del codigo; no bloquear una investigacion por depender del
grafo.
