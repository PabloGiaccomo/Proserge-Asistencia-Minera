ALTER TABLE personal_ficha_familiares
    ADD COLUMN IF NOT EXISTS fecha_nacimiento DATE NULL AFTER parentesco;
