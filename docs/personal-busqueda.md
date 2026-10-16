# Busqueda de Personal

El listado de Personal y el selector de trabajadores de Exportar en Excel
aceptan varios DNI completos separados por espacios, por ejemplo:

```text
77012755 45678923 45685145 65484513
```

Si toda la consulta contiene dos o mas numeros separados por espacios en blanco,
se buscan coincidencias exactas con los que tienen ocho digitos en `dni` o
`numero_documento`. Los numeros incompletos o con mas de ocho digitos no impiden
encontrar los DNI completos: `42419346 7701275` sigue buscando `42419346`.
Se conservan los ceros iniciales y se ignoran los DNI repetidos.
Los demas filtros del listado siguen aplicandose.

Los nombres, puestos, minas, documentos parciales individuales y consultas mixtas conservan
la busqueda textual existente. Un solo DNI conserva tambien ese comportamiento.

El selector de exportacion devuelve todas las coincidencias de la lista de DNI;
la busqueda textual mantiene su limite habitual de sugerencias. Escribir muestra
sugerencias sin seleccionarlas. Pulsar Enter sobre una consulta numerica agrega
al resumen todos los trabajadores encontrados cuyo DNI/documento coincida con
los numeros completos de ocho digitos. Conserva las selecciones anteriores,
evita duplicados e ignora los numeros incompletos. Los nombres mantienen la
seleccion manual. Enter no descarga el archivo: se usa Exportar en Excel.

## Actualizacion del listado

El buscador del listado consulta resultados despues de una pausa de 350 ms;
Enter consulta inmediatamente y limpiar el campo restaura los resultados.
Se actualizan filas, contador y paginacion sin recargar la pagina ni reemplazar
el campo de busqueda. Las peticiones anteriores se cancelan al volver a escribir
y sus respuestas tardias se ignoran. Los filtros siguen presentes en la consulta.
La pagina y el tamano de pagina tambien se actualizan sin recarga.

Las filas se renderizan en el servidor con los mismos permisos de la vista
completa. Ante un error se conserva el ultimo listado y se muestra un mensaje
para reintentar. La URL se actualiza tras recibir resultados validos.
