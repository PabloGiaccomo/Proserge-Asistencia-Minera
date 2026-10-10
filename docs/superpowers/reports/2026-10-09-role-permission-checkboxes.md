# Correccion de checks de permisos de roles

Fecha: 2026-10-09. Alcance: pantalla de roles y aplicacion efectiva de los checks.

## Correccion posterior: consumidor de Dashboard en Evaluaciones

El reporte del usuario permitio reproducir una brecha no cubierta por los
tests anteriores: la matriz guardaba Dashboard=false pero
`EvaluacionesPageService` agregaba la pestana por tener cualquier evaluacion
visible. La inspeccion local de la cuenta indicada fue de solo lectura; sus
dos roles activos no concedian Dashboard. No se cambiaron roles ni usuarios.

Cambios acotados:

- EvaluacionesPageService consulta el grant efectivo `evaluaciones.dashboards`
  antes de agregar la pestana.
- EvaluacionDesempenoPageController rechaza `tipo=dashboard` sin el grant con
  403 antes de preparar datos, tambien para `X-Evaluation-Dashboard: 1`.
- RolePermissionCheckboxTest ahora recorre la pagina real: pestaña ausente,
  denegacion directa/parcial y grant/revocacion sin logout.
- EvaluacionesWebTest declara Dashboard en la fixture que prueba filtros del
  Dashboard. No se flexibilizan checks para que pase la prueba.
- Se mantiene acceso total de ADMIN/SUPERADMIN activos y union de otros roles
  activos; alcance y permisos de tipos de evaluacion no cambian.

Regresiones rojas: las dos pruebas reales fallaron por Dashboard agregado
automaticamente. Resultado focal final: 100 pruebas / 3136 assertions, sin
fallos ni errores (EvaluacionesWebTest, WebPermissionMiddlewareTest,
RbacAccessAuditTest, RolePermissionCheckboxTest). PHP lint, Pint del test nuevo,
compilacion Blade y git diff --check pasan. Revision independiente sin hallazgos
significativos. No cambia Blade/JS/CSS ni requiere build, migracion o despliegue.
El usuario debe recargar la pagina local; no se probo su sesion en navegador.
Suite global de esta correccion: 1354 pruebas / 10933 assertions, 41 fallos y
4 errores, 299.35 segundos. No es verde. Frente a la corrida inmediatamente
anterior dejan de fallar seis casos EMO, y aparecen dos casos de devoluciones
EPP (integridad de firma/contenido) y un caso API de Evaluaciones (deadlock en
Personal/Mina). No se atribuyen esos resultados a este control de pestanas;
requieren investigacion separada. No se tocaron esos servicios para ocultarlos.
Todos los casos fallidos observados en esta corrida son:

- Tests.Feature.EppReturnServiceTest::test_older_signed_return_without_existence_link_can_be_compensated_from_its_ledger
- Tests.Feature.EppReturnServiceTest::test_return_overflow_rolls_back_acceptance_and_balance
- Tests.Feature.EvaluacionSupervisorApiTest::test_creacion_correcta_evaluacion_supervisor
- Tests.Feature.EvaluacionSupervisorApiTest::test_no_duplicidad_para_mismo_contexto
- Tests.Feature.EvaluacionSupervisorApiTest::test_visualizacion_y_edicion_correcta
- Tests.Feature.EvaluacionSupervisorApiTest::test_compatibilidad_destino_taller_oficina
- Tests.Feature.EvaluacionesApiTest::test_no_duplica_evaluacion_en_mismo_grupo_y_trabajador
- Tests.Feature.ExampleTest::test_the_application_returns_a_successful_response
- Tests.Feature.ImportPersonalLifecycleStateTest::test_imported_preparation_contract_dates_appear_in_contract_expirations
- Tests.Feature.LogisticaDashboardServiceTest::test_dashboard_scope_counts_only_active_or_enabled_workers_and_splits_expirations
- Tests.Feature.LogisticaEppIntegrationTest::test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente
- Tests.Feature.ParadaHerramientaServiceTest::test_guarda_y_envia_lista_de_herramientas_por_grupo
- Tests.Feature.ParadaHerramientaServiceTest::test_actualiza_cantidades_entregadas_y_recibidas_del_pedido
- Tests.Feature.ParadaHerramientaServiceTest::test_modo_entrega_y_recepcion_no_se_pisan_entre_si
- Tests.Feature.ParadaHerramientaServiceTest::test_entrega_solo_se_registra_cuando_la_parada_inicio_y_guarda_incidencia
- Tests.Feature.ParadaHerramientaServiceTest::test_recepcion_final_guarda_fecha_estado_y_observacion
- Tests.Feature.PersonalAntiguoRegularizationTest::test_cesado_existente_regularizado_como_vigente_no_queda_cesado_y_aparece_en_vencimientos
- Tests.Feature.PersonalContractStatusHighlightTest::test_listado_resalta_columna_contrato_segun_estado_documental
- Tests.Feature.PersonalContractStatusHighlightTest::test_burbuja_adjuntar_contrato_firma_contrato_editable_y_limpia_aviso
- Tests.Feature.PersonalContractStatusHighlightTest::test_contrato_cerrado_prioriza_estado_cesado_sobre_inactivo
- Tests.Feature.PersonalContratoFormatoServiceTest::test_contract_format_routes_return_templates_preview_and_download
- Tests.Feature.PersonalContratoFormatoServiceTest::test_signed_contract_upload_activates_worker_after_download
- Tests.Feature.PersonalContratoFormatoServiceTest::test_contract_data_update_redirects_to_personal_list
- Tests.Feature.PersonalContratoFormatoServiceTest::test_signed_contract_can_be_downloaded_from_documents_view
- Tests.Feature.PersonalContratoNotRenewedClosureTest::test_ruta_respeta_permiso_actualizar
- Tests.Feature.PersonalContratoRenewalTest::test_historial_solo_muestra_boton_de_subir_pdf_para_contrato_en_preparacion
- Tests.Feature.PersonalContratoRenewalTest::test_detalle_de_contrato_muestra_documento_firmado_solo_si_existe_pdf
- Tests.Feature.PersonalContratoRenewalTest::test_descarga_documento_firmado_del_contrato_historico_exacto
- Tests.Feature.PersonalContratoRenewalTest::test_historial_de_contratos_muestra_catalogo_de_puestos_al_editar
- Tests.Feature.PersonalContratoRenewalTest::test_editar_contrato_rechaza_puesto_fuera_del_catalogo
- Tests.Feature.PersonalContratoRenewalTest::test_editar_contrato_rechaza_tipo_de_contrato_fuera_del_catalogo
- Tests.Feature.PersonalDocumentoDownloadTest::test_individual_document_download_still_works
- Tests.Feature.PersonalDocumentoDownloadTest::test_route_permissions_and_validations_for_bulk_download
- Tests.Feature.PersonalFichaObservedResubmitTest::test_public_submit_allows_missing_documents_for_later_regularization
- Tests.Feature.PersonalListaNegraTest::test_usuario_con_permiso_agrega_trabajador_a_lista_negra_sin_cambiar_estado
- Tests.Feature.PersonalListaNegraTest::test_usuario_con_permiso_quita_trabajador_de_lista_negra_sin_cambiar_estado
- Tests.Feature.PersonalListaNegraTest::test_listado_mantiene_visible_lista_negra_aunque_filtre_estado
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_tarea_diaria_actualiza_vencimientos_y_deja_historial_de_sistema
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_acciones_de_trabajador_diferencian_asignar_y_gestionar_examenes
- Tests.Feature.PersonalPuestoManagementTest::test_boton_puestos_aparece_en_acciones_de_personal
- Tests.Feature.PersonalPuestoManagementTest::test_crea_puesto_con_funciones
- Tests.Feature.PersonalPuestoManagementTest::test_editar_puesto_mantiene_id_y_actualiza_trabajadores_enlazados
- Tests.Feature.PersonalPuestoManagementTest::test_elimina_puesto_sin_trabajadores_asociados
- Tests.Feature.PersonalPuestoManagementTest::test_no_elimina_puesto_con_trabajadores_asociados
- Tests.Feature.PersonalRegularizationDashboardTest::test_ceased_section_links_to_reentry_without_creating_or_changing_a_contract

## Resultado operativo

- ADMIN/SUPERADMIN activos mantienen acceso total, aun con matrices parciales.
- Cualquier otro rol, incluido GERENTE, permite conceder y retirar cada check.
- Dashboard no vuelve a activarse por otras acciones. Ver y Administrar tampoco
  recrean permisos explicitamente desmarcados.
- El formulario guarda una matriz completa: un check omitido equivale a false,
  incluso cuando se quitan todos o se reintenta una validacion fallida.
- La siguiente peticion recalcula permisos sin cerrar sesion. Otro rol ACTIVO
  puede seguir concediendo el mismo permiso; uno INACTIVO no lo concede.
- Compatibilidad historica solamente para claves ausentes. Las alternativas
  expresas de rutas, como editar|actualizar, siguen vigentes.
- Preferencias de notificaciones no conceden permisos. Ver permite consultar;
  Actualizar permite lectura/archivo, tambien tras polling. ADMIN/SUPERADMIN
  activos conservan acceso administrativo aunque deshabiliten notificaciones.
- El control dedicado de programados exige su permiso especifico, sin exigir
  Ver general. Las pestanas independientes de Logistica reciben su contexto
  y actor autenticado sin conceder EPP ni ampliar el alcance por mina.

## Archivos de esta correccion

- app/Support/Rbac/PermissionMatrix.php: respetar false explicito, privilegios.
- app/Modules/Seguridad/Controllers/RolPageController.php y
  resources/views/seguridad/roles/_form.blade.php: persistencia y checks independientes.
- app/Http/Middleware/EnsureWebPermission.php: impedir fallbacks sobre false.
- app/Providers/AppServiceProvider.php y resources/views/partials/header.blade.php:
  preferencias y acciones de notificaciones sujetas al permiso.
- app/Modules/Personal/Controllers/PersonalMinaHabilitacionController.php:
  entrada dedicada de programados independiente del Ver general.
- app/Modules/Logistica/Controllers/LogisticaPageController.php: contexto de
  pestanas autorizado y scoped, incluido el workspace de entregas.
- tests/Feature/RolePermissionCheckboxTest.php: normalizacion exhaustiva,
  guardado, validacion, sesion, union de roles, administradores y pestanas.
- tests/Browser/role-permissions.cjs y render-role-permissions.php: formulario
  real Blade en escritorio/movil, envio y permisos tras polling.
- WebPermissionMiddlewareTest, RbacOperationalScopeTest, UsuarioNotificationTest:
  expectativas actualizadas a la regla aprobada.
- PersonalMinaHabilitacionCorrectedFlowTest, PersonalMinaHabilitacionBaseTest,
  PersonalMinaExamenesMinerosTest y PersonalMinaEmoWebTest: fixtures declaran
  permisos especificos en lugar de herencias implicitas. No se flexibiliza el
  builder general ni los actores que prueban fronteras de autorizacion.
- docs/CODEX_CONTEXT.md y docs/security/authorization-audit.md: regla canonica.

El checkout contenia numerosos cambios previos; no se descartaron ni se
atribuyen todos los diffs de esos archivos a esta correccion.

## Verificacion

- RBAC focalizado: 92 pruebas, 2970 assertions, sin fallos ni errores.
- Regresion posterior de roles/mineria: 13 pruebas, 2436 assertions, sin fallos.
- Roles y Logistica final: 46 pruebas, 2713 assertions; 1 fallo previo:
  LogisticaEppIntegrationTest::test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente
  (respuesta 200, texto esperado Subir catalogo ausente, misma causa del baseline).
  Las 11 nuevas pruebas de checks pasan, incluido el recorrido de todas las pestanas.
- Navegador sintetico: escritorio 1440 y movil 390, checks y seleccion masiva
  independientes, payload sin checks quitados, sin desbordamientos/errores.
  Polling conserva permisos de notificaciones (solo Ver: 0 formularios;
  Ver + Actualizar: 3). Todo el HTTP se intercepta; no se opera una cuenta real.
- PHP lint de implementacion y tests tocados pasa. git diff --check pasa.
  Compilacion Blade repetida tras la suite global: exit 0.
- Pint de archivos nuevos pasa; archivos previamente modificados conservan
  incidencias de estilo, sin reformatear trabajo ajeno.
- Sin cambios de assets compilables: no corresponde build Vite.
- No hay migraciones, cambios de dependencias, commit, push ni despliegue.
  Pruebas exclusivamente en proserge_app_test, con transacciones y guardia.
- Suite global final: 1351 pruebas, 10913 assertions, 40 fallos y 1 error,
  493.13 segundos. Comparacion de nombres fallidos contra baseline: sin altas
  ni bajas (mismos 41 casos). La falla de Logistica vuelve a su causa previa
  de texto ausente, no un error de render. Las 11 regresiones de permisos
  pasan con 2449 assertions. Resultado global NO verde; pendientes abajo.
- Revision independiente final sin hallazgos significativos sobre el contexto
  de Logistica; no se ejecutaron pruebas DB concurrentes durante la revision.

## Uso y pendientes manuales

Recargar la pantalla de permisos antes de editar y guardar los checks deseados.
No se modificaron permisos de usuarios reales durante la verificacion.
Validar con un usuario operativo real y revisar sus otros roles activos si un
permiso sigue disponible. No se probo ese paso con una sesion real del usuario.
La auditoria integral de autorizacion sigue abierta; este informe no la cierra.

## Casos globales fallidos ya existentes

Baseline previo: 1340 pruebas, 8464 assertions, 40 fallos y 1 error.
Se conservan los nombres para distinguir pendientes ajenos de regresiones:

- Tests.Feature.EvaluacionSupervisorApiTest::test_creacion_correcta_evaluacion_supervisor
- Tests.Feature.EvaluacionSupervisorApiTest::test_no_duplicidad_para_mismo_contexto
- Tests.Feature.EvaluacionSupervisorApiTest::test_visualizacion_y_edicion_correcta
- Tests.Feature.EvaluacionSupervisorApiTest::test_compatibilidad_destino_taller_oficina
- Tests.Feature.ExampleTest::test_the_application_returns_a_successful_response
- Tests.Feature.ImportPersonalLifecycleStateTest::test_imported_preparation_contract_dates_appear_in_contract_expirations
- Tests.Feature.LogisticaEppIntegrationTest::test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente
- Tests.Feature.ParadaHerramientaServiceTest::test_guarda_y_envia_lista_de_herramientas_por_grupo
- Tests.Feature.ParadaHerramientaServiceTest::test_actualiza_cantidades_entregadas_y_recibidas_del_pedido
- Tests.Feature.ParadaHerramientaServiceTest::test_modo_entrega_y_recepcion_no_se_pisan_entre_si
- Tests.Feature.ParadaHerramientaServiceTest::test_entrega_solo_se_registra_cuando_la_parada_inicio_y_guarda_incidencia
- Tests.Feature.ParadaHerramientaServiceTest::test_recepcion_final_guarda_fecha_estado_y_observacion
- Tests.Feature.PersonalAntiguoRegularizationTest::test_cesado_existente_regularizado_como_vigente_no_queda_cesado_y_aparece_en_vencimientos
- Tests.Feature.PersonalContractStatusHighlightTest::test_listado_resalta_columna_contrato_segun_estado_documental
- Tests.Feature.PersonalContractStatusHighlightTest::test_burbuja_adjuntar_contrato_firma_contrato_editable_y_limpia_aviso
- Tests.Feature.PersonalContractStatusHighlightTest::test_contrato_cerrado_prioriza_estado_cesado_sobre_inactivo
- Tests.Feature.PersonalContratoFormatoServiceTest::test_contract_format_routes_return_templates_preview_and_download
- Tests.Feature.PersonalContratoFormatoServiceTest::test_signed_contract_upload_activates_worker_after_download
- Tests.Feature.PersonalContratoFormatoServiceTest::test_contract_data_update_redirects_to_personal_list
- Tests.Feature.PersonalContratoFormatoServiceTest::test_signed_contract_can_be_downloaded_from_documents_view
- Tests.Feature.PersonalContratoNotRenewedClosureTest::test_ruta_respeta_permiso_actualizar
- Tests.Feature.PersonalContratoRenewalTest::test_historial_solo_muestra_boton_de_subir_pdf_para_contrato_en_preparacion
- Tests.Feature.PersonalContratoRenewalTest::test_detalle_de_contrato_muestra_documento_firmado_solo_si_existe_pdf
- Tests.Feature.PersonalContratoRenewalTest::test_descarga_documento_firmado_del_contrato_historico_exacto
- Tests.Feature.PersonalContratoRenewalTest::test_historial_de_contratos_muestra_catalogo_de_puestos_al_editar
- Tests.Feature.PersonalContratoRenewalTest::test_editar_contrato_rechaza_puesto_fuera_del_catalogo
- Tests.Feature.PersonalContratoRenewalTest::test_editar_contrato_rechaza_tipo_de_contrato_fuera_del_catalogo
- Tests.Feature.PersonalDocumentoDownloadTest::test_individual_document_download_still_works
- Tests.Feature.PersonalDocumentoDownloadTest::test_route_permissions_and_validations_for_bulk_download
- Tests.Feature.PersonalFichaObservedResubmitTest::test_public_submit_allows_missing_documents_for_later_regularization
- Tests.Feature.PersonalListaNegraTest::test_usuario_con_permiso_agrega_trabajador_a_lista_negra_sin_cambiar_estado
- Tests.Feature.PersonalListaNegraTest::test_usuario_con_permiso_quita_trabajador_de_lista_negra_sin_cambiar_estado
- Tests.Feature.PersonalListaNegraTest::test_listado_mantiene_visible_lista_negra_aunque_filtre_estado
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_tarea_diaria_actualiza_vencimientos_y_deja_historial_de_sistema
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_acciones_de_trabajador_diferencian_asignar_y_gestionar_examenes
- Tests.Feature.PersonalPuestoManagementTest::test_boton_puestos_aparece_en_acciones_de_personal
- Tests.Feature.PersonalPuestoManagementTest::test_crea_puesto_con_funciones
- Tests.Feature.PersonalPuestoManagementTest::test_editar_puesto_mantiene_id_y_actualiza_trabajadores_enlazados
- Tests.Feature.PersonalPuestoManagementTest::test_elimina_puesto_sin_trabajadores_asociados
- Tests.Feature.PersonalPuestoManagementTest::test_no_elimina_puesto_con_trabajadores_asociados
- Tests.Feature.PersonalRegularizationDashboardTest::test_ceased_section_links_to_reentry_without_creating_or_changing_a_contract

## Fallos transitorios investigados durante esta correccion

Primera corrida: 1349 pruebas, 10577 assertions, 76 fallos y 1 error.
Los 36 nombres adicionales siguientes se investigaron. Se corrigio la entrada
dedicada, y las fixtures generales declararon permisos especificos conforme a
la regla aprobada; los tests de autorizacion conservan grants limitados.
Una corrida posterior volvio a los mismos 41 nombres del baseline, antes de
agregar las dos regresiones finales de contexto de Logistica:

- Tests.Feature.PersonalMinaEmoWebTest::test_dedicated_control_route_accepts_direct_scheduled_permission_without_general_view
- Tests.Feature.PersonalMinaEmoWebTest::test_control_uses_sql_paginator_limit_dto_and_options_without_calling_legacy_query
- Tests.Feature.PersonalMinaExamenesMinerosTest::test_archivo_de_intento_y_descarga_por_ruta
- Tests.Feature.PersonalMinaExamenesMinerosTest::test_rutas_respetan_permiso_actualizar_y_no_cambian_estado_laboral_ni_contrato
- Tests.Feature.PersonalMinaHabilitacionBaseTest::test_rutas_respetan_permiso_actualizar
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_vista_principal_muestra_acciones_y_no_formularios_administrativos_visibles
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_vista_no_abre_importar_excel_al_entrar_con_preview_guardada
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_vista_abre_importar_excel_solo_con_flag_temporal
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_agregar_examen_valida_campos_condicionales_y_maximo_dos_intentos
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_configurar_examenes_por_mina_en_columnas_y_quitar_examen
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_quitar_examen_de_mina_desde_modal_responde_json_sin_recargar
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_quitar_examen_de_mina_desactiva_duplicados_activos_equivalentes
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_configurar_requisito_sincroniza_en_segundo_plano_sin_habilitar_prematuramente
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_editar_examen_genera_historial_de_precio_sin_modificar_intentos_previos
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_asignar_desde_tarjeta_genera_examenes_y_conserva_trabajador_seleccionado
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_selector_de_trabajador_queda_limpio_y_filtros_operativos_van_en_matriz
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_selector_de_trabajador_muestra_total_real_y_no_solo_limite_visible
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_gestionar_examenes_del_trabajador_permite_abrir_o_generar_desde_el_modal
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_minas_disponibles_muestran_asignado_pendiente_y_evitan_reasignacion
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_desasignar_mina_desde_habilitacion_la_deja_disponible_para_el_trabajador
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_matriz_diferencia_desaprobado_con_reintento_de_desaprobado_definitivo
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_listado_de_habilitacion_se_pagina_por_cantidad_elegida
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_matriz_operativa_por_mina_muestra_examenes_dinamicos_y_estados_visuales
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_filtro_de_estado_examen_limita_trabajadores_y_columnas_de_la_matriz
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_proximos_vencimientos_muestra_solo_minas_asignadas_y_abre_gestion
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_filtros_de_vencimientos_y_programados_conservan_su_pestana_ajax
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_entrada_a_habilitacion_carga_solo_la_vista_visible
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_vista_no_muestra_habilitado_visual_sin_examenes_configurados_o_generados
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_excel_master_preview_no_guarda_y_confirmacion_importa_datos
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_excel_master_requiere_confirmacion_explicita_para_reemplazar_catalogo
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_excel_master_desactiva_convalidacion_cuando_examen_deja_de_ser_compartido
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_excel_master_actualiza_solo_habilitacion_de_trabajador_existente_sin_pisar_datos_internos
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_excel_master_real_con_hojas_por_mina_importa_evaluaciones_y_multiples_minas
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_excel_master_normaliza_dni_de_siete_digitos_y_actualiza_habilitacion_existente
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_excel_master_programar_emo_queda_en_proceso_y_accion_pendiente
- Tests.Feature.PersonalMinaHabilitacionCorrectedFlowTest::test_desaprobacion_no_bloquea_asignar_otra_mina_y_permite_nuevos_intentos
