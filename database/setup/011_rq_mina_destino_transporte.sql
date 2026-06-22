ALTER TABLE rq_mina
  ADD COLUMN IF NOT EXISTS destino_tipo VARCHAR(20) NULL AFTER mina_id,
  ADD COLUMN IF NOT EXISTS destino_id CHAR(36) NULL AFTER destino_tipo,
  ADD COLUMN IF NOT EXISTS destino_nombre VARCHAR(191) NULL AFTER destino_id,
  ADD COLUMN IF NOT EXISTS supervisor_id CHAR(36) NULL AFTER destino_nombre,
  ADD COLUMN IF NOT EXISTS supervisor_pets_id CHAR(36) NULL AFTER supervisor_id;

ALTER TABLE rq_mina
  ADD INDEX IF NOT EXISTS idx_rq_mina_supervisor (supervisor_id),
  ADD INDEX IF NOT EXISTS idx_rq_mina_supervisor_pets (supervisor_pets_id);

UPDATE rq_mina rm
LEFT JOIN minas m ON m.id = rm.mina_id
SET
  rm.destino_tipo = COALESCE(rm.destino_tipo, 'MINA'),
  rm.destino_id = COALESCE(rm.destino_id, rm.mina_id),
  rm.destino_nombre = COALESCE(rm.destino_nombre, m.nombre)
WHERE rm.destino_tipo IS NULL
   OR rm.destino_id IS NULL
   OR rm.destino_nombre IS NULL;

CREATE TABLE IF NOT EXISTS rq_mina_transporte_detalle (
  id CHAR(36) NOT NULL,
  rq_mina_id CHAR(36) NOT NULL,
  transporte VARCHAR(191) NOT NULL,
  cantidad INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rq_mina_transporte_rq (rq_mina_id),
  CONSTRAINT fk_rq_mina_transporte_rq FOREIGN KEY (rq_mina_id) REFERENCES rq_mina(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
