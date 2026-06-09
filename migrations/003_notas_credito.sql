-- Migration 003: Tabla notas_credito
-- Notas de Crédito y Débito electrónicas (SUNAT)

CREATE TABLE IF NOT EXISTS notas_credito (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  venta_id      INT NOT NULL,
  tipo_nota     ENUM('credito','debito') NOT NULL DEFAULT 'credito',
  serie         VARCHAR(4) NOT NULL,
  numero        INT UNSIGNED NOT NULL DEFAULT 1,
  cod_motivo    VARCHAR(5) NOT NULL,
  des_motivo    VARCHAR(250) NOT NULL,
  total         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  aplica_igv    TINYINT(1) NOT NULL DEFAULT 1,
  sunat_xml     MEDIUMTEXT NULL,
  sunat_hash    VARCHAR(100) NULL,
  sunat_qr      TEXT NULL,
  sunat_cdr     TEXT NULL,
  sunat_estado  ENUM('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
  sunat_mensaje VARCHAR(1000) NULL,
  sunat_fecha   DATETIME NULL,
  estado        ENUM('activa','anulada') NOT NULL DEFAULT 'activa',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_venta_id (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
