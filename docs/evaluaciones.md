# Evaluaciones

## Dashboard

Pestana a la derecha de Residentes. Acceso si se puede consultar al menos un
tipo; solo agrega registros de tipos autorizados, respeta autoria y scope mina.
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

## Evaluacion diaria

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
