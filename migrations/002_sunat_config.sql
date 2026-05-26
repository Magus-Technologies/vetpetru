-- =============================================================
-- 002 — Configuración SUNAT en tabla configuracion
-- Credenciales SOL, modo, API URL, flag de certificado .pem
-- =============================================================
ALTER TABLE `configuracion`
    ADD COLUMN `sunat_usuario_sol`  VARCHAR(45)  NULL DEFAULT NULL AFTER `clave`,
    ADD COLUMN `sunat_clave_sol`    VARCHAR(45)  NULL DEFAULT NULL AFTER `sunat_usuario_sol`,
    ADD COLUMN `sunat_modo`        VARCHAR(20)   NULL DEFAULT 'beta' AFTER `sunat_clave_sol`,
    ADD COLUMN `sunat_api_url`     VARCHAR(255)  NULL DEFAULT NULL AFTER `sunat_modo`,
    ADD COLUMN `certificado_subido` TINYINT(1)   NOT NULL DEFAULT 0 AFTER `sunat_api_url`,
    ADD COLUMN `certificado_fecha`  DATETIME     NULL DEFAULT NULL AFTER `certificado_subido`;