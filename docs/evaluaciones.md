# Evaluaciones

## Dashboard

Pestana a la derecha de Residentes. Acceso si se puede consultar al menos un
tipo; solo agrega registros de tipos autorizados, respeta autoria y scope mina,
salvo las evaluaciones directas propias registradas fuera del scope del actor.
No concede permisos nuevos ni cambia evaluaciones.

- Filtros por fecha exacta o rango inclusivo, mina y cargo actual de Personal.
- Selector de tipo: Todas, Diaria, Supervisores o Residentes. Solo ofrece tipos
  autorizados y filtra consultas, indicadores, promedios y graficas en conjunto.
- Indicadores: evaluaciones, personas unicas y promedio separado por tipo.
- Diaria/residentes sobre 20; supervisores sobre 100. Puntajes nulos o fuera de
  escala se excluyen del promedio; se muestra el numero de puntajes validos.
- Dona por tipo y barras apiladas de volumen mensual, por mina y por cargo.
- Analisis detallado solo cuando se elige un tipo: perfil de criterios,
  composicion de la nota o aporte ponderado por bloque, distribucion de puntajes
  y evolucion mensual. En supervisores, el detalle de competencias se separa en
  los mismos bloques de la ficha (Bloque A: Competencias Tecnicas, Bloque B:
  Desempeno en SSOMA, Bloque C: Habilidades blandas), cada uno plegable.
- Residentes sin mina, fechados en el primer dia de su periodo mensual.
- No se interpreta un promedio combinado como rendimiento comparable.
- Filtros via fragmento HTML autenticado, con cancelacion de peticiones previas
  y manejo de validacion; no recargan el documento completo.
- Pruebas visuales: tests/Browser/evaluation-dashboard.cjs, con datos ficticios.

## Tipos y permisos

- `evaluaciones.ver_desempeno`: consulta de evaluaciones diarias.
- `evaluaciones.evaluar_desempeno`: registra evaluaciones diarias.
- `evaluaciones.ver_supervisores`: consulta evaluaciones de supervisores.
- `evaluaciones.evaluar_supervisores`: registra evaluaciones de supervisores.
- `evaluaciones.ver_residentes`: consulta evaluaciones mensuales de residentes.
- `evaluaciones.evaluar_residentes`: registra evaluaciones mensuales de residentes.
- `evaluaciones.habilitar_evaluacion_residentes`: habilita el ambito gerencial para evaluar residentes; se requiere junto con `evaluar_residentes`, salvo el privilegio administrativo global.
- `evaluaciones.ver_todas_evaluaciones`: permite consultar evaluaciones registradas por otros usuarios.

Una accion para evaluar incluye la visualizacion del tipo correspondiente. Si el
usuario solo tiene acceso a evaluacion diaria, la pantalla entra directamente a
ese flujo y no muestra navegacion hacia los otros tipos.

Sin `ver_todas_evaluaciones`, cada historial muestra solamente las evaluaciones
registradas por el usuario autenticado. El permiso no habilita tipos de
evaluacion por si mismo y conserva el alcance por mina donde corresponda.

## Evaluacion diaria desde asistencia

- Nace de una asistencia cerrada.
- La pestana diaria muestra los detalles del dia antes de abrir el formulario:
  disponibles con la accion Evaluar, ya evaluados como Evaluado y los demas
  como No evaluable. El conteo de pendientes incluye solo los disponibles.
- La ruta de apertura con `asistencia_detalle_id` vuelve a comprobar permiso,
  autoria, estado de asistencia y detalle, autoevaluacion y duplicado. El
  guardado conserva esas verificaciones y el control transaccional de duplicado.
- El evaluador debe tener una cuenta vinculada a `personal`.
- Solo el responsable de la asistencia o el usuario que registro sus marcas
  puede evaluarla.
- Un usuario administrador con permiso para realizar la evaluacion diaria puede
  intervenir sobre una asistencia cerrada registrada por otro responsable.
- Solo se evalua personal con estado `PRESENTE` o `TARDANZA`.
- No se permite autoevaluacion.
- Existe una sola evaluacion por detalle de asistencia.
- Cada uno de los cinco criterios se califica de 1 a 4; el total es sobre 20.
- La semana se obtiene de la fecha de asistencia.
- Si se marca incidencia, su descripcion es obligatoria. Si no se marca, no se
  almacena descripcion de incidencia.
- El formulario conserva notas y observaciones ante errores de validacion. Al
  guardar, evaluacion y promedio se actualizan en una misma transaccion.

## Evaluacion diaria directa

- En la misma pestana diaria existe un formulario independiente para cualquier
  persona registrada en Personal, sin asistencia ni parada. Requiere seleccionar
  una mina activa; no exige asignacion del trabajador a ella ni scope de mina
  del evaluador.
- Cualquier cuenta con `evaluaciones.evaluar_desempeno` puede registrarla,
  incluso sin vinculo a Personal. La cuenta autenticada queda como autor; si
  tiene Personal vinculado, no puede evaluarse a si misma.
- Conserva los cinco criterios 1..4, incidencia y promedio transaccional. Se
  permiten varias evaluaciones del mismo trabajador en la misma fecha y mina;
  un token de envio unico impide guardar dos veces el mismo formulario.
- El autor puede consultar su evaluacion directa sin scope de mina. Esto no
  amplia la visibilidad de evaluaciones desde asistencia de otros responsables.

## Supervisores

El acceso para crear depende de `evaluaciones.evaluar_supervisores`. Puede
evaluarse a cualquier trabajador marcado como supervisor; no se exige relacion
con el evaluador ni scope de mina. La identidad del evaluador se obtiene de la
cuenta autenticada, no de un campo editable del formulario.

Las cuentas autorizadas pueden registrar evaluaciones de supervisores sin
vinculo a Personal. Para residentes se mantiene la regla de vinculo o privilegio
administrativo.
Se conserva `evaluador_id` cuando hay ficha y siempre se guarda la cuenta
autenticada en `created_by_usuario_id`. Nunca se toma la identidad enviada por
el cliente.
El encabezado muestra el nombre del evaluador o su correo si no tiene ficha.
El historial identifica tambien las evaluaciones registradas solo con cuenta.
La propiedad se determina por la cuenta creadora; para registros historicos sin
esa cuenta, se usa el evaluador de Personal si existe, nunca un vinculo nulo.

Supervisores y residentes pueden evaluarse sin parada, grupo o asistencia.
El supervisor mantiene unidad minera como contexto, no como limite de permiso;
el residente mantiene su periodo mensual sin requerir mina.

La migracion `2026_09_16_000100_repair_evaluation_actor_schema.php` permite
evaluador de Personal nulo y agrega campos faltantes de contexto y auditoria.
No borra filas, no modifica resultados existentes y su rollback conserva datos.

El supervisor evaluado se busca por nombre, documento o puesto dentro de todo
el personal registrado. La mina conserva el contexto de la evaluacion, pero no
limita los resultados del buscador ni a quien se puede evaluar.

La ficha se organiza en:

- Bloque A: Competencias Tecnicas (Asociadas al Cargo).
- Bloque B: Desempeno en SSOMA.
- Bloque C: Habilidades blandas.

## Residentes

La evaluacion se registra por residente y mes, sin seleccionar mina. No se
permite duplicar la evaluacion del mismo residente y periodo mensual. La
identidad del evaluador se obtiene de la cuenta autenticada.

Gerencia selecciona primero residente y mes; el servidor comprueba elegibilidad
y duplicado antes de mostrar la ficha completa. El periodo se guarda como el
primer dia del mes y un indice unico protege `residente_id + periodo_mes`, aun
ante solicitudes simultaneas. La migracion se detiene si detecta duplicados
historicos y no elimina datos.

El residente evaluado se busca por nombre, documento o puesto dentro de todo el
personal registrado.

El resultado se calcula sobre 20 puntos:

- Indicadores KPI: 1 punto por cada entregable marcado; `Ninguno` vale 0.
- Costos de servicio mensual: 2 puntos por cada entregable; `Ninguno` vale 0.
- Eventos de seguridad: `Si` suma 4 puntos y `No` suma 0.
- Reportes de calidad: `Si` suma 4 puntos y `No` suma 0.
- Liderazgo, gestion e innovacion: escala de 1 a 4 puntos.
- El comentario es obligatorio.

## Carga historica unica

La pantalla existente de Evaluaciones incluye `Cargar datos historicos` para
usuarios con `evaluaciones.administrar`, mientras la carga este habilitada y no
exista un lote confirmado. Las rutas de carga y reporte validan ese permiso en
backend. Los permisos para evaluar diariamente no autorizan una importacion.

Alcance vigente (2026-10-06): se carga solamente `Proserge Control Asistencia.xlsx`.
Se leen `EV SUPERVISORES` y `PERSONAL` como diccionario ID -> DNI. No se importan
asistencias, evaluaciones diarias, residentes ni el Consolidado. Las rutas web
solo llaman `previewSupervisors` y `confirmSupervisors`; un preview antiguo de
alcance mixto debe revisarse nuevamente, no puede confirmarse por este flujo.
Los lectores genericos permanecen para compatibilidad y pruebas del historial
anterior; no constituyen una opcion de carga expuesta al usuario.

Los snapshots DATA 2025 no se importan ni actualizan Personal. Son evidencia
auxiliar para corregir documentos desconocidos antes de volver a revisar los
libros principales. El importador nunca crea personas por nombre.

El preview valida DNI de ocho digitos contra Personal. Los IDs internos del
libro se traducen por su hoja PERSONAL. Si el mismo ID indica dos DNI, se
bloquea la carga. No se adivinan ceros iniciales perdidos ni identidades por
parecido. Las diferencias de nombre se muestran como advertencias ubicadas por
DNI, hoja y fila; la identidad resuelta sigue siendo el DNI.

La revision tambien consulta UUID exactos de `personal.id` en lotes de 500 y
resuelve su DNI registrado, tanto para trabajador como para evaluador. Si la
hoja PERSONAL y el ID actual indican documentos distintos, se mantiene el
bloqueo. Se normalizan separadores, apostrofo de Excel, decimales cero y
notacion cientifica que represente exactamente ocho digitos; no se completan
ceros ni se extraen numeros de textos libres. La referencia original permanece
visible en la revision y el reporte. Una coincidencia exacta de nombre solo
se sugiere (hasta cinco opciones), nunca asigna ni desbloquea la fila.
Los previews anteriores deben revisarse nuevamente con los archivos originales;
la confirmacion sigue revalidando la identidad y rechaza manifiestos obsoletos.

La pantalla pagina de 50 en 50 y permite filtrar cambios propuestos, errores,
advertencias y filas sin cambios, por tipo y por DNI/nombre/hoja/motivo. El
reporte CSV identifica las filas y evita que sus textos se interpreten como
formulas al abrirlos en Excel. Archivos y manifiestos permanecen en el disco
privado `local`; el reporte solo se descarga con permiso administrativo.

Los registros historicos anteriores de otros tipos mantienen su estructura;
esta carga no los crea, elimina ni modifica. No crea Personal ni asignaciones.

Supervisores conservan la nota original del Excel con
`calculo_version=HISTORICA`, lote, fila, clave unica y respuestas originales.
No se recalcula ese total al importar. Admite total 0..100 y respuestas
presentes 1..5. Una respuesta fuera de rango es bloqueante. Una celda de criterio
vacia es una excepcion historica: se conserva el registro con
`estado=POR_REGULARIZAR`, se muestra una advertencia y no participa en ningun
indicador, promedio o grafico (ni en Evaluaciones ni en el Dashboard general).
La nota original sigue disponible como historial. No se inventan calificaciones.
Un ID/fila de origen ya importado se omite solo si coincide; no se sobreescribe.

Confirmar requiere cero bloqueantes, revisar el reporte y marcar la aceptacion.
Una revision sin cambios propuestos no puede cerrar la carga. Las evaluaciones
historicas completas no se recalculan mediante la edicion habitual.
Se vuelve a comprobar hash, identidad, contenido y unicidad. El lote, las
evaluaciones de supervisor se guardan en una sola transaccion. No se escriben
asistencias, evaluaciones diarias/residentes ni promedios diarios. Se bloquean
trabajadores en orden y una clave UNIQUE permite un
solo lote definitivo, incluso entre solicitudes competidoras. Un fallo revierte
todas las escrituras. Una repeticion no crea otra carga.

Tras confirmar se retira el boton y se rechazan nuevos previews/confirmaciones
incluso por URL directa; el reporte sigue disponible para administradores. Las
evaluaciones historicas completas cuentan desde la confirmacion; las pendientes
quedan excluidas hasta regularizar. El historial
sin mina se muestra a lectores con `ver_todas_evaluaciones`, no a quienes solo
leen sus propias evaluaciones. Filtrar una mina concreta excluye esos registros.
El filtro Origen del dashboard afecta todos sus KPI, graficos y resultados.
Las notas antiguas se etiquetan; la reconstruccion de aportes ponderados de
supervisor se limita a la formula vigente y muestra su cobertura de datos.

### Seguimiento y regularizacion

En la misma pestana Supervisores, el historial ahora tiene busqueda por DNI o
nombre, filtros Todas/Pendientes/Regularizadas y paginacion de 20 registros. No
se limita a los 20 registros mas recientes, para que los pendientes antiguos
puedan encontrarse. Se muestran criterios faltantes, motivo, fecha y revisor.

`Regularizar` reutiliza el formulario de los 26 criterios. Requiere
`evaluaciones.administrar` y `editar`/`actualizar`, ademas del acceso al registro;
GET, POST y servicio vuelven a validarlo. No obliga a inventar mina o parada.
`Guardar seguimiento` permite documentar por que sigue pendiente, sin habilitar
indicadores ni cambiar notas. Es una accion independiente del guardado completo.
Solo admite registros historicos pendientes; no habilita edicion de historicos
completos, ni permite reabrir una regularizacion ya completada.

Al regularizar se exigen las 26 notas 1..5 y se calcula el resultado con la
plantilla actual. Se cambia a `REGISTRADA` / `calculo_version=REGULARIZADA`, y
cuenta en indicadores, conservando Origen historico. Las celdas originales del
Excel permanecen en `source_payload`; la clave `_regularizacion` contiene la
bitacora de seguimiento (actor, fecha, motivo y criterios pendientes). No se
requiere otra tabla ni migracion. Un bloqueo de fila y transaccion impiden que
dos intentos regularicen el registro dos veces. Las historicas completas
conservan siempre su nota original sin recalculo.

El enlace `Descargar reporte` permanece en Evaluaciones despues de confirmar,
aunque el flag de carga se cierre. El CSV conserva la revision original y agrega
estado actual, criterios pendientes, bitacora, nota actual y version de calculo.
El reporte no habilita otra importacion ni permite saltar los bloqueantes.

Todos los criterios de evaluaciones nuevas son obligatorios: diaria 5 notas
1..4, supervisor 26 notas 1..5, residente todos sus componentes. Las escalas y
formulas no cambian. Los formularios conservan valores tras un error, muestran
el criterio faltante y bloquean visualmente el doble envio. Request y servicio
de supervisor rechazan criterios ausentes, nulos, fuera de rango o desconocidos.

### Despliegue y ensayo

1. Respaldar MySQL y `storage/app/private`. Ensayar sobre una copia aislada;
   nunca dirigir PHPUnit a datos reales. Las pruebas usan `proserge_app_test`.
2. Desplegar los cambios y las migraciones aditivas de Evaluaciones
   `2026_10_05_000100` a `2026_10_05_000500_guard_one_confirmed_historical_import`.
   Revisar todas las migraciones pendientes antes de ejecutar una migracion
   general: otros modulos pueden tener archivos de la misma fecha.
3. Ejecutar las migraciones seleccionadas y `php artisan optimize:clear`.
4. Habilitar `EVALUACIONES_HISTORICAL_IMPORT_ENABLED=true` en el ambiente de la
   carga y limpiar cache de configuracion. Por defecto el flag es false.
5. Desde Evaluaciones, revisar el archivo Control; descargar el reporte y resolver
   todos los bloqueantes. Revisar las advertencias de criterios faltantes.
   Ninguna evaluacion definitiva se escribe durante el preview.
6. Confirmar manualmente una sola vez. Comparar guardados con cambios propuestos
   (las filas sin cambios no se insertan), verificar pendientes y
   dashboard filtrando fechas antiguas y Origen = Carga historica.
7. Poner el flag en false y limpiar cache. Comprobar que el boton desaparecio,
   que otra carga es rechazada y que el reporte permanece protegido.

El archivo admite hasta 20 MB; verificar limites PHP de subida,
memoria y tiempo en cPanel antes del ensayo de volumen.
El lector carga las hojas seleccionadas en memoria, no en streaming. El limite
de subida no acota la memoria descomprimida; ensayar los libros reales en una
copia aislada antes de habilitar el flag en produccion.
No iniciar confirmacion durante un despliegue o reinicio del servidor.
No se ha confirmado ningun lote
de informacion real como parte del desarrollo.

Las migraciones conservan historial en `down()`: un rollback de Laravel no
borra la carga ni reconstruye informacion anterior. Ante un error de datos
confirmados, cerrar el flag y aplicar un procedimiento auditado de correccion
o restauracion desde backup; no borrar tablas ni repetir una importacion ciega.
Mantener archivos/reportes privados hasta verificar los conteos y cerrar la
auditoria de la carga; su eliminacion posterior debe acordarse operativamente.
