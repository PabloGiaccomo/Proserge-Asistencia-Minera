# Inventario de fallos de la suite completa

Fecha: 2026-10-06. Base exclusiva: proserge_app_test.

## Ultima verificacion

Fuente: storage/app/private/security-audit/2026-10-06/full-suite-third-block.xml.
Resultado JUnit: 768 casos / 4316 assertions; 709 pasan, 19 errores y 40 fallos.
59 casos pendientes, 0 omitidos. No es una suite global verde.

La primera captura (full-suite.xml) contenia 94 casos fallidos. Comparacion por
clase + nombre: 35 resueltos y 0 fallos nuevos en este bloque. Ambas capturas
se conservan en disco privado. Todos los tests globales terminaron con exit 1,
como corresponde a una suite con fallos; no se interrumpio esta ejecucion.

Comando global: php vendor/phpunit/phpunit/phpunit --no-progress --no-output
--log-junit storage/app/private/security-audit/2026-10-06/full-suite-third-block.xml.
La primera ejecucion Artisan/Collision habia atascado su formateador de errores
HTML; se adopto PHPUnit directo para no repetir ese coste.

## Resuelto en el tercer bloque

- RQMinaApiTest: 24 casos antes bloqueados por el nombre UNIQUE de ADMIN.
- RQMinaPlanManagementTest: 11 casos antes bloqueados por esa fixture. Al
  ejecutarlos se encontro y corrigio la doble codificacion de documentos en
  el guardado de planes. Dos regresiones nuevas verifican guardados repetidos
  y compatibilidad historica. No se tocaron datos reales.
- Cuatro regresiones nuevas de administrar pasan; no formaban parte de los
  94 fallos originales. Detalle de permisos: authorization-audit.md.

## Clasificacion Inicial de Pendientes

- ParadaHerramientaServiceTest todavia incluye una colision UNIQUE de
  OPERACIONES y cuatro rechazos de actualizacion por contrastar con permisos.
- Convalidacion/reintentos con ValidationException por fecha futura requieren
  reloj aislado antes de interpretar el resultado; no relajar fechas reales.
- Rechazos 403 de Personal deben contrastar permisos especificos de fixtures,
  sesion y rol persistido. No conceder permisos nuevos para ocultar un fallo.
- Supervisor API antigua, expectativas HTML y otros casos necesitan
  reproduccion dirigida. No son todos bugs ni todos tests obsoletos.

## Casos Pendientes

### EvaluacionSupervisorApiTest

Fuente: tests/Feature/EvaluacionSupervisorApiTest.php.

- `test_creacion_correcta_evaluacion_supervisor`: Expected response status code [201] but received 422.
- `test_no_duplicidad_para_mismo_contexto`: Expected response status code [201] but received 422.
- `test_visualizacion_y_edicion_correcta`: Failed asserting that null is identical to ''.
- `test_compatibilidad_destino_taller_oficina`: Expected response status code [201] but received 422.

### ExampleTest

Fuente: tests/Feature/ExampleTest.php.

- `test_the_application_returns_a_successful_response`: Expected response status code [200] but received 302.

### ImportPersonalLifecycleStateTest

Fuente: tests/Feature/ImportPersonalLifecycleStateTest.php.

- `test_imported_preparation_contract_dates_appear_in_contract_expirations`: Failed asserting that actual size 0 matches expected size 1.

### LogisticaEppIntegrationTest

Fuente: tests/Feature/LogisticaEppIntegrationTest.php.

- `test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente`: Expectativa de contenido HTML no satisfecha; revisar el XML para el detalle.

### MiningExamIdentityIntegrationTest

Fuente: tests/Feature/MiningExamIdentityIntegrationTest.php.

- `test_master_reactivates_inactive_confirmed_alias_without_creating_or_renaming`: Failed asserting that null is identical to '<id sintetico>'.
- `test_bulk_sole_active_exact_name_is_not_ambiguous_with_unrelated_inactive_history`: Failed asserting that null is identical to '<id sintetico>'.
- `test_master_cache_keeps_confirmed_aliases_with_different_spacing_separate`: Failed asserting that false is true.
- `test_group_suggests_origin_but_only_explicit_convalidation_changes_destination_with_null_grade`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_group_rejects_expired_foreign_worker_and_low_grade_sources`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_group_does_not_bypass_grade_requirement_or_disabled_convalidation`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.

### ParadaHerramientaServiceTest

Fuente: tests/Feature/ParadaHerramientaServiceTest.php.

- `test_guarda_y_envia_lista_de_herramientas_por_grupo`: Illuminate\Database\UniqueConstraintViolationException: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'OPERACIONES' for key 'uq_roles_nombre' (Connection: mysql, Host: 127.0.0.1, Port: 3
- `test_actualiza_cantidades_entregadas_y_recibidas_del_pedido`: Failed asserting that false is true.
- `test_modo_entrega_y_recepcion_no_se_pisan_entre_si`: Failed asserting that false is true.
- `test_entrega_solo_se_registra_cuando_la_parada_inicio_y_guarda_incidencia`: Failed asserting that false is true.
- `test_recepcion_final_guarda_fecha_estado_y_observacion`: Failed asserting that false is true.

### PersonalAntiguoRegularizationTest

Fuente: tests/Feature/PersonalAntiguoRegularizationTest.php.

- `test_cesado_existente_regularizado_como_vigente_no_queda_cesado_y_aparece_en_vencimientos`: Expected response status code [200] but received 403.

### PersonalContractStatusHighlightTest

Fuente: tests/Feature/PersonalContractStatusHighlightTest.php.

- `test_listado_resalta_columna_contrato_segun_estado_documental`: Expectativa de contenido HTML no satisfecha; revisar el XML para el detalle.
- `test_burbuja_adjuntar_contrato_firma_contrato_editable_y_limpia_aviso`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
- `test_contrato_cerrado_prioriza_estado_cesado_sobre_inactivo`: Expected response status code [200] but received 403.

### PersonalContratoFormatoServiceTest

Fuente: tests/Feature/PersonalContratoFormatoServiceTest.php.

- `test_contract_format_routes_return_templates_preview_and_download`: Expected response status code [200] but received 403.
- `test_signed_contract_upload_activates_worker_after_download`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
- `test_contract_data_update_redirects_to_personal_list`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
- `test_signed_contract_can_be_downloaded_from_documents_view`: Expected response status code [200] but received 403.

### PersonalContratoNotRenewedClosureTest

Fuente: tests/Feature/PersonalContratoNotRenewedClosureTest.php.

- `test_ruta_respeta_permiso_actualizar`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.

### PersonalContratoRenewalTest

Fuente: tests/Feature/PersonalContratoRenewalTest.php.

- `test_historial_solo_muestra_boton_de_subir_pdf_para_contrato_en_preparacion`: Expected response status code [200] but received 403.
- `test_detalle_de_contrato_muestra_documento_firmado_solo_si_existe_pdf`: Expected response status code [200] but received 403.
- `test_descarga_documento_firmado_del_contrato_historico_exacto`: Expected response status code [404] but received 403.
- `test_historial_de_contratos_muestra_catalogo_de_puestos_al_editar`: Expected response status code [200] but received 403.
- `test_editar_contrato_rechaza_puesto_fuera_del_catalogo`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
- `test_editar_contrato_rechaza_tipo_de_contrato_fuera_del_catalogo`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.

### PersonalDocumentoDownloadTest

Fuente: tests/Feature/PersonalDocumentoDownloadTest.php.

- `test_individual_document_download_still_works`: Expected response status code [200] but received 403.
- `test_route_permissions_and_validations_for_bulk_download`: Session is missing expected key [errors].

### PersonalFichaObservedResubmitTest

Fuente: tests/Feature/PersonalFichaObservedResubmitTest.php.

- `test_public_submit_allows_missing_documents_for_later_regularization`: Expectativa de contenido HTML no satisfecha; revisar el XML para el detalle.

### PersonalListaNegraTest

Fuente: tests/Feature/PersonalListaNegraTest.php.

- `test_usuario_con_permiso_agrega_trabajador_a_lista_negra_sin_cambiar_estado`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
- `test_usuario_con_permiso_quita_trabajador_de_lista_negra_sin_cambiar_estado`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
- `test_listado_mantiene_visible_lista_negra_aunque_filtre_estado`: Expectativa de contenido HTML no satisfecha; revisar el XML para el detalle.

### PersonalMinaExamenesMinerosTest

Fuente: tests/Feature/PersonalMinaExamenesMinerosTest.php.

- `test_reintentos_permiten_superar_el_maximo_y_mantienen_no_habilitado_si_desaprueba`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_nota_minima_y_no_aplica`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_convalida_equivalencias_con_nota_opcional with data set "ambos sin nota"`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_convalida_equivalencias_con_nota_opcional with data set "nota suficiente"`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_convalida_equivalencias_con_nota_opcional with data set "nota insuficiente"`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_convalida_equivalencias_con_nota_opcional with data set "origen sin nota y destino exige"`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_convalida_equivalencias_con_nota_opcional with data set "conserva nota aunque destino no exige"`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_convalidacion_mismo_examen_conserva_nota_y_rechaza_equivalencia_no_configurada`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_convalidacion_requiere_permiso_pero_no_scope_de_mina`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_convalidacion_rechaza_destino_inactivo_aunque_conserve_historial`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.

### PersonalMinaHabilitacionCorrectedFlowTest

Fuente: tests/Feature/PersonalMinaHabilitacionCorrectedFlowTest.php.

- `test_registro_de_intento_bloquea_examen_antes_de_asignar_numero`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_intentos_manuales_conservan_numeros_consecutivos_aun_si_superan_el_maximo`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_fallo_al_guardar_intento_nuevo_elimina_archivo_subido_y_revierte_datos`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_fallo_al_completar_programado_elimina_archivo_y_conserva_programacion`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.
- `test_examen_aprobado_que_ya_vencio_puede_rendirse_otra_vez`: Illuminate\Validation\ValidationException: La fecha de realizacion no puede ser futura.

### PersonalPuestoManagementTest

Fuente: tests/Feature/PersonalPuestoManagementTest.php.

- `test_boton_puestos_aparece_en_acciones_de_personal`: Expectativa de contenido HTML no satisfecha; revisar el XML para el detalle.
- `test_crea_puesto_con_funciones`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
- `test_editar_puesto_mantiene_id_y_actualiza_trabajadores_enlazados`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
- `test_elimina_puesto_sin_trabajadores_asociados`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
- `test_no_elimina_puesto_con_trabajadores_asociados`: Expected response status code [201, 301, 302, 303, 307, 308] but received 403.
