# Logistica EPP: lotes, firma movil, devoluciones e inventario

**Fecha:** 2026-10-07

**Estado:** Pendiente de revision del documento por el usuario

**Alcance:** catalogo EPP, inventario, entrega, devolucion, aceptacion firmada, Kardex y trazabilidad

## 1. Objetivo

Permitir que Logistica prepare entregas o devoluciones de varios EPP para un solo trabajador, que el trabajador revise exactamente lo que recibe o devuelve y que confirme la operacion firmando en la pantalla movil del encargado.

La evidencia debe responder de forma verificable:

- quien firmo;
- que productos, cantidades y atributos firmo;
- que productos quedaron pendientes, rechazados o no disponibles;
- cuando y donde se proceso la operacion;
- quien la registro;
- si el contenido firmado fue modificado posteriormente;
- que saldo retornable conserva el trabajador.

La firma inicial se realizara en el dispositivo del encargado de Logistica. El encargado prepara la operacion y entrega temporalmente el dispositivo al trabajador para revisar y firmar. Un enlace o QR para firmar desde otro dispositivo queda fuera de la primera version, pero los identificadores y estados del modelo no deben impedir agregarlo despues.

## 2. Evidencia del sistema actual

El flujo existente esta concentrado en:

- `app/Modules/Epps/Controllers/EppPageController.php`;
- `app/Modules/Epps/Services/EppService.php`;
- `app/Models/EppRegistro.php`;
- `app/Models/EppEntrega.php`;
- `resources/views/epps/partials/workspace.blade.php`;
- `resources/views/logistica/index.blade.php`;
- `tests/Feature/EppCatalogTest.php`;
- `tests/Feature/LogisticaEppIntegrationTest.php`.

El comportamiento confirmado es:

- una solicitud HTTP crea una sola entrega;
- "Registrar y agregar otra" conserva trabajador y fecha, pero crea movimientos independientes;
- el cambio de EPP crea la nueva entrega y cierra la anterior dentro de una transaccion;
- las devoluciones y cierres registran al usuario de Logistica, pero no la aceptacion del trabajador;
- el Kardex web y Excel se forman desde `epp_entregas`;
- el Excel reserva una columna `FIRMA`, pero no escribe evidencia de firma;
- las entregas pueden editarse y eliminarse fisicamente;
- el catalogo no distingue retornables de consumibles;
- `stock` existe en `epp_registro`, pero el alta de catalogo lo fuerza a cero y la entrega no genera movimientos de inventario;
- el dashboard calcula cobertura con una lista fija de tipos de EPP;
- las consultas EPP no reciben al usuario ni aplican de forma consistente su alcance por mina;
- existe un canvas movil de firma en Personal que sirve como referencia tecnica, pero no debe reutilizar su almacenamiento Base64 directamente como modelo de evidencia EPP.

## 3. Principios e invariantes

1. La firma certifica lo procesado, nunca lo meramente solicitado.
2. Un articulo ausente, pendiente o rechazado no puede aparecer como recibido o devuelto.
3. Una operacion firmada es inmutable. Toda correccion se registra como un nuevo movimiento compensatorio.
4. Una firma pertenece a un trabajador y una operacion. No cubre movimientos de otros trabajadores.
5. Toda cantidad se expresa como solicitada, procesada y pendiente.
6. El trabajador debe ver el resumen final despues de cualquier cambio y antes de firmar.
7. Cambiar cantidad, producto, talla, color, atributo, condicion o tipo de movimiento invalida la firma capturada y exige firmar otra vez.
8. Los consumibles se entregan y quedan en el historial, pero no generan saldo de devolucion.
9. Los retornables conservan saldo por cantidad hasta su devolucion, perdida, baja o correccion autorizada.
10. El inventario no puede quedar negativo ni confirmarse desde un valor desactualizado.
11. El Kardex descargado es una representacion. La fuente verificable es el registro canonico privado del servidor.
12. Los registros historicos actuales se conservan y se identifican como `LEGACY_SIN_FIRMA`; no se inventan firmas retrospectivas.
13. Firma e identificacion son datos sensibles: almacenamiento privado, acceso autorizado y descargas auditadas.
14. La firma dibujada es una aceptacion electronica con firma manuscrita digitalizada, no un certificado criptografico de firma digital.

## 4. Modelo operativo

### 4.1 Operacion o lote

Una operacion agrupa todos los productos que un trabajador revisara en una sola pantalla y confirmara con una sola firma.

Tipos iniciales:

- `ENTREGA`;
- `DEVOLUCION`;
- `CORRECCION`;
- `ANULACION`.

Estados:

- `BORRADOR`: puede modificarse y no afecta existencias;
- `RESERVADO`: las unidades procesables fueron reservadas temporalmente;
- `LISTO_PARA_FIRMA`: resumen congelado y visible al trabajador;
- `FIRMADO`: todas las unidades procesables fueron aceptadas;
- `FIRMADO_PARCIAL`: existe aceptacion, pero quedaron cantidades pendientes;
- `PENDIENTE_STOCK`: no se proceso ninguna unidad por falta de disponibilidad;
- `RECHAZADO`: el trabajador rechazo toda la operacion;
- `EXPIRADO`: la reserva vencio antes de firmar;
- `ANULADO`: un movimiento posterior autorizado neutralizo sus efectos.

Solo `FIRMADO` y `FIRMADO_PARCIAL` generan entregas, devoluciones y movimientos definitivos de inventario.

### 4.2 Detalle del lote

Cada linea conserva:

- EPP solicitado y EPP realmente procesado;
- cantidad solicitada;
- cantidad procesada;
- cantidad pendiente;
- talla, color y atributos reales;
- politica de devolucion vigente como snapshot;
- condicion de devolucion cuando corresponda;
- precio unitario como snapshot cuando exista;
- motivo de pendiente, rechazo, sustitucion o incidencia;
- referencia a la entrega/saldo original en una devolucion;
- snapshot legible del nombre, codigo y atributos.

Estados de detalle:

- `PENDIENTE`;
- `RESERVADO`;
- `ENTREGADO`;
- `DEVUELTO`;
- `CONSUMIDO`;
- `NO_DISPONIBLE`;
- `RECHAZADO`;
- `PERDIDO`;
- `ANULADO`.

Una linea puede representar una entrega parcial: solicitada 10, procesada 5 y pendiente 5. La firma cubre solamente las 5 procesadas. Una atencion posterior crea otra operacion enlazada al pendiente; nunca modifica el lote ya firmado.

### 4.3 Politica del catalogo

Cada EPP activo debe definir obligatoriamente:

- `politica_devolucion`: `RETORNABLE` o `CONSUMIBLE`;
- `control_inventario`: si descuenta existencias;
- `permite_entrega_repetida`: util para consumibles;
- vida util, si aplica;
- talla, color y atributos configurables;
- si requiere numero de serie o identificador individual, preparado para una ampliacion posterior.

La migracion asignara `RETORNABLE` a los EPP existentes para no liberar obligaciones por error. Logistica debera revisar expresamente el catalogo y reclasificar descartables. Un cambio posterior de politica solo afecta operaciones nuevas.

### 4.4 Saldo bajo responsabilidad

`epp_entregas` se conserva durante la transicion como registro de asignacion al trabajador. Se ampliara con:

- operacion de origen;
- cantidad entregada;
- cantidad pendiente de devolucion;
- politica de devolucion snapshot;
- estado de integridad/firma;
- marca de registro legado.

Una devolucion parcial reduce el saldo con bloqueo de fila. Si se entregaron 5 y se devuelven 2, permanecen 3 en poder del trabajador. Una devolucion superior al saldo se rechaza. Los consumibles nacen con saldo retornable cero y estado `CONSUMIDO` para efectos de obligaciones, sin desaparecer del Kardex.

## 5. Inventario real

El valor unico `epp_registro.stock` no es suficiente porque no distingue almacen, mina, talla, color ni movimientos concurrentes. Debe sustituirse gradualmente por:

- almacenes activos y su mina opcional;
- existencias por almacen, EPP y clave de variante;
- libro inmutable de entradas, salidas, devoluciones, ajustes y liberaciones;
- reservas temporales con vencimiento;
- conteos/ajustes autorizados con motivo.

La clave de variante se construye de forma canonica con talla, color y otros atributos. La confirmacion aplica `lockForUpdate` sobre existencia, reserva, operacion y saldos afectados.

No se inferira un stock inicial desde entregas historicas ni desde el campo actual. El despliegue exige un conteo de apertura o importacion controlada por almacen. Hasta registrar apertura, la interfaz mostrara `STOCK_NO_CONCILIADO`, no cero disponible.

## 6. Flujo de entrega multiple

1. El encargado elige trabajador y almacen dentro de su alcance.
2. Agrega una o varias lineas; puede buscar, escanear o repetir consumibles.
3. El sistema consulta stock por variante y muestra disponible, solicitado y procesable.
4. Si no alcanza, el encargado elige entre entrega parcial, dejar pendiente, cambiar variante o proponer sustitucion.
5. El sistema valida duplicados y entregas retornables activas.
6. Al pasar a firma, reserva stock por un periodo corto y congela el resumen.
7. La pantalla cambia a modo trabajador: controles administrativos ocultos, texto grande y lista exacta de lo que recibira.
8. El trabajador puede aceptar, indicar desacuerdo o devolver el dispositivo sin firmar.
9. Al firmar, el servidor revalida reserva, alcance, trabajador, catalogo y cantidades.
10. En una unica transaccion crea movimientos de inventario, saldos, detalles y aceptacion.
11. Muestra constancia y permite descargar PDF o ver el Kardex.

Si ninguna linea puede entregarse, no se solicita firma. La operacion queda `PENDIENTE_STOCK`.

## 7. Flujo de devolucion multiple

1. Se elige trabajador y se cargan solo saldos retornables vigentes.
2. El encargado selecciona varios articulos y cantidades, incluyendo devolucion parcial.
3. Para cada linea indica condicion: `BUENO`, `DESGASTADO`, `DANADO`, `INCOMPLETO` u `OTRO`.
4. Perdida u olvido no se presentan como devolucion fisica; generan su movimiento especifico.
5. El trabajador revisa la lista que efectivamente esta entregando a Logistica.
6. La firma confirma solo esas cantidades.
7. La transaccion reduce saldos y, segun condicion, reingresa a stock disponible, cuarentena o baja.

Una devolucion completa puede cerrar varias asignaciones. Una parcial deja visible el saldo restante.

## 8. Incidencias y casos limite

### Disponibilidad y diferencias

- Solicitado 10, disponible 5: procesado 5, pendiente 5, firma por 5.
- Stock de sistema distinto al fisico: detener la linea y registrar `DIFERENCIA_INVENTARIO`; no corregir stock silenciosamente.
- Sin talla/color correcto: pendiente o sustitucion explicitamente aceptada.
- Sustitucion: conservar solicitado y entregado real; ambos aparecen en el resumen.
- Articulo desactivado durante el borrador: revalidar y bloquear antes de reservar.

### Aceptacion

- Aceptacion parcial por lineas: retirar del resumen firmado las lineas rechazadas y registrar su motivo.
- Cantidad discutida: volver a edicion; cualquier trazo previo se descarta.
- Firma vacia o accidental: exigir una cantidad minima de trazos y area ocupada, ademas de confirmacion visual.
- Rechazo de firma: liberar reservas y registrar `FIRMA_RECHAZADA`; no crear entrega aceptada.
- Imposibilidad de firmar: flujo excepcional con motivo, testigo identificado y aprobador con permiso especial. Nunca dibujar por el trabajador.
- Tercero que firma: identificar rol y motivo; la constancia no atribuye la firma al trabajador.

### Concurrencia y red

- Doble clic o reintento: `submission_uuid` idempotente por operacion.
- Dos encargados por el ultimo stock: el segundo recibe conflicto de disponibilidad y vuelve a revisar.
- Perdida de red antes de confirmar: conservar borrador; no afectar inventario.
- Perdida de respuesta despues de confirmar: consultar por `submission_uuid`; no repetir escritura.
- Sesion vencida: reautenticar y revalidar; no confirmar con credenciales vencidas.
- Primera version sin modo offline definitivo: nunca almacenar una operacion firmada solo en el navegador.

### Correcciones e historial

- Error antes de firmar: editar borrador.
- Error despues de firmar: crear correccion o anulacion compensatoria con motivo.
- Producto catalogado con otro nombre despues: el snapshot historico no cambia.
- Registro legado: visible como no firmado, sin codigo de verificacion de firma.
- Eliminacion: prohibida para firmado y legado; se reemplaza por anulacion auditada.

### Devolucion y cese

- Devolucion parcial: reduce solo lo firmado como devuelto.
- Cantidad superior al saldo: rechazo completo de la linea.
- Consumible: nunca aparece entre pendientes de devolucion o cesados.
- Perdido/danado: conserva responsabilidad, condicion y eventual importe snapshot; cualquier descuento economico queda fuera de esta primera implementacion.
- Trabajador cesado: lista exclusivamente saldos retornables positivos.

### Archivos e integridad

- Firma guardada pero transaccion fallida: limpiar temporal; no dejar evidencia huerfana.
- Base de datos confirmada pero archivo no promovido: la operacion queda en alerta tecnica y no se presenta como verificada hasta reparar.
- Archivo alterado o ausente: hash invalido/archivo faltante visible; nunca ocultar el problema.
- Excel modificado fuera del sistema: la pagina de verificacion muestra el contenido canonico del servidor.

## 9. Evidencia de firma

La aceptacion almacenara:

- imagen normalizada en PNG dentro del disco privado `local`;
- hash SHA-256 del archivo;
- JSON canonico del contenido mostrado;
- hash SHA-256 del JSON canonico;
- fecha/hora del servidor en zona aplicativa y UTC;
- trabajador, documento snapshot y tipo de firmante;
- usuario operador;
- `submission_uuid`;
- agente de navegador resumido e identificador de sesion;
- hash de IP, sin exponer la IP en vistas ni logs;
- version del texto de declaracion;
- codigo corto de verificacion no predecible.

El servidor valida prefijo MIME, decodificacion, dimensiones, tamano, trazos y limite de carga. La imagen nunca se mueve a `public` ni se inserta directamente como Data URL persistente.

La pagina de verificacion requiere autenticacion y permiso. Compara hashes, muestra el snapshot firmado y registra la consulta/descarga. El QR de la constancia apunta a esa ruta interna.

## 10. Kardex y constancias

El Kardex web agrupara movimientos por operacion y mostrara:

- fecha y tipo;
- productos y cantidades procesadas;
- cantidades pendientes;
- firma valida, firma invalida o legado sin firma;
- operador y firmante;
- codigo de verificacion;
- correcciones o anulaciones relacionadas;
- saldo retornable actual.

Se mantendra Excel para operacion y analisis, agregando identificador, estado de firma, fecha/hora y codigo de verificacion. No se incrustara una firma como unica prueba porque el archivo es editable.

Se agregara una constancia PDF por operacion con resumen, declaracion, firma, QR, hashes abreviados y relacion con correcciones. Las descargas se sirven mediante controlador autenticado desde almacenamiento privado.

## 11. Componentes y limites

Responsabilidades propuestas:

- `EppCatalogService`: politica del producto y snapshots;
- `EppInventoryService`: existencias, reservas y libro de movimientos;
- `EppOperationService`: borrador, lineas, transiciones e idempotencia;
- `EppAcceptanceService`: canonicalizacion, firma, hashes y verificacion;
- `EppBalanceService`: saldos del trabajador y devoluciones parciales;
- `EppKardexService`: lectura historica, Excel y constancia PDF;
- Requests dedicados para entrega, devolucion, firma, incidencia, correccion y anulacion;
- Policies/servicios de alcance por almacen y mina;
- controlador web delgado para cada flujo.

`EppService` continuara atendiendo temporalmente el flujo legado y delegara gradualmente. No se agregaran mas reglas extensas a este archivo ni mas JavaScript EPP inline en `logistica/index.blade.php`.

El canvas de firma se extraera a JavaScript reutilizable, con Pointer Events, escalado por `devicePixelRatio`, soporte tactil, cambio de orientacion, limpieza y restauracion segura del dibujo.

## 12. Seguridad y permisos

Permisos separados:

- ver operaciones;
- preparar entrega;
- confirmar entrega firmada;
- preparar devolucion;
- confirmar devolucion firmada;
- ver firma y evidencia;
- descargar constancia;
- gestionar inventario;
- registrar ajuste;
- corregir/anular firmado;
- aprobar excepcion sin firma del trabajador.

Ver una pestana no debe conceder automaticamente todas las escrituras EPP. Cada ruta valida permiso directo, alcance del almacen/mina y acceso al trabajador. Las busquedas, Kardex, firma y descargas aplican el mismo alcance. La interfaz solo complementa, nunca reemplaza, la autorizacion backend.

## 13. Persistencia propuesta

Nuevas tablas:

- `epp_almacenes`;
- `epp_existencias`;
- `epp_reservas`;
- `epp_inventario_movimientos`;
- `epp_operaciones`;
- `epp_operacion_detalles`;
- `epp_aceptaciones`;
- `epp_operacion_eventos`.

Cambios aditivos:

- `epp_registro`: politica y controles;
- `epp_entregas`: operacion origen, saldo, snapshot de politica e indicador legado.

Los UUID se mantienen como texto. Las migraciones deben comprobar tablas/columnas historicas, crear indices para estado, trabajador, almacen, EPP, firma y fechas, y evitar depender de una base creada desde cero.

Los eventos conservan actor, accion, fecha, origen, motivo y snapshot minimo. No se registran firmas completas ni documentos sensibles en logs de aplicacion.

## 14. Migracion y compatibilidad

1. Crear esquema nuevo de forma aditiva.
2. Marcar entregas existentes como `LEGACY_SIN_FIRMA` y calcular saldo inicial conservador para estados activos.
3. Asignar politica `RETORNABLE` a catalogo existente y exigir revision administrativa.
4. Crear almacenes y registrar apertura de inventario mediante preview y confirmacion.
5. Habilitar nuevas operaciones a un grupo pequeno de Logistica.
6. Mantener lectura conjunta de legado y operaciones nuevas en Kardex/dashboard.
7. Desactivar alta individual antigua cuando el flujo por lotes este validado.
8. Sustituir editar/eliminar por correccion/anulacion.

No se reescribe historial ni se generan firmas retroactivas. El campo `stock` anterior queda solo como compatibilidad durante la transicion y deja de ser fuente de verdad.

## 15. Interfaz movil

La experiencia prioriza uso con una mano y personal con poca experiencia tecnologica:

- buscador de trabajador con identidad visible;
- carrito de EPP con botones grandes para cantidad;
- disponibilidad por variante;
- estados textuales, no solo colores;
- bloque separado para pendientes y sustituciones;
- modo revision del trabajador sin controles administrativos;
- firma a ancho completo y boton claro para limpiar;
- confirmacion final que repite cantidad de lineas y unidades;
- resultado con codigo de constancia y siguiente accion;
- borrador recuperable tras recarga accidental;
- sin tablas horizontales en anchos de 320, 375, 390 y 430 px.

## 16. Pruebas obligatorias

### Dominio y base de datos

- entrega total, parcial y sin stock;
- reserva, expiracion y concurrencia por ultimo stock;
- idempotencia de doble envio;
- sustitucion y atributos snapshot;
- consumible sin saldo retornable;
- devolucion parcial, total y superior al saldo;
- reingreso segun condicion;
- correccion/anulacion sin mutar el original;
- bloqueo de edicion/eliminacion firmada;
- integridad de hashes y archivo faltante;
- legado sin firma;
- alcance por mina/almacen;
- transaccion y limpieza ante fallos.

### HTTP y permisos

- cada endpoint con y sin permiso;
- acceso fuera de scope;
- descargas privadas;
- sesion vencida;
- payload adulterado entre revision y firma;
- firma de trabajador distinto;
- `submission_uuid` repetido.

### Frontend y navegador

- agregar/quitar varias lineas;
- mostrar solicitado, procesado y pendiente;
- invalidar firma al editar;
- Pointer Events tactiles;
- rotacion y redimension del canvas;
- doble toque de confirmacion;
- perdida de red y recuperacion del borrador;
- accesibilidad por teclado y mensajes sin depender del color;
- desktop y anchos moviles definidos.

### Reportes

- Kardex mixto legado/nuevo;
- Excel con verificacion;
- PDF con firma y QR;
- hash coincidente y alterado;
- operaciones corregidas/anuladas visibles.

## 17. Entregables por incremento

### Incremento 1: catalogo, almacenes e inventario

Politica retornable/consumible, variantes, almacenes, apertura, existencias, libro y permisos. No cambia aun la entrega productiva.

### Incremento 2: entrega multiple y firma movil

Borrador, reservas, resumen, firma, idempotencia, entrega parcial, pendientes y constancia.

### Incremento 3: devolucion multiple y saldos

Saldos retornables, devolucion parcial/total, condicion, perdida e incidencias de cese.

### Incremento 4: Kardex verificable y cierre del legado

Kardex unificado, Excel, PDF, verificacion, correcciones, anulaciones y retiro controlado de editar/eliminar.

Cada incremento debe desplegarse con migraciones aditivas, pruebas focalizadas, `php -l`, Pint sobre archivos tocados, `git diff --check`, compilacion Blade/Vite y revision movil.

## 18. Fuera de alcance inicial

- firma remota por enlace/QR en el telefono propio del trabajador;
- operacion offline confirmada sin servidor;
- certificados criptograficos emitidos por una entidad certificadora;
- descuentos automaticos de planilla por perdida o dano;
- serializacion individual obligatoria de todos los EPP;
- compras, ordenes de compra o aprobaciones contables completas;
- fotografia obligatoria de cada entrega.

Estas capacidades pueden agregarse sin cambiar la semantica de lote firmado, detalle procesado, saldo e inventario definida aqui.
