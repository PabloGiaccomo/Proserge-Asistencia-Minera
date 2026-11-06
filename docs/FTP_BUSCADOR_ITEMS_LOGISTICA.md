# Logistica: buscador de Identificacion de items

Fecha: 2026-10-30. Buscador verificado en produccion.

## Uso

En Logistica > Identificacion de items, elegir EPP, Herramientas o Consumibles
y escribir un nombre o codigo. Los resultados y el contador se actualizan
mientras se escribe, sin recargar ni perder el foco. Buscar y Enter tambien
aplican el filtro inmediatamente. Se aceptan palabras
parciales en cualquier orden, con o sin tildes y sin distinguir mayusculas.
La consulta se conserva al cambiar de categoria. Limpiar quita la consulta y
mantiene la categoria seleccionada. El contador corresponde a los resultados
de esa categoria; cero coincidencias no significa que falte stock.

La busqueda incluye los items registrados y las herramientas aprendidas de
paradas. Se elimina primero la duplicidad por nombre y se busca tambien en el
codigo que aparece en pantalla. Un resultado vacio invita a revisar otra
categoria o limpiar. Agregar item conserva sus permisos actuales.

## Archivos de produccion

- `app/Modules/Logistica/Services/LogisticaDashboardService.php`: normaliza
  `ident_q`, conserva el catalogo completo para ampliar la busqueda al borrar
  texto y prepara el resultado inicial.
- `resources/views/logistica/index.blade.php`: formulario GET, contador,
  mensaje sin coincidencias y estilo adaptable del buscador.
- `resources/views/logistica/partials/item-search-script.blade.php`: filtra
  nombre/codigo al escribir, actualiza el contador y conserva la consulta en la
  URL y los enlaces de categoria. Limpiar restaura la lista sin recargar.

El filtro de catalogo usa `ident_q`, independiente del filtro `q` de trabajadores
y otras pestanas. La busqueda usa el acceso existente a Identificacion de items;
no requiere permisos de escritura ni cambia roles, datos o alcance por mina.

## Transferencia por WinSCP

1. Guardar copia de los archivos actuales del servidor para poder volver.
2. Abrir la raiz Laravel que contiene `artisan`, `app` y `resources`.
3. Usar `logistics-item-search-live-2026-10-28.zip`, conservando las rutas.
   Los tres archivos pertenecen a la misma entrega; incluir la nueva parcial
   `item-search-script.blade.php`. Reutilizar una sola sesion conectada.
4. Subir cada archivo con nombre temporal en su directorio de destino,
   en modo Binary. Para las vistas Blade desmarcar `Preserve timestamp`
   en las opciones de esa transferencia: conservar una fecha anterior a la
   vista compilada puede mantener una pantalla antigua. Descargar el temporal
   y comparar tamano y SHA256 antes de activarlo por renombrado. Conservar
   un respaldo del archivo anterior. Con fecha del servidor se verifico la
   renovacion de las vistas sin acceso a terminal ni edicion de caches.
5. Recargar Identificacion de items y escribir sin pulsar Buscar. Comprobar
   nombre, codigo, cambio de categoria, Limpiar y una consulta sin resultados
   con el rol operativo. Borrar letras debe volver a ampliar la lista.

Esta entrega usa estilos y JavaScript de vistas Blade; no modifica assets Vite
ni necesita npm, build o migracion. Reutiliza `app/Shared/Support/SearchTerms.php`,
ya utilizado por el servicio de Logistica existente. No transferir `.env`,
dependencias ni datos locales como parte de esta entrega.

## Evidencia local

- MySQL aislado `proserge_app_test`: LogisticaItemSearchTest,
  LogisticaDashboardServiceTest y LogisticaEppIntegrationTest:
  **40 tests, 347 assertions**.
- Browser `logistics-item-search.cjs`: 1440 y 390 px; busqueda por nombre/codigo,
  tildes, palabras invertidas, categoria conservada, cero resultados y Limpiar.
  Verifica escritura sin recarga, foco conservado, ampliacion al borrar tras
  recargar una URL filtrada y apertura del modal Editar.
- Pint, sintaxis PHP, compilacion Blade y `git diff --check`: correctos.
- Capturas con datos sinteticos en `outputs/logistics-item-search/`.

## Validacion en produccion: 2026-10-30

Se recupero primero la vista truncada que causaba el error 500, verificando
por descarga el respaldo completo. Despues se activo el buscador con el
mismo procedimiento de archivo temporal y hash. El servicio y la parcial
JavaScript ya estaban completos: sus descargas coincidieron con la entrega.

En la sesion web autenticada se comprobaron 28 items iniciales, filtrado al
escribir `casco`, restauracion de los 28 al borrar, cero coincidencias y
cambio de `casco` a `guantes` despues de recargar una URL filtrada (4 items).
La ayuda de permisos de EPP tambien quedo visible en el editor de roles;
LOGISTICA conserva solo Ver, Registrar, Confirmar entrega y Confirmar
devolucion en EPPs. No se modificaron roles ni registros de negocio en esta
transferencia. La comprobacion web de esta reanudacion uso la cuenta ADMIN.

Evidencia: `outputs/logistics-recovery-2026-10-30/buscador-produccion.png`.
