# Verificacion: EPP con firma, lotes e inventario

Fecha: 2026-10-08 (America/Lima; cierre de comandos durante la madrugada UTC del 09).
Ultima suite completa finalizada: 2026-10-08 23:55:59 America/Lima. Ultima verificacion estatica/visual revisada: 23:56.
Rama: `codex/epp-signed-operations-finish`.
Ultimos commits funcionales: `ac9a9a2` (Task 10) y `a37210c` (correcciones de revision final).
Plan: [implementacion](2026-10-07-logistica-epp-firma-lotes.md).
Guia operativa: [EPP_FIRMA_OPERATIVA](../../EPP_FIRMA_OPERATIVA.md).

## Estado y puerta de liberacion

Tasks 1-10 implementadas. Revision independiente final: nueve hallazgos importantes corregidos en una sola ronda, con regresiones RED/GREEN. Task 11 parcial: verificacion local realizada, pero **no aprobado para produccion**.

Faltan:

- Task 11.1: suite transversal completamente verde; hay fallos ajenos a los flujos EPP focalizados.
- Task 11.2: estilo global completamente verde; no se reformatea el monolito para cerrar esta funcionalidad.
- Task 11.4: ensayo de backup/restauracion, migracion y operacion sobre copia realista aislada. No se proporciono una copia de datos/archivos ni un destino de ensayo autorizado. La base sintetica de pruebas NO sustituye este requisito.

No se ejecuto migracion, backfill ni apertura contra produccion. No se hizo merge ni push; el checkout original con cambios ajenos se conserva.

## Evidencia reproducible

Todos los PHPUnit usan `phpunit.xml`, MySQL `proserge_app_test` y comprobaciones de identidad de base. Los helpers concurrentes tambien exigen APP_ENV=testing y la misma base. PHP utilizado: 8.2.12. Comandos ejecutados desde el worktree; sustituir `php` por la ruta del ejecutable local si corresponde.

| Control | Resultado |
|---|---|
| EPP + dashboard + integracion EPP, excluyendo SOLO la asercion conocida de Herramientas | 280 tests, 1860 assertions, 0 failures, 0 errors |
| Suite completa final | 946 tests, 5232 assertions, 38 failures y 59 errors |
| Comparacion de fallos globales antes/despues de la ronda de correcciones | Los mismos 97 nombres; no se atribuye esto a una suite verde ni a un baseline anterior al plan |
| JavaScript de firma y mini-kardex | 9/9 PASS |
| Navegador Chrome/Playwright | PASS en 320/375/390/430/1440 px |
| Blade: `php artisan view:cache` | PASS |
| Vite: `npm run build` | PASS; manifest y assets compilados disponibles |
| Sintaxis PHP de archivos nuevos/modificados de toda la rama | 97 archivos PASS |
| Pint de archivos afectados y modulo EPP | PASS |
| Pint global final | Exit 1: 290 archivos reportados; no se corrigen estilos ajenos |
| Rutas EPP | 39 rutas; cero escrituras sin web.auth y web.permission |
| `git diff --check` | PASS |
| BPMN entrega/devolucion y correcciones | XML validado y SVG generado; entrega revisada visualmente |

Filtro preciso de suite focalizada, para no incluir accidentalmente metodos de otros modulos cuyo nombre contiene Logistica:

```powershell
php vendor/phpunit/phpunit/phpunit --filter '/^Tests\\Feature\\(?:Epp\w+|LogisticaDashboardServiceTest|LogisticaEppIntegrationTest)::(?!test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente)/'
php vendor/phpunit/phpunit/phpunit
php vendor/bin/pint --test
php artisan view:cache
npm.cmd run build
node --test tests/JavaScript/epp-signature-pad.test.mjs tests/JavaScript/epp-mini-kardex.test.mjs
node tests/Browser/epp-signed-delivery.cjs
php artisan route:list --path=epps -v
git diff --check
```

La prueba de navegador requiere Playwright disponible. Se reutilizo el runtime instalado, sin agregar dependencias al proyecto. Renderiza vistas Blade con modelos sinteticos y usa CSS real `public/proserge-app.css` + manifest Vite. Comprueba dibujo, borrado, resize/rotacion, cantidades parciales, saldos, disposiciones, formularios, testigo, resultado, perdida, verificacion, nueve columnas de cobertura y firmas de Personal; detecta errores de consola y overflow. Espera la animacion del menu y comprueba que el sidebar cerrado no cubra el contenido. No equivale a un ensayo HTTP completo sobre datos realistas.

## Revision final y regresiones

| Hallazgo importante | Regresion verificada |
|---|---|
| Lecturas/dashboard sin alcance y kardex bloqueado por almacen ajeno | EppAuthorizationTest::test_legacy_reads_and_dashboard_never_expose_workers_or_stock_outside_scope; kardex de trabajador con almacenes mixtos |
| Backfill reabria saldo legacy reducido | EppLegacyBackfillTest::test_rerunning_backfill_never_reopens_a_reduced_legacy_balance |
| Reintentos de compensacion duplicaban efectos | EppCorrectionTest::test_partial_correction_retries_apply_effects_only_once_and_reject_changed_payload; legacy retry, annulment retry y dos procesos concurrentes |
| Devoluciones sin existencia reversible | EppReturnServiceTest: anulacion restaura disponible/cuarentena; compatibilidad con devolucion anterior sin existencia_id usando ledger |
| UI obligaba corregir todas las lineas | EppCorrectionTest::test_http_correction_can_select_one_line_and_leave_other_lines_unchanged |
| Perdida sin entrada HTTP tras retirar flujo individual | EppReturnServiceTest: registro HTTP con UUID repetido reduce saldo una sola vez, stock sin aumento |
| Reportes dependian del catalogo actual y omitian hechos firmados | EppKardexEvidenceTest::test_reports_preserve_signed_names_attributes_and_return_facts |
| Excel interpretaba formula del nombre | EppKardexEvidenceTest::test_excel_keeps_catalog_formula_text_as_literal_string |
| Cobertura omitia consumible entregado con saldo cero | LogisticaDashboardServiceTest: consumible CONSUMIDO requerido mantiene cobertura |

Se conservaron originales firmados y eventos; las compensaciones son administrativas y no reciben ficticiamente la firma original. La nueva ruta de perdida exige permiso corregir y alcance. Las descargas mantienen almacenamiento privado y verificacion SHA-256. No se agregaron firmas reales, documentos privados, .env, dumps ni backups al commit.

## Backfill: evidencia sintetica, no operativa

`EppLegacyBackfillTest` ejecuta el comando real dentro de transacciones de prueba:

- Fixture de dos entregas: revisados 2, actualizados 2; no crea operaciones ni aceptaciones.
- Dry-run de una entrega: no inicializa cantidad_original.
- Primera ejecucion real sintetica y repeticion: actualizados 0 y omitidos 1 en la repeticion.
- Saldo original 4, reducido a 2: repetir conserva saldo 2 y cantidad_original 4.

No hay totales de backfill de una copia realista: ese ensayo permanece pendiente. No confundir estos contadores de fixtures con un inventario ni con un procesamiento operativo.

## Decisiones registradas y coste si fueran incorrectas

1. Ejecutar Tasks 1-10 secuencialmente por interfaces compartidas; evita conflictos. Coste: posible retrabajo de integracion.
2. Ensayar navegador/despliegue solo con dependencias y destino aislado disponibles, registrando limitaciones. Coste: evidencia de liberacion manual pendiente.
3. Trabajar exclusivamente en worktree y verificar identidad de base. Coste: detener pruebas cuando no pueda probarse aislamiento.
4. Continuar frente al fallo aislado de Herramientas, sin cambiar reglas ajenas. Coste: integracion global requiere reparacion separada.
5. Compensaciones sin aceptacion propia no son FIRMADO_VALIDO ni reutilizan firma. Coste: podria requerirse constancia separada de correccion.
6. Conservar matriz Excel historica y agregar export verificable separado. Coste: mantener etiquetas claras para dos formatos.
7. Compensacion legacy reduce responsabilidad sin inventar stock de origen incierto. Coste: ajuste de inventario conciliado separado cuando proceda.
8. No incluir reparaciones globales de tests/estilo de otros modulos. Coste: integracion y liberacion siguen condicionadas a saneamiento global.
9. Copia realista/backup/restauracion no juzgados por revision: se exige ensayo antes de produccion, sin simularlo. Coste: no hay aprobacion de despliegue todavia.
10. Avisos de dependencias preexistentes se registran sin actualizar masivamente el lock. Composer reporto 51 advisories en 15 paquetes existentes. Coste: evaluar/remediar vulnerabilidades antes de liberar.
11. Proteccion append-only cubre contrato Eloquent, no SQL directo con credenciales de escritura. Coste: requiere controles operativos/DB adicionales si existen escritores externos.
12. PDF editado fuera del sistema no puede verificarse por apariencia; servidor autenticado y hashes son autoritativos. Coste: el receptor debe consultar el codigo interno.
13. Pendiente firmado es snapshot historico, no demanda neta. Tablero lo etiqueta explicitamente y no descuenta entregas posteriores sin enlace. Coste: conciliacion de cumplimiento pendiente para obtener demanda neta.

## Mejoras menores diferidas

- Etiqueta de variante de inventario puede mostrar hash en lugar de atributos legibles.
- Canvas de firma no ofrece equivalente por teclado; asistencia/testigo autorizado conserva requisitos y nunca transforma una negativa en aceptacion.

## Orden obligatorio de despliegue

1. Resolver/revisar puertas globales y avisos de seguridad; aprobar resultado del ensayo aislado.
2. Backup verificable de MySQL y storage privado; restaurar ambos en copia aislada y comprobar permisos.
3. Instalar desde composer.lock, publicar codigo + public/build y probar migraciones aditivas sobre copia realista.
4. Dry-run de backfill, revisar contadores; ejecutar real y repetir; no reabrir saldos compensados.
5. Configurar permisos directos/alcances, almacenes, cobertura y apertura conciliada con preview.
6. Ensayar lote parcial, rechazo, firma/testigo, devolucion a disponible/cuarentena, perdida, compensacion, reintentos concurrentes, PDF/Excel/QR y denegacion fuera de alcance.
7. Desplegar solo tras aprobar el ensayo. Ante incidencia, parar escrituras y preservar evidencia; no ejecutar down ni eliminar historial. Restaurar datos requiere reconciliar movimientos posteriores al backup.

## Fallos globales observados

La lista siguiente permite reproducir y comparar sin guardar HTML de respuestas ni datos sensibles. Se conserva la misma lista de 97 casos en las ejecuciones globales inmediatamente anterior y posterior a la correccion final. No demuestra que todos precedan al inicio de este plan.

- `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`
- `Tests\Feature\ImportPersonalLifecycleStateTest::test_imported_preparation_contract_dates_appear_in_contract_expirations`
- `Tests\Feature\LogisticaEppIntegrationTest::test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente`
- `Tests\Feature\MiningExamCatalogPageTest::test_all_catalog_routes_require_configuration_permission`
- `Tests\Feature\MiningExamCatalogPageTest::test_page_and_preview_do_not_modify_catalog_and_confirmation_requires_acceptance`
- `Tests\Feature\MiningExamCatalogPageTest::test_confirmation_rejects_expired_and_wrong_token`
- `Tests\Feature\MiningExamCatalogPageTest::test_missing_schema_shows_operational_warning_and_rejects_writes`
- `Tests\Feature\MiningExamIdentityIntegrationTest::test_group_suggests_origin_but_only_explicit_convalidation_changes_destination_with_null_grade`
- `Tests\Feature\MiningExamIdentityIntegrationTest::test_group_rejects_expired_foreign_worker_and_low_grade_sources`
- `Tests\Feature\MiningExamIdentityIntegrationTest::test_group_does_not_bypass_grade_requirement_or_disabled_convalidation`
- `Tests\Feature\ParadaHerramientaServiceTest::test_guarda_y_envia_lista_de_herramientas_por_grupo`
- `Tests\Feature\ParadaHerramientaServiceTest::test_actualiza_cantidades_entregadas_y_recibidas_del_pedido`
- `Tests\Feature\ParadaHerramientaServiceTest::test_modo_entrega_y_recepcion_no_se_pisan_entre_si`
- `Tests\Feature\ParadaHerramientaServiceTest::test_entrega_solo_se_registra_cuando_la_parada_inicio_y_guarda_incidencia`
- `Tests\Feature\ParadaHerramientaServiceTest::test_recepcion_final_guarda_fecha_estado_y_observacion`
- `Tests\Feature\PersonalAntiguoRegularizationTest::test_cesado_existente_regularizado_como_vigente_no_queda_cesado_y_aparece_en_vencimientos`
- `Tests\Feature\PersonalContractStatusHighlightTest::test_listado_resalta_columna_contrato_segun_estado_documental`
- `Tests\Feature\PersonalContractStatusHighlightTest::test_burbuja_adjuntar_contrato_firma_contrato_editable_y_limpia_aviso`
- `Tests\Feature\PersonalContractStatusHighlightTest::test_contrato_cerrado_prioriza_estado_cesado_sobre_inactivo`
- `Tests\Feature\PersonalContratoFormatoServiceTest::test_contract_format_routes_return_templates_preview_and_download`
- `Tests\Feature\PersonalContratoFormatoServiceTest::test_signed_contract_upload_activates_worker_after_download`
- `Tests\Feature\PersonalContratoFormatoServiceTest::test_contract_data_update_redirects_to_personal_list`
- `Tests\Feature\PersonalContratoFormatoServiceTest::test_signed_contract_can_be_downloaded_from_documents_view`
- `Tests\Feature\PersonalContratoNotRenewedClosureTest::test_ruta_respeta_permiso_actualizar`
- `Tests\Feature\PersonalContratoRenewalTest::test_historial_solo_muestra_boton_de_subir_pdf_para_contrato_en_preparacion`
- `Tests\Feature\PersonalContratoRenewalTest::test_detalle_de_contrato_muestra_documento_firmado_solo_si_existe_pdf`
- `Tests\Feature\PersonalContratoRenewalTest::test_descarga_documento_firmado_del_contrato_historico_exacto`
- `Tests\Feature\PersonalContratoRenewalTest::test_historial_de_contratos_muestra_catalogo_de_puestos_al_editar`
- `Tests\Feature\PersonalContratoRenewalTest::test_editar_contrato_rechaza_puesto_fuera_del_catalogo`
- `Tests\Feature\PersonalContratoRenewalTest::test_editar_contrato_rechaza_tipo_de_contrato_fuera_del_catalogo`
- `Tests\Feature\PersonalDocumentoDownloadTest::test_individual_document_download_still_works`
- `Tests\Feature\PersonalDocumentoDownloadTest::test_route_permissions_and_validations_for_bulk_download`
- `Tests\Feature\PersonalFichaObservedResubmitTest::test_public_submit_allows_missing_documents_for_later_regularization`
- `Tests\Feature\PersonalListaNegraTest::test_usuario_con_permiso_agrega_trabajador_a_lista_negra_sin_cambiar_estado`
- `Tests\Feature\PersonalListaNegraTest::test_usuario_con_permiso_quita_trabajador_de_lista_negra_sin_cambiar_estado`
- `Tests\Feature\PersonalListaNegraTest::test_listado_mantiene_visible_lista_negra_aunque_filtre_estado`
- `Tests\Feature\PersonalMinaExamenesMinerosTest::test_reintentos_permiten_superar_el_maximo_y_mantienen_no_habilitado_si_desaprueba`
- `Tests\Feature\PersonalMinaExamenesMinerosTest::test_nota_minima_y_no_aplica`
- `Tests\Feature\PersonalMinaExamenesMinerosTest::test_convalidacion_requiere_permiso_pero_no_scope_de_mina`
- `Tests\Feature\PersonalMinaExamenesMinerosTest::test_convalidacion_rechaza_destino_inactivo_aunque_conserve_historial`
- `Tests\Feature\PersonalMinaHabilitacionCorrectedFlowTest::test_registro_de_intento_bloquea_examen_antes_de_asignar_numero`
- `Tests\Feature\PersonalMinaHabilitacionCorrectedFlowTest::test_intentos_manuales_conservan_numeros_consecutivos_aun_si_superan_el_maximo`
- `Tests\Feature\PersonalMinaHabilitacionCorrectedFlowTest::test_fallo_al_guardar_intento_nuevo_elimina_archivo_subido_y_revierte_datos`
- `Tests\Feature\PersonalMinaHabilitacionCorrectedFlowTest::test_fallo_al_completar_programado_elimina_archivo_y_conserva_programacion`
- `Tests\Feature\PersonalMinaHabilitacionCorrectedFlowTest::test_examen_aprobado_que_ya_vencio_puede_rendirse_otra_vez`
- `Tests\Feature\PersonalMinaHabilitacionCorrectedFlowTest::test_acciones_de_trabajador_diferencian_asignar_y_gestionar_examenes`
- `Tests\Feature\PersonalPuestoManagementTest::test_boton_puestos_aparece_en_acciones_de_personal`
- `Tests\Feature\PersonalPuestoManagementTest::test_crea_puesto_con_funciones`
- `Tests\Feature\PersonalPuestoManagementTest::test_editar_puesto_mantiene_id_y_actualiza_trabajadores_enlazados`
- `Tests\Feature\PersonalPuestoManagementTest::test_elimina_puesto_sin_trabajadores_asociados`
- `Tests\Feature\PersonalPuestoManagementTest::test_no_elimina_puesto_con_trabajadores_asociados`
- `Tests\Feature\PersonalRegularizationDashboardTest::test_ceased_section_links_to_reentry_without_creating_or_changing_a_contract`
- `Tests\Feature\RQMinaApiTest::test_crea_rq_mina_en_borrador`
- `Tests\Feature\RQMinaApiTest::test_calcula_backup_y_total_del_pedido_de_personal`
- `Tests\Feature\RQMinaApiTest::test_crea_rq_mina_con_destino_taller_y_transporte`
- `Tests\Feature\RQMinaApiTest::test_crea_rq_mina_con_supervisor_a_cargo`
- `Tests\Feature\RQMinaApiTest::test_crea_rq_mina_con_plan_operativo_semanal`
- `Tests\Feature\RQMinaApiTest::test_actualizar_payload_legacy_no_duplica_plan_inicial`
- `Tests\Feature\RQMinaApiTest::test_no_permite_utilizar_plan_de_otra_parada`
- `Tests\Feature\RQMinaApiTest::test_ensure_default_plan_es_idempotente_y_valida_rango`
- `Tests\Feature\RQMinaApiTest::test_plan_no_puede_terminar_fuera_de_parada_ni_invertir_fechas`
- `Tests\Feature\RQMinaApiTest::test_reemplazar_plan_uno_no_elimina_grupos_de_otro_plan`
- `Tests\Feature\RQMinaApiTest::test_grupos_legacy_sin_plan_siguen_en_respuesta_legacy`
- `Tests\Feature\RQMinaApiTest::test_comando_backfill_planes_es_idempotente_y_respeta_dry_run`
- `Tests\Feature\RQMinaApiTest::test_opciones_de_campos_rq_mina_se_guardan_y_eliminan`
- `Tests\Feature\RQMinaApiTest::test_crea_rq_mina_con_destino_oficina`
- `Tests\Feature\RQMinaApiTest::test_no_deja_editar_rq_mina_cerrado`
- `Tests\Feature\RQMinaApiTest::test_usuario_sin_scope_no_puede_ver_rq_mina`
- `Tests\Feature\RQMinaApiTest::test_creador_puede_editar_rq_mina_en_borrador`
- `Tests\Feature\RQMinaApiTest::test_otro_usuario_sin_privilegios_no_puede_editar`
- `Tests\Feature\RQMinaApiTest::test_enviar_cambia_estado_de_borrador_a_enviado`
- `Tests\Feature\RQMinaApiTest::test_enviar_crea_rq_proserge_para_atencion_rrhh`
- `Tests\Feature\RQMinaApiTest::test_web_permite_eliminar_rq_enviado_con_dependencias_operativas`
- `Tests\Feature\RQMinaApiTest::test_web_permite_eliminar_rq_aunque_la_parada_ya_termino`
- `Tests\Feature\RQMinaApiTest::test_listado_muestra_eliminar_para_rq_enviado_aunque_haya_terminado`
- `Tests\Feature\RQMinaApiTest::test_reducir_pedido_retira_ultimas_asignaciones_y_registra_cambio`
- `Tests\Feature\RQMinaPlanManagementTest::test_codigos_son_correlativos_por_parada_y_respetan_rango`
- `Tests\Feature\RQMinaPlanManagementTest::test_edita_plan_y_bloquea_archivado`
- `Tests\Feature\RQMinaPlanManagementTest::test_no_reduce_rango_si_existen_turnos_o_transportes_fuera`
- `Tests\Feature\RQMinaPlanManagementTest::test_seleccion_query_muestra_legacy_solo_en_plan_inicial`
- `Tests\Feature\RQMinaPlanManagementTest::test_guardar_plan_dos_no_elimina_plan_uno_y_payload_legacy_opera_plan_inicial`
- `Tests\Feature\RQMinaPlanManagementTest::test_guardar_plan_conserva_la_atencion_logistica_registrada`
- `Tests\Feature\RQMinaPlanManagementTest::test_guardar_plan_con_ids_reutiliza_las_filas_y_conserva_el_historial`
- `Tests\Feature\RQMinaPlanManagementTest::test_duplicar_copia_estructura_desplaza_fechas_y_limpia_ejecucion`
- `Tests\Feature\RQMinaPlanManagementTest::test_duplicacion_insuficiente_hace_rollback`
- `Tests\Feature\RQMinaPlanManagementTest::test_reglas_de_archivo_y_permisos_web`
- `Tests\Feature\RQMinaPlanManagementTest::test_no_opera_plan_de_otra_parada_por_ruta_web`
- `Tests\Feature\WebPermissionMiddlewareTest::test_main_module_entry_routes_are_protected_by_view_permission`
- `Tests\Feature\WebPermissionMiddlewareTest::test_admin_role_cannot_drop_users_and_roles_permissions`
- `Tests\Feature\WebPermissionMiddlewareTest::test_admin_role_cannot_be_deactivated`
- `Tests\Feature\WebPermissionMiddlewareTest::test_last_active_admin_user_cannot_be_deactivated`
- `Tests\Feature\WebPermissionMiddlewareTest::test_last_active_admin_user_cannot_be_moved_to_non_admin_role`
- `Tests\Feature\WebPermissionMiddlewareTest::test_usuario_scope_uses_scope_permission_instead_of_configurar`
- `Tests\Feature\WebPermissionMiddlewareTest::test_eliminar_usuario_is_restricted_to_superadmin`
- `Tests\Feature\WebPermissionMiddlewareTest::test_usuario_scope_empty_and_sync`
- `Tests\Feature\WebPermissionMiddlewareTest::test_api_eliminar_usuario_endpoints`
- `Tests\Feature\WebPermissionMiddlewareTest::test_bienestar_bloqueo_actions_respect_specific_permissions`
