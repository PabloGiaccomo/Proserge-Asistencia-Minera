# Evaluaciones

## Tipos y permisos

- `evaluaciones.ver_desempeno`: consulta de evaluaciones diarias.
- `evaluaciones.evaluar_desempeno`: registra evaluaciones diarias.
- `evaluaciones.ver_supervisores`: consulta evaluaciones de supervisores.
- `evaluaciones.evaluar_supervisores`: registra evaluaciones de supervisores.
- `evaluaciones.ver_residentes`: consulta evaluaciones mensuales de residentes.
- `evaluaciones.evaluar_residentes`: registra evaluaciones mensuales de residentes.

Una accion para evaluar incluye la visualizacion del tipo correspondiente. Si el
usuario solo tiene acceso a evaluacion diaria, la pantalla entra directamente a
ese flujo y no muestra navegacion hacia los otros tipos.

## Evaluacion diaria

- Nace de una asistencia cerrada.
- El evaluador debe tener una cuenta vinculada a `personal`.
- Solo el responsable de la asistencia o el usuario que registro sus marcas
  puede evaluarla.
- Solo se evalua personal con estado `PRESENTE` o `TARDANZA`.
- No se permite autoevaluacion.
- Existe una sola evaluacion por detalle de asistencia.
- Cada uno de los cinco criterios se califica de 1 a 4; el total es sobre 20.
- La semana se obtiene de la fecha de asistencia.
- Si se marca incidencia, su descripcion es obligatoria. Si no se marca, no se
  almacena descripcion de incidencia.

## Supervisores

El acceso depende de los permisos especificos y del alcance por mina del
usuario. La identidad del evaluador se obtiene de la cuenta autenticada, no de
un campo editable del formulario.

El supervisor evaluado se busca por nombre, documento o puesto dentro de todo
el personal registrado. La mina conserva el contexto de la evaluacion y el
alcance del evaluador, pero no limita los resultados del buscador.

La ficha se organiza en:

- Bloque A: Competencias Tecnicas (Asociadas al Cargo).
- Bloque B: Desempeno en SSOMA.
- Bloque C: Habilidades blandas.

## Residentes

La evaluacion se registra por residente y mes, sin seleccionar mina. No se
permite duplicar la evaluacion del mismo residente y periodo mensual. La
identidad del evaluador se obtiene de la cuenta autenticada.

El residente evaluado se busca por nombre, documento o puesto dentro de todo el
personal registrado.

El resultado se calcula sobre 20 puntos:

- Indicadores KPI: 1 punto por cada entregable marcado; `Ninguno` vale 0.
- Costos de servicio mensual: 2 puntos por cada entregable; `Ninguno` vale 0.
- Eventos de seguridad: `Si` suma 4 puntos y `No` suma 0.
- Reportes de calidad: `Si` suma 4 puntos y `No` suma 0.
- Liderazgo, gestion e innovacion: escala de 1 a 4 puntos.
- El comentario es obligatorio.
