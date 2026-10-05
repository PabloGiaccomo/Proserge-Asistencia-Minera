# Organizar examenes y nombres alternativos

Fecha: 2026-10-05. Estado: propuesta para revision del usuario.

## Objetivo y alcance

Dar al responsable una vista dentro de Habilitacion minera para corregir la
organizacion del catalogo, reconocer nombres alternativos del Excel y evitar
nuevos duplicados. Debe funcionar igual en local y en el hosting desplegado por
FTP. Solo afecta al catalogo y su uso en habilitacion minera; no modifica Personal,
contratos, documentos ni estado laboral.

El usuario confirmo que singular/plural de espacio confinado representan el mismo
curso y que existen otros nombres incorrectos. Esta confirmacion no implica que
todo nombre parecido sea un examen equivalente, ni autoriza borrar historial.

## Evidencia y capacidades existentes

El diagnostico local encontro 50 registros de catalogo y candidatos como
izage/izaje, espacio confinado/espacios confinados y variantes de trabajo en
caliente. Hay configuraciones de convalidacion diferentes entre variantes.

MiningExamCatalogAuditService ya consulta nombres, requisitos, resultados,
intentos, cursos y precios, y sugiere coincidencias por distancia de texto.
El endpoint de diagnostico requiere habilitacion_minera/configurar.
PersonalMinaBulkPreviewService resuelve actualmente nombres exactos normalizados.
La convalidacion existente exige confirmacion y permite equivalencias por
requisito destino; no debe reemplazarse con una aprobacion automatica.

## Alternativas

1. Recomendado: identidad principal y nombres alternativos, conservando los IDs
   historicos. Reduce riesgo, conserva snapshots y evita mover miles de intentos.
2. Fusion fisica de registros: mueve requisitos e historial, requiere resolver
   colisiones y diferencias de reglas. No se incluye en esta primera version.
3. Solo renombrar: no resuelve IDs duplicados ni la lectura de variantes del Excel.

## Pantalla y flujo

Acceso: Habilitacion minera > Acciones > Organizar examenes.

- Busqueda por nombre, filtros activos/historicos y posibles duplicados.
- Tabla con nombre, minas que lo requieren, referencias a resultados/intentos y
  nombres alternativos reconocidos. No mostrar datos personales para agrupar.
- Comparacion de candidatos: nombre principal, variantes, reglas distintas y
  referencias existentes. El usuario puede agregar candidatos no sugeridos.
- Acciones: corregir nombre visible, asociar variantes al examen principal o
  marcar el caso como pendiente. No hay boton de borrar historial.
- Elegir principal y revisar impacto antes de confirmar. Se muestran los nombres
  antiguos que seguiran reconocidos y las minas que conservaran sus requisitos.
- Guardar exige motivo y confirmacion. Presentar resultado y siguiente accion.
- Buscar parecido solo sugiere; nunca guarda ni convalida por si solo.

Las reglas diferentes por mina permanecen visibles. Asociar nombres no modifica
vigencia, nota minima, precios ni permisos de convalidacion.

## Datos y reglas de identidad

Una tabla de asociaciones registra examen variante y examen principal, con actor,
fecha y motivo. Una tabla de nombres alternativos conserva texto original y clave
normalizada, con alcance de mina opcional. Se agrega historial de cambios con
snapshot anterior/posterior y actor. Migraciones incrementales, sin backfill que
infiera equivalencias automaticamente.

Cada examen tiene como maximo un principal. No permitir ciclos ni cadenas:
las asociaciones apuntan al principal final. Las asociaciones incompatibles o
ambiguas se rechazan. No borrar registros antiguos, sus intentos ni precios.
Los examenes existentes conservan IDs y snapshots; la interfaz presenta el grupo
principal sin ocultar las variantes necesarias para operar requisitos historicos.

El resolver compartido de nombres se usa en el importador masivo y el Master.
Primero resuelve nombre exacto y alias confirmado; despues usa la mina destino
para elegir el requisito activo perteneciente al grupo. Si quedan dos requisitos
posibles de la misma mina, muestra conflicto y requiere resolverlo: no elige el
primero. Si falta un alias, muestra candidatos para la revision administrativa.
No crear automaticamente un examen nuevo por un nombre parecido.

Reconocer el mismo curso no convalida resultados automaticamente. Para sugerir
convalidacion se admiten variantes del grupo, solo para el mismo trabajador y
respetando permiso, alcance de mina, vigencia y nota del requisito destino.
La confirmacion conserva origen/destino y snapshot como en el flujo existente.

Un historial sin mina de origen sigue siendo un caso separado. La pantalla de
organizacion no inventa su procedencia ni lo asigna masivamente. Su conciliacion
con el Excel requiere una accion explicita posterior; no forma parte de fusionar
nombres.

## Arquitectura y seguridad

- Controlador dedicado del modulo Personal, delgado, con GET, preview y confirm.
- Servicio de organizacion para transacciones, auditoria y locks ordenados.
- Resolver compartido para identidad y nombres, independiente del texto Blade.
- Reutilizar el diagnostico existente y ampliar comparaciones de reglas.
- Vista Blade y modulo JS propio; estilos dentro del build actual.
- Lectura y escritura requieren web.auth y habilitacion_minera/configurar, CSRF y
  validacion backend. Permisos no dependen de ocultar botones.
- Confirmacion revalida snapshot/fingerprint y rechaza catalogo cambiado desde
  preview. Cambios auditados y transaccionales; ninguna escritura en GET.

## Local y FTP

No depender de rutas Windows, Python, Graphify, Node en produccion ni ejecutables
de backup. Los assets se compilan en local y se suben con manifest y archivos
generados. Rutas y enlaces se generan con Laravel, sin localhost fijo.

FTP no actualiza MySQL. Entregar migraciones y SQL equivalente revisado para
instalarlas mediante terminal cPanel o phpMyAdmin, segun herramientas disponibles.
No exponer un endpoint web de migraciones ni ejecutar DDL al abrir la pantalla.
Si falta el esquema, mostrar aviso operativo y deshabilitar escrituras.

Las asociaciones se guardan en la base de cada ambiente. Subir el codigo no
sobrescribe la base productiva con la local; el responsable revisa y confirma los
grupos desde la misma vista en cada ambiente. Backup previo a migraciones y a
correcciones masivas; no incluir backups privados en el paquete FTP.

## Validacion y entrega

Pruebas sobre MySQL aislado: permisos, aliases por mina, variantes singular/plural,
typos confirmados, ambiguedad, ciclos, catalogo cambiado, diferencia de reglas,
preservacion de historial y convalidacion con/sin nota.
Verificar importaciones de ambos formatos y reimportacion sin duplicados.
Browser desktop/mobile: filtros, comparacion, preview y confirmacion, errores y
ausencia de solapamientos. PHP lint, view:cache, build y diff check.

Actualizar documentacion canonica de reglas y despliegue. Entregar lista exacta
de archivos FTP, requisito de esquema y comprobaciones posteriores.

## Criterio de aceptacion

Desde la vista, un usuario autorizado puede asociar variantes confirmadas sin
perder referencias, y un Excel posterior reconoce esas variantes en la mina
correcta. Cursos distintos permanecen separados y no se aprueba a nadie por
normalizar un nombre. El mismo comportamiento funciona sin herramientas locales
en el hosting, despues de instalar el esquema y subir el build.
