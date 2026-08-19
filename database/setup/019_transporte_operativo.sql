ALTER TABLE rq_mina_actividad_transportes
  ADD COLUMN IF NOT EXISTS rq_mina_plan_id CHAR(36) NULL AFTER actividad_id,
  ADD COLUMN IF NOT EXISTS fecha DATE NULL AFTER unidad_carga,
  ADD COLUMN IF NOT EXISTS turno VARCHAR(10) NULL AFTER fecha,
  ADD COLUMN IF NOT EXISTS tipo_transporte VARCHAR(20) NULL AFTER turno,
  ADD COLUMN IF NOT EXISTS capacidad_requerida INT UNSIGNED NULL AFTER tipo_transporte,
  ADD COLUMN IF NOT EXISTS cantidad_unidades_requeridas INT UNSIGNED NULL AFTER capacidad_requerida,
  ADD COLUMN IF NOT EXISTS capacidad_camion VARCHAR(50) NULL AFTER placas_asignadas,
  ADD COLUMN IF NOT EXISTS origen_snapshot VARCHAR(191) NULL AFTER origen,
  ADD COLUMN IF NOT EXISTS destino_snapshot VARCHAR(191) NULL AFTER origen_snapshot,
  ADD COLUMN IF NOT EXISTS observaciones TEXT NULL AFTER indicaciones,
  ADD COLUMN IF NOT EXISTS doc_vehiculo_path TEXT NULL AFTER recepcion_observacion,
  ADD COLUMN IF NOT EXISTS doc_proserge_path TEXT NULL AFTER doc_vehiculo_path,
  ADD COLUMN IF NOT EXISTS doc_mantenimiento_path TEXT NULL AFTER doc_proserge_path,
  ADD COLUMN IF NOT EXISTS doc_checklist_path TEXT NULL AFTER doc_mantenimiento_path,
  ADD COLUMN IF NOT EXISTS documentos JSON NULL AFTER doc_checklist_path,
  ADD COLUMN IF NOT EXISTS created_by CHAR(36) NULL AFTER orden,
  ADD COLUMN IF NOT EXISTS updated_by CHAR(36) NULL AFTER created_by;

CREATE INDEX IF NOT EXISTS idx_rq_mina_act_trans_plan_fecha_turno
  ON rq_mina_actividad_transportes (rq_mina_plan_id, fecha, turno);

CREATE INDEX IF NOT EXISTS idx_rq_mina_act_trans_tipo
  ON rq_mina_actividad_transportes (tipo_transporte);

CREATE TABLE IF NOT EXISTS transporte_servicios (
  id CHAR(36) NOT NULL,
  rq_mina_id CHAR(36) NOT NULL,
  rq_mina_plan_id CHAR(36) NULL,
  tipo VARCHAR(20) NOT NULL DEFAULT 'PERSONAL',
  fecha DATE NOT NULL,
  turno VARCHAR(10) NOT NULL,
  tramo VARCHAR(30) NOT NULL DEFAULT 'IDA',
  transportista VARCHAR(191) NULL,
  tipo_vehiculo VARCHAR(120) NULL,
  placa VARCHAR(50) NULL,
  conductor_personal_id CHAR(36) NULL,
  conductor_nombre_snapshot VARCHAR(191) NULL,
  capacidad INT UNSIGNED NULL,
  hora_salida TIME NULL,
  hora_retorno TIME NULL,
  origen VARCHAR(191) NULL,
  destino VARCHAR(191) NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'BORRADOR',
  observaciones TEXT NULL,
  created_by CHAR(36) NULL,
  updated_by CHAR(36) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_trans_serv_rq_fecha_turno (rq_mina_id, fecha, turno),
  KEY idx_trans_serv_plan_fecha_turno (rq_mina_plan_id, fecha, turno),
  KEY idx_trans_serv_placa_fecha_turno (placa, fecha, turno, tramo),
  KEY idx_trans_serv_cond_fecha_turno (conductor_personal_id, fecha, turno, tramo),
  KEY idx_trans_serv_tipo_estado (tipo, estado),
  CONSTRAINT fk_trans_serv_rq FOREIGN KEY (rq_mina_id) REFERENCES rq_mina(id) ON DELETE CASCADE,
  CONSTRAINT fk_trans_serv_plan FOREIGN KEY (rq_mina_plan_id) REFERENCES rq_mina_planes(id) ON DELETE SET NULL,
  CONSTRAINT fk_trans_serv_conductor FOREIGN KEY (conductor_personal_id) REFERENCES personal(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transporte_servicio_alcances (
  id CHAR(36) NOT NULL,
  transporte_servicio_id CHAR(36) NOT NULL,
  rq_mina_actividad_grupo_id CHAR(36) NULL,
  rq_mina_actividad_id CHAR(36) NULL,
  grupo_trabajo_id CHAR(36) NULL,
  sait_snapshot VARCHAR(191) NULL,
  orden INT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_trans_alc_servicio (transporte_servicio_id),
  KEY idx_trans_alc_grupo_operativo (rq_mina_actividad_grupo_id),
  KEY idx_trans_alc_actividad (rq_mina_actividad_id),
  KEY idx_trans_alc_grupo_trabajo (grupo_trabajo_id),
  CONSTRAINT fk_trans_alc_servicio FOREIGN KEY (transporte_servicio_id) REFERENCES transporte_servicios(id) ON DELETE CASCADE,
  CONSTRAINT fk_trans_alc_grupo_operativo FOREIGN KEY (rq_mina_actividad_grupo_id) REFERENCES rq_mina_actividad_grupos(id) ON DELETE SET NULL,
  CONSTRAINT fk_trans_alc_actividad FOREIGN KEY (rq_mina_actividad_id) REFERENCES rq_mina_actividades(id) ON DELETE SET NULL,
  CONSTRAINT fk_trans_alc_grupo_trabajo FOREIGN KEY (grupo_trabajo_id) REFERENCES grupo_trabajo(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transporte_servicio_pasajeros (
  id CHAR(36) NOT NULL,
  transporte_servicio_id CHAR(36) NOT NULL,
  grupo_trabajo_detalle_id CHAR(36) NOT NULL,
  personal_id CHAR(36) NOT NULL,
  tramo VARCHAR(30) NOT NULL DEFAULT 'IDA',
  estado VARCHAR(30) NOT NULL DEFAULT 'ASIGNADO',
  asignado_por_id CHAR(36) NULL,
  asignado_at TIMESTAMP NULL,
  retirado_por_id CHAR(36) NULL,
  retirado_at TIMESTAMP NULL,
  motivo_retiro TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_trans_pas_serv_estado (transporte_servicio_id, estado),
  KEY idx_trans_pas_personal_estado (personal_id, estado),
  KEY idx_trans_pas_detalle_estado (grupo_trabajo_detalle_id, estado),
  CONSTRAINT fk_trans_pas_servicio FOREIGN KEY (transporte_servicio_id) REFERENCES transporte_servicios(id) ON DELETE CASCADE,
  CONSTRAINT fk_trans_pas_detalle FOREIGN KEY (grupo_trabajo_detalle_id) REFERENCES grupo_trabajo_detalle(id) ON DELETE CASCADE,
  CONSTRAINT fk_trans_pas_personal FOREIGN KEY (personal_id) REFERENCES personal(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transporte_servicio_eventos (
  id CHAR(36) NOT NULL,
  transporte_servicio_id CHAR(36) NULL,
  tipo VARCHAR(60) NOT NULL,
  estado_anterior VARCHAR(30) NULL,
  estado_nuevo VARCHAR(30) NULL,
  snapshot JSON NULL,
  observacion TEXT NULL,
  usuario_id CHAR(36) NULL,
  fecha_evento TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_trans_event_servicio (transporte_servicio_id),
  KEY idx_trans_event_tipo_fecha (tipo, fecha_evento),
  CONSTRAINT fk_trans_event_servicio FOREIGN KEY (transporte_servicio_id) REFERENCES transporte_servicios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
