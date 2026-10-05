-- Backup previo. Elegir la base del ambiente en phpMyAdmin.
-- Instala estructura solamente; no copia ni modifica resultados existentes.
CREATE TABLE IF NOT EXISTS examen_minero_asociaciones (
 id CHAR(36) NOT NULL PRIMARY KEY, variante_id CHAR(36) NOT NULL,
 principal_id CHAR(36) NOT NULL, usuario_id CHAR(36) NULL, motivo VARCHAR(1000) NOT NULL,
 created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 UNIQUE KEY examen_minero_asociaciones_variante_id_unique (variante_id),
 KEY examen_minero_asociaciones_principal_id_index (principal_id),
 CONSTRAINT fk_exam_variant FOREIGN KEY (variante_id) REFERENCES examenes_mineros(id) ON DELETE RESTRICT,
 CONSTRAINT fk_exam_principal FOREIGN KEY (principal_id) REFERENCES examenes_mineros(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS examen_minero_aliases (
 id CHAR(36) NOT NULL PRIMARY KEY, examen_id CHAR(36) NOT NULL, mina_id CHAR(36) NULL,
 scope_key VARCHAR(36) NOT NULL DEFAULT 'global', nombre VARCHAR(191) NOT NULL,
 clave CHAR(64) NOT NULL, usuario_id CHAR(36) NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
 KEY examen_minero_aliases_examen_id_index (examen_id), UNIQUE KEY uq_exam_alias_scope (clave,scope_key),
 CONSTRAINT fk_exam_alias_exam FOREIGN KEY (examen_id) REFERENCES examenes_mineros(id) ON DELETE RESTRICT,
 CONSTRAINT fk_exam_alias_mine FOREIGN KEY (mina_id) REFERENCES minas(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS examen_minero_catalogo_cambios (
 id CHAR(36) NOT NULL PRIMARY KEY, usuario_id CHAR(36) NOT NULL,
 accion VARCHAR(30) NOT NULL, motivo VARCHAR(1000) NOT NULL,
 antes_json JSON NOT NULL, despues_json JSON NOT NULL,
 created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
