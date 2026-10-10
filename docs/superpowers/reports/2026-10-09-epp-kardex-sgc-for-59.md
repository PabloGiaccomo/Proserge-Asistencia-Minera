# Kardex Excel SGC-FOR-59: formato y periodo

## Firma unica al pie (correccion posterior confirmada 2026-10-09)

El usuario confirma que la columna Firma no debe tener dibujos en las filas.
`EppKardexExcelService` elimina esas inserciones y mantiene solo la ultima
firma valida del trabajador relacionada con los EPP de la hoja dentro del
rango, en el recuadro inferior. Filas compactas de 22 puntos. No cambia la
agrupacion por fecha/movimiento/lote, las cantidades, permisos, alcance ni
archivos privados. Las constancias individuales conservan su evidencia.

Regresion roja: una firma esperada frente a dos dibujos existentes. Tras el
cambio, se verifica una sola imagen anclada en AE34, tambien despues de
guardar/reabrir XLSX; fondo blanco, seleccion de ultima aceptacion dentro del
rango, exclusion de fecha posterior y hashes originales intactos. Se fijan
todos los timestamps de la fixture para no depender del reloj.

Se actualizan EppKardexPresentationTest y las expectativas de dibujo en
EppExistingWorkflowTest; los controles de agrupacion y datos permanecen.
53 pruebas focalizadas / 548 assertions pasan, incluidos tres cascos y dos
chalecos compactados en tres filas identicas en pantalla y Excel, movimientos
y lotes separados, cantidades grandes paginadas y evidencia invalida.
PHP lint, Pint de exportador/presentacion y git diff --check pasan. Revision
independiente sin defecto de produccion en este ajuste; se corrigio el reloj
de la fixture senalado por el revisor. No cambian Blade ni assets compilables;
sin migraciones, usuarios reales, commit o despliegue.

El recuadro inferior conserva sus dimensiones/posicion anteriores. Se verifica
el XLSX guardado estructuralmente; no se reviso visualmente en Excel nativo en
esta correccion. Las dos hojas conservan su pie independiente cuando contienen
articulos con firma aplicable. La evidencia de un lote no certifica otros.
Suite global final: 1351 pruebas / 10866 assertions, 41 fallos y 7 errores,
263.55 segundos. No es una suite verde. Conserva los 41 casos previos listados
en `2026-10-09-role-permission-checkboxes.md` y observa siete casos adicionales:

- Tests.Feature.LogisticaDashboardServiceTest::test_dashboard_scope_counts_only_active_or_enabled_workers_and_splits_expirations
- Tests.Feature.PersonalMinaEmoBulkTest::test_confirmation_rejects_change_after_preview_and_rolls_back_whole_file
- Tests.Feature.PersonalMinaEmoBulkTest::test_mid_file_failure_rolls_back_every_appointment_and_attempt
- Tests.Feature.PersonalMinaEmoSchedulingTest::test_other_clinic_is_rejected_without_partial_attempt_or_appointment
- Tests.Feature.PersonalMinaEmoSchedulingTest::test_completes_only_linked_mines_with_exact_medical_outcome with data set #1
- Tests.Feature.PersonalMinaEmoSchedulingTest::test_cancelled_appointment_is_reprogrammed_on_same_identity_with_audited_values
- Tests.Feature.PersonalMinaEmoSchedulingTest::test_completion_from_stale_cancelled_and_reprogrammed_context_is_rejected

Seis errores son deadlocks MySQL al crear asignaciones de Personal/Mina; el
fallo de Dashboard es contador esperado 2/real 3. La repeticion focalizada
de esos tres archivos termina con 69 pruebas / 525 assertions, 1 fallo de
contador y 6 errores de deadlock en los siguientes casos (no todos coinciden
con los de la corrida global):

- Tests.Feature.PersonalMinaEmoSchedulingTest::test_completion_from_stale_cancelled_and_reprogrammed_context_is_rejected
- Tests.Feature.PersonalMinaEmoSchedulingTest::test_regularizing_new_legacy_link_invalidates_previous_appointment_context
- Tests.Feature.PersonalMinaEmoSchedulingTest::test_scheduling_evidence_is_removed_when_enclosing_transaction_rolls_back
- Tests.Feature.PersonalMinaEmoSchedulingTest::test_result_cannot_be_future_or_before_appointment_and_rejects_future_schedule_today
- Tests.Feature.PersonalMinaEmoSchedulingTest::test_existing_mining_scheduling_entry_point_preserves_uploaded_evidence
- Tests.Feature.PersonalMinaEmoSchedulingTest::test_direct_result_registration_is_atomic_and_does_not_emit_a_pending_schedule_notification
- Tests.Feature.LogisticaDashboardServiceTest::test_dashboard_scope_counts_only_active_or_enabled_workers_and_splits_expirations

No se modificaron esos servicios ni reglas para ocultar estos resultados.
La causa de los bloqueos/contador requiere investigacion separada; no se
afirma que la suite global este sana. Las 53 regresiones del exportador,
agrupacion y evidencia pasan sin fallos ni errores.
Las secciones siguientes conservan
el registro de las revisiones anteriores; la regla de firma por fila queda
sustituida por esta correccion.

## Paginacion de pantalla y retiro del bloque tecnico

La tabla Kardex consulta paginas desde el servidor despues de agrupar por
fecha, movimiento y lote. Limites permitidos: 20 (predeterminado), 50 y 100;
numeracion continua, contador de rango/total y Anterior/Siguiente. Al elegir
otro trabajador se restablece pagina 1/20. Historial vacio: 0–0 de 0; una pagina
posterior al final se ajusta a la ultima disponible. Valores invalidos reciben
422. Se conservan permisos y alcance por trabajador/mina/almacen.

La respuesta ya no incluye entregas crudas ni el listado tecnico de operaciones,
UUID, estados internos y hashes. No se borra evidencia o historial. Tampoco se
verifican archivos de firma ni se crean auditorias de evidencia al paginar.
El Excel mantiene todos los movimientos del rango, independiente de la pagina.

Para agrupar y contar, el servidor aun examina todos los metadatos del trabajador:
no se presenta como un LIMIT SQL de unidades. Lee operaciones/legado por bloques
de 100 y el agrupador indexa rangos de cantidades, materializando solo las filas
pedidas. La regresion de una cantidad de 100 millones genera solo las tres filas
de su ultima pagina, sin expandir todo ese volumen. No se cambia el esquema.
La lectura por bloques no es un snapshot ante escrituras concurrentes.

Pruebas focalizadas iniciales: 70 aprobadas / 626 aserciones. Comprobacion
adicional de limites, historial vacio y alcance: dos pruebas / 51 aserciones
aprobadas (incluye repetir el caso de agrupacion/Excel).
Prueba de navegador sintetica aprobada: parametros 20/50/100, contador, ultima
pagina, numeracion, ausencia del bloque tecnico, rango de descarga completo,
reintento tras HTTP 500 y respuesta tardia descartada al cambiar trabajador
incluso sin AbortController. Revision de anchos 320/375/756/1440 y capturas
desktop/mobile sin solapamientos. Todas las solicitudes estan interceptadas;
no se opero una sesion real ni se registraron movimientos reales.
Revision independiente aprobada sin hallazgos pendientes dentro del alcance.
Sintaxis PHP, Pint y compilacion de vistas aprobadas. No se modifican assets
compilables ni se requieren npm/build o migraciones para este ajuste.

Suite general final: 1340 pruebas, 8464 aserciones, 40 fallos y un error.
Dentro de esa ejecucion, las 71 pruebas focalizadas pasan (640 aserciones).
Los 41 casos coinciden exactamente con `devolucion-legend-full.xml`: no hay
fallos adicionales; sus nombres estan enumerados mas abajo en este informe.
Registro: `.superpowers/kardex-pagination-full.xml`.
Comando: `php -d memory_limit=1024M vendor/bin/phpunit --no-output --log-junit .superpowers/kardex-pagination-full.xml`.
El limite ampliado se aplica solo al proceso de pruebas para evitar el
agotamiento de 512 MB observado en la ejecucion anterior. No se modifica
la configuracion de la aplicacion o produccion.

## Agrupacion por fecha, movimiento y lote firmado

Esta regla reemplaza la distribucion anterior de una fila independiente por
cada unidad: pantalla y Excel comparten el lector de movimientos y el agrupador.
EPP distintos del mismo lote, fecha y codigo comparten fila; tres cascos y dos
chalecos producen tres filas (dos con ambos articulos y una solo con casco).
Las unidades que corresponden a una misma columna siempre ocupan filas
distintas, incluso si provienen de modelos o nombres diferentes. No se mezclan
fechas, codigos N/C/I/P/D, lotes ni registros antiguos sin lote identificable.
La firma se dibuja una vez por fila cubierta; se conserva la seleccion de la
ultima firma valida del trabajador por fecha y la evidencia original intacta.
El agrupador entrega filas compactadas antes de cualquier paginacion; esta
correccion no implementa paginacion ni modifica otros flujos pendientes.

Verificacion focalizada: 68 pruebas / 583 aserciones aprobadas, incluyendo
cantidades desiguales, devoluciones parciales, lotes diferentes y dos modelos
que comparten columna. Las regresiones nuevas fallaron antes de corregirse.
Revision independiente: detecto la discrepancia de alias pantalla/Excel, luego
confirmo su correccion sin nuevos hallazgos dentro del alcance revisado.
Pint, sintaxis PHP de archivos tocados y `git diff --check` aprobados.
Exportacion sintetica sobre base aislada, con rollback, y vista previa XLSX
revisadas. No se verifico impresion en Excel nativo. No requiere migraciones
ni recompilacion de assets: los cambios funcionales son servicios PHP.
Prueba de navegador sintetica aprobada: filas con N en dos columnas,
ultima unidad en una sola columna y devolucion D separada; controles de rango
y anchos 320/375/756/1440 px. Todas sus solicitudes estan interceptadas y no
usan una sesion real ni registran movimientos en la aplicacion.
La suite general de esta correccion no concluyo: PHP alcanzo 512 MB durante
la escritura ZIP de PhpSpreadsheet (zipstream-php/File.php:334). Su JUnit
quedo vacio y no permite comparar fallos con la ejecucion historica de abajo;
no se afirma que la suite completa este aprobada. No se cambio el limite de
memoria de la aplicacion ni su configuracion de produccion.
Comprobacion ampliada `php artisan test --filter 'Epp.*Test'`: 306 aprobadas,
1 fallo, 2228 aserciones. El unico fallo es el ya registrado
`LogisticaEppIntegrationTest::test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente`
(espera el texto `Subir catalogo`), ajeno a la agrupacion Kardex. Todas las
pruebas seleccionadas de EPP/Kardex concluyeron; no se modifico Herramientas.

## Ajuste visual de la leyenda de devolucion

La leyenda D se limita a AA8:AD9, sin invadir AE (firma). P usa W8:Y9
para dejar Z como separacion. Se conservan alturas, tipografia, fondo blanco
de las descripciones, fondo gris de los codigos y el resto de la plantilla.
La etiqueta visible es `Devolución`.

Verificacion: prueba de regresion primero fallida, despues aprobada;
33 pruebas focalizadas aprobadas y nueva revision final de presentacion
con 2 pruebas/40 aserciones. PHP syntax, Pint y `git diff --check` aprobados.
Exportacion sintetica en base aislada y vista previa del XLSX revisadas.
No se verifico impresion en Excel nativo. No requiere build ni migraciones.

Suite general repetida: 1332 pruebas, 8356 aserciones, 40 fallos y un error.
El conjunto de 41 casos coincide exactamente con el registro anterior
`kardex-presentation-full.xml`; sus nombres estan enumerados mas abajo
(el fallo intermitente de reloj alli documentado no reaparecio).
Registro actual: `.superpowers/devolucion-legend-full.xml`.

## Correccion posterior solicitada por el usuario

Esta seccion reemplaza la presentacion descrita en la entrega inicial de abajo:

- Codigos exclusivamente N/C/I/P/D; sin cantidad concatenada ni asteriscos.
- Una fila por unidad con la fecha real del movimiento: cantidad tres genera
  tres filas, no tres fechas inventadas. El rango sigue siendo inclusivo.
- Solo encabezados EPP presentes en los movimientos del trabajador del rango.
- Sin bloque tecnico adicional debajo de la declaracion original del formato.
- Firma valida del trabajador sobre fondo blanco en las unidades de su lote;
  ultima firma aplicable de la fecha, nunca de un testigo ni en filas legacy.
  Recuadro inferior con ultima firma relacionada con esa pagina en el periodo.
  Se transforma solo la copia exportada, no la evidencia privada ni sus hashes.
- Declaracion completa antes del lienzo de firma; texto/version congelados en
  nuevas entregas y visibles en su constancia. No se modifica evidencia previa.
- Mina filtra por paradas RQ solapadas con la vida de uso de la entrega y solo
  muestra sus periodos/dias, conservando autorizacion y alcance por usuario.
- Busquedas de Logistica por palabras en cualquier orden, parciales, acentos,
  espacios adicionales y DNI; sin ampliar filtros o permisos.

Archivos adicionales: `EppDeliveryDeclaration`, parcial de declaracion,
`SearchTerms`, servicios EPP/Logistica y sus regresiones. No se requieren nuevas
dependencias o migraciones para esta correccion. No se escribieron datos reales.

La revision externa de codigo no pudo finalizar por limite de uso del revisor;
no se presenta como aprobada. Se realizo revision propia y verificacion local.

Verificacion de esta correccion:

- Modulo EPP/Logistica: **317 pruebas, 2259 aserciones, cero fallos/errores**.
- Cierre de regresiones con el codigo final: **16 pruebas, 212 aserciones,
  cero fallos/errores**, incluyendo colores legibles en fechas, cantidades
  expandidas, firmas PNG transparentes serializadas, buscadores, DNI y mina.
- Suite general: **1332 pruebas, 8333 aserciones, 40 fallos y un error**.
  Todos los 41 casos ya estaban en el registro previo; no hay casos nuevos.
  La prueba intermitente del reloj de Personal paso en esta ejecucion.
- Chrome sintetico: revision/firma 320/375/390/430/1440 px, controles de rango
  Kardex y busqueda real de paradas con palabras invertidas y filtro de mina.
- PHP syntax, Pint, compilacion Blade y `git diff --check`: correctos.
- Vista previa del XLSX generado con tres unidades y fondo transparente de
  origen: tres filas N, fechas negras, firma visible y recuadro inferior.
- No se recompilaron assets: esta correccion modifica PHP y Blade/JS inline.
  La comprobacion de impresion en Excel nativo queda pendiente; la revision
  visual fue con el renderizador y Chrome sintetico, no con sesion real.

Habilidades aplicadas: diagnostico sistematico para rastrear el exportador,
brainstorming para concretar los requisitos aprobados, TDD para reproducir los
defectos antes de corregir, hojas de calculo para inspeccion visual de la
plantilla sin reemplazar PhpSpreadsheet, verificacion antes de completar y
sincronizacion de documentacion. La solicitud de revision externa se registro
arriba con su limitacion; no sustituye las comprobaciones ejecutadas.

Uso local: recargar Logistica y generar una nueva descarga. Los archivos ya
descargados no se reescriben. Para publicar esta correccion, incluir servicios,
Support, Blade y plantilla existente; no ejecutar migraciones por estos cambios.

## Entrega inicial (referencia historica; presentacion reemplazada arriba)

## Alcance

Restaurar la descarga operativa sin redisenar Entregas y cambios ni afectar
inventario, firmas originales o datos reales. Plantilla original conservada en
`resources/epp-templates/SGC-FOR-59.xlsx` (SHA-256
`EF864883929E8435409B5271CCCD2F7B657A65697177C74912A94D64C190E9EE`).

## Archivos y comportamiento

- `DownloadEppKardexRequest`: fechas validas e inclusivas; por defecto mes
  calendario anterior completo, rango invertido rechazado.
- `EppKardexExcelService`: carga la plantilla, conserva Anterior/Posterior,
  agrupa movimientos por fecha y amplia filas/paginas para no truncar historial.
  Incluye cantidades, atributos, observaciones y referencias de evidencia.
- Ambos controladores de descarga usan el mismo servicio con permisos directos
  y alcance vigente por trabajador/almacen. Consultas de evidencia auditadas.
- `logistica/index.blade.php`: Desde/Hasta junto a Descargar Excel; rango
  enviado al servidor y validacion antes de descargar.
- `EPP_FIRMA_OPERATIVA.md`: guia canonica actualizada.

La ultima firma valida del trabajador por fecha se muestra solo donde cubre
movimientos. El asterisco distingue esos movimientos; no se atribuye esa firma
a otros lotes ni a registros anteriores sin evidencia. Testigos no se imprimen
como firma del trabajador. Los detalles conservan las constancias anteriores.

## Verificacion

- Regresiones: plantilla, fechas, historial sin firma, devoluciones parciales,
  ultima firma por dia, testigos, articulos adicionales, mas de veinte fechas,
  cambios, zona horaria Lima, observaciones y ajustes administrativos.
- Chrome sintetico: mes anterior, fechas personalizadas, rango invertido y
  controles a 320/375/756/1440 px. Todas las solicitudes interceptadas; sin
  autenticacion contra la sesion del usuario.
- No hay migraciones, nuevas dependencias ni escrituras sobre datos reales.

Resultado final focalizado: **312 pruebas / 2184 aserciones, cero fallos y
cero errores**, incluida la regresion de cierre y entrega en fechas distintas.
Las nueve regresiones de formato pasaron (108 aserciones). La prueba de reloj
global y la descarga con plantilla se repitieron aisladas: ambas pasaron.
Chrome, Pint, php -l, view:cache y git diff --check terminaron correctamente.
Revision independiente final sin hallazgos pendientes. No se requirio nuevo
build Vite: este ajuste modifica Blade/JS inline, no assets compilables.

Cambio aplicado localmente, sin commit, push ni despliegue externo. Para usarlo,
recargar y abrir Logistica > Kardex, seleccionar trabajador y Desde/Hasta.
Para desplegar, incluir el nuevo servicio, Request, controladores, Blade y
la plantilla binaria; no se necesita migracion para esta correccion de Excel.

La suite global ejecutada antes del ultimo ajuste de fechas de cambio dio
1325 pruebas / 8237 aserciones, 41 fallos y 1 error. Comparada con el XML previo
de la revision local, mantiene los 41 casos ya pendientes y agrega un fallo
de reloj en PersonalRegularizationDashboardTest (17:17:55 frente a 17:17:54).
No se declara verde global ni se amplian cambios a esos otros flujos.

Revision visual del export sintetico mediante artifact tool: matriz con
movimientos multiples legibles, firma en su fila y detalle de cobertura sin
recortes. No se abrio Microsoft Excel ni la sesion operativa autenticada; queda
pendiente revisar impresion y aspecto final en Excel y el dispositivo real.

## Casos globales pendientes

- `Tests.Feature.EvaluacionSupervisorApiTest::test_creacion_correcta_evaluacion_supervisor`.
- `Tests.Feature.EvaluacionSupervisorApiTest::test_no_duplicidad_para_mismo_contexto`.
- `Tests.Feature.EvaluacionSupervisorApiTest::test_visualizacion_y_edicion_correcta`.
- `Tests.Feature.EvaluacionSupervisorApiTest::test_compatibilidad_destino_taller_oficina`.
- `Tests.Feature.ExampleTest::test_the_application_returns_a_successful_response`.
- `Tests.Feature.ImportPersonalLifecycleStateTest::test_imported_preparation_contract_dates_appear_in_contract_expirations`.
- `Tests.Feature.LogisticaEppIntegrationTest::test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente`.
- `Tests.Feature.ParadaHerramientaServiceTest::test_guarda_y_envia_lista_de_herramientas_por_grupo`.
- `Tests.Feature.ParadaHerramientaServiceTest::test_actualiza_cantidades_entregadas_y_recibidas_del_pedido`.
- `Tests.Feature.ParadaHerramientaServiceTest::test_modo_entrega_y_recepcion_no_se_pisan_entre_si`.
- `Tests.Feature.ParadaHerramientaServiceTest::test_entrega_solo_se_registra_cuando_la_parada_inicio_y_guarda_incidencia`.
- `Tests.Feature.ParadaHerramientaServiceTest::test_recepcion_final_guarda_fecha_estado_y_observacion`.
- `Tests.Feature.PersonalAntiguoRegularizationTest::test_cesado_existente_regularizado_como_vigente_no_queda_cesado_y_aparece_en_vencimientos`.
- `Tests.Feature.PersonalContractStatusHighlightTest::test_listado_resalta_columna_contrato_segun_estado_documental`.
- `Tests.Feature.PersonalContractStatusHighlightTest::test_burbuja_adjuntar_contrato_firma_contrato_editable_y_limpia_aviso`.
- `Tests.Feature.PersonalContractStatusHighlightTest::test_contrato_cerrado_prioriza_estado_cesado_sobre_inactivo`.
- `Tests.Feature.PersonalContratoFormatoServiceTest::test_contract_format_routes_return_templates_preview_and_download`.
- `Tests.Feature.PersonalContratoFormatoServiceTest::test_signed_contract_upload_activates_worker_after_download`.
- `Tests.Feature.PersonalContratoFormatoServiceTest::test_contract_data_update_redirects_to_personal_list`.
- `Tests.Feature.PersonalContratoFormatoServiceTest::test_signed_contract_can_be_downloaded_from_documents_view`.
- `Tests.Feature.PersonalContratoNotRenewedClosureTest::test_ruta_respeta_permiso_actualizar`.
- `Tests.Feature.PersonalContratoRenewalTest::test_historial_solo_muestra_boton_de_subir_pdf_para_contrato_en_preparacion`.
- `Tests.Feature.PersonalContratoRenewalTest::test_detalle_de_contrato_muestra_documento_firmado_solo_si_existe_pdf`.
- `Tests.Feature.PersonalContratoRenewalTest::test_descarga_documento_firmado_del_contrato_historico_exacto`.
- `Tests.Feature.PersonalContratoRenewalTest::test_historial_de_contratos_muestra_catalogo_de_puestos_al_editar`.
- `Tests.Feature.PersonalContratoRenewalTest::test_editar_contrato_rechaza_puesto_fuera_del_catalogo`.
- `Tests.Feature.PersonalContratoRenewalTest::test_editar_contrato_rechaza_tipo_de_contrato_fuera_del_catalogo`.
- `Tests.Feature.PersonalDocumentoDownloadTest::test_individual_document_download_still_works`.
- `Tests.Feature.PersonalDocumentoDownloadTest::test_route_permissions_and_validations_for_bulk_download`.
- `Tests.Feature.PersonalFichaObservedResubmitTest::test_public_submit_allows_missing_documents_for_later_regularization`.
- `Tests.Feature.PersonalListaNegraTest::test_usuario_con_permiso_agrega_trabajador_a_lista_negra_sin_cambiar_estado`.
- `Tests.Feature.PersonalListaNegraTest::test_usuario_con_permiso_quita_trabajador_de_lista_negra_sin_cambiar_estado`.
- `Tests.Feature.PersonalListaNegraTest::test_listado_mantiene_visible_lista_negra_aunque_filtre_estado`.
- `Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_tarea_diaria_actualiza_vencimientos_y_deja_historial_de_sistema`.
- `Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_acciones_de_trabajador_diferencian_asignar_y_gestionar_examenes`.
- `Tests.Feature.PersonalPuestoManagementTest::test_boton_puestos_aparece_en_acciones_de_personal`.
- `Tests.Feature.PersonalPuestoManagementTest::test_crea_puesto_con_funciones`.
- `Tests.Feature.PersonalPuestoManagementTest::test_editar_puesto_mantiene_id_y_actualiza_trabajadores_enlazados`.
- `Tests.Feature.PersonalPuestoManagementTest::test_elimina_puesto_sin_trabajadores_asociados`.
- `Tests.Feature.PersonalPuestoManagementTest::test_no_elimina_puesto_con_trabajadores_asociados`.
- `Tests.Feature.PersonalRegularizationDashboardTest::test_ceased_section_links_to_reentry_without_creating_or_changing_a_contract`.
- `Tests.Feature.PersonalRegularizationDashboardTest::test_fragment_refresh_returns_rendered_results_and_does_not_cache`.
