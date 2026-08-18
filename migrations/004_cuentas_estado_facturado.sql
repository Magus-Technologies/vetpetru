-- Migration 004: Agregar estado 'facturado' a cuentas por cobrar
-- Permite que una cuenta se marque como 'facturado' al emitir comprobante
-- desde el modulo de facturacion (flujo cuentas -> cobrar -> facturacion).
--
-- Antes:  enum('abierta','cerrada','anulada')
-- Ahora:  enum('abierta','facturado','cerrada','anulada')
--
-- Nota: la tabla cuentas usa charset latin1_swedish_ci (no utf8mb4).
-- Respetamos el charset original para no romper consistencia.

ALTER TABLE `cuentas`
  MODIFY COLUMN `estado`
  ENUM('abierta','facturado','cerrada','anulada')
  CHARACTER SET latin1 COLLATE latin1_swedish_ci
  NULL DEFAULT 'abierta';
