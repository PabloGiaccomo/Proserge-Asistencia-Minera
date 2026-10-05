# Man Power

Planificacion diaria de grupos, responsables, integrantes y destinos para una parada.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Crear grupo de trabajo | [BPMN](./planificar-grupos-trabajo.bpmn) | [SVG](./planificar-grupos-trabajo.svg) | Crea un grupo BORRADOR o con el estado solicitado, con integrantes iniciales opcionales; las acciones posteriores tienen diagramas propios. |
| Reubicar integrante de Man Power | [BPMN](./reubicar-integrante.bpmn) | [SVG](./reubicar-integrante.svg) | Mueve la distribucion activa entre grupos de la misma parada y fecha; retira pasajero activo de transporte asociado. |
| Cancelar grupos del dia | [BPMN](./cancelar-grupos-dia.bpmn) | [SVG](./cancelar-grupos-dia.svg) | Cancela grupos no cancelados del SAIT, fecha y plan seleccionados cuando ninguno tiene asistencia; retira integrantes y pasajeros activos. |
| Agregar integrante a grupo de Man Power | [BPMN](./agregar-integrante.bpmn) | [SVG](./agregar-integrante.svg) | Agrega una asignacion aprobada para la parada cuando no hay asistencia iniciada ni conflicto de distribucion. |
| Copiar grupo de Man Power | [BPMN](./copiar-grupo.bpmn) | [SVG](./copiar-grupo.svg) | Copia un grupo a fecha/turno destino; omite integrantes inactivos, fuera de rango o con conflicto, y registra resumen. |
| Copiar grupos de un dia | [BPMN](./copiar-grupos-dia.bpmn) | [SVG](./copiar-grupos-dia.svg) | Copia grupos fuente a otra fecha. Por defecto reemplaza los grupos destino del SAIT; omite integrantes no elegibles y devuelve contadores. |
| Copiar grupos a semana o parada | [BPMN](./copiar-grupos-rango.bpmn) | [SVG](./copiar-grupos-rango.svg) | Repite la copia diaria desde el dia siguiente o hoy hasta fin de semana/parada, limitada por el plan. |

## Actores y datos

- Planificador operativo
- Sistema

## Evidencia principal

- `app/Modules/ManPower/Services/GrupoTrabajoService.php`
- `app/Modules/ManPower/Policies/ManPowerPolicy.php`
- `routes/web.php`
- `routes/api.php`

## Duplicidades o inconsistencias

- El servicio conserva metodos modernos y wrappers ForUser de compatibilidad.
- copyDayGroups usa sobrescribir_destino=true por defecto; revisar grupos destino y su asistencia antes de representar copia masiva como inocua.

## Por confirmar

- Regla formal para aprobar un grupo antes de que pase a asistencia.
