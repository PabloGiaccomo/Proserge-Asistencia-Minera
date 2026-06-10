# Componentes, servicios y patrones reutilizables

## Servicios reutilizables

- `PersonalService`: alta, actualizacion y estado laboral. Usarlo para evitar
  saltos indebidos a `ACTIVO`.
- `PersonalFichaService`: ficha publica, links, documentos de ficha,
  observacion, aprobacion y regularizacion.
- `PersonalContratoService`: contrato laboral, renovacion, reingreso, cierre,
  anulacion, no renovacion y snapshots.
- `PersonalDocumentoDownloadService`: descarga documental ZIP.
- `PersonalAntiguoService`: personal antiguo y regularizacion.
- `PersonalMinaHabilitacionService`: trabajador-mina, requisitos, examenes,
  intentos y estados de habilitacion.
- `PersonalMinaExcelImportService`: master Excel de habilitacion.
- `PersonalNormalizer`: nombres, documentos, telefonos, fechas, contratos.
- `PersonalFichaCatalog`: campos, documentos, declaraciones y labels de ficha.
- `PermissionMatrix`: evaluar permisos en backend y vistas.
- `NotificationService`: emitir eventos de notificacion.
- `DisponibilidadPersonalService`: disponibilidad al asignar personal.

## Componentes Blade existentes

- `components/ui/badge.blade.php`: badges.
- `components/ui/btn.blade.php`: botones.
- `components/ui/card.blade.php`: tarjetas.
- `components/ui/data-table.blade.php`: tablas.
- `components/ui/empty-state.blade.php`: estados vacios.
- `components/ui/global-search.blade.php`: busqueda global.
- `components/ui/simple-search.blade.php`: busqueda simple.
- `components/layout/page-header.blade.php`: encabezados.

Estos componentes aun no se usan de forma consistente en las vistas grandes.
Antes de crear nuevos estilos, revisar si se pueden adaptar.

## Patrones a extraer

- Badge de estado laboral.
- Badge de estado documental.
- Badge de estado contractual.
- Badge de estado de habilitacion.
- Tabla tipo Excel con filtros por columna, paginacion y contador.
- Modal de confirmacion con motivo obligatorio.
- Panel de acciones agrupadas.
- Toast operativo con resumen de importacion.
- Selector de trabajador por nombre, DNI y puesto.
- Timeline de historial laboral/procesos.
- Visualizador de documento/archivo con descarga.
- Resumen de importacion con creados, actualizados, omitidos y errores.
- Componente de advertencia operativa.
- Control de "siguiente accion esperada".

## Patrones de historial

Crear un patron comun para:

- fecha del evento;
- usuario responsable;
- origen del evento;
- estado anterior/nuevo;
- observacion;
- snapshot antes/despues cuando aplique;
- referencia al archivo o documento;
- indicador de evento manual, importado o automatico.

Aplicable a contratos, cese, habilitacion minera, examenes, documentos,
asistencia, faltas, importaciones y archivado.

## Importadores

Patron recomendado:

1. Preview sin escribir datos.
2. Resumen de hojas/columnas detectadas.
3. Mapeo explicito de campos.
4. Advertencias por datos faltantes o ambiguos.
5. Confirmacion.
6. Ejecucion idempotente.
7. Resultado con creados, actualizados, omitidos, duplicados y errores.
8. Log tecnico para diagnostico.

No asumir que un Excel historico es fuente de verdad. Preguntar si actualiza,
crea o solo compara.

## Archivado y recuperacion

Componentes futuros reutilizables:

- tabla de archivos archivados por trabajador/proceso;
- servicio de mover archivo a almacenamiento secundario;
- servicio de restaurar archivo;
- checksum/hash de archivo;
- politica de retencion;
- evento de auditoria de archivado/restauracion;
- indicador visual "archivado, recuperable".
