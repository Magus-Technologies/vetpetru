-- ============================================================
-- VetPro - Sistema de Gestión Veterinaria
-- Base de Datos MySQL
-- Versión: 1.0
-- Servidor: magus-ecommerce.com
-- ============================================================

CREATE DATABASE IF NOT EXISTS vetpro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vetpro;

-- ============================================================
-- SEDES (Multi-sede)
-- ============================================================
CREATE TABLE sedes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    direccion VARCHAR(255),
    telefono VARCHAR(20),
    email VARCHAR(100),
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO sedes (nombre, direccion, telefono, email) VALUES
('Sede Principal', 'Av. Principal 234, Miraflores', '01-444-5678', 'principal@vetpro.pe'),
('Sede San Isidro', 'Calle Las Flores 890, San Isidro', '01-222-3456', 'sanisidro@vetpro.pe');

-- ============================================================
-- USUARIOS / PERSONAL
-- ============================================================
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sede_id INT DEFAULT 1,
    nombre VARCHAR(150) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    rol ENUM('admin','veterinario','asistente','recepcionista') DEFAULT 'recepcionista',
    especialidad VARCHAR(100),
    telefono VARCHAR(20),
    turno VARCHAR(100),
    activo TINYINT(1) DEFAULT 1,
    ultimo_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sede_id) REFERENCES sedes(id)
);

-- Password: admin123 (bcrypt)
INSERT INTO usuarios (sede_id, nombre, email, password, rol, especialidad, turno) VALUES
(1, 'Dr. Carlos Silva', 'admin@vetpro.pe', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Medicina Interna', 'L-V 8:00-17:00'),
(1, 'Dra. Ana Torres', 'ana@vetpro.pe', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'veterinario', 'Cirugía', 'L-S 9:00-18:00'),
(1, 'Luis Pérez', 'luis@vetpro.pe', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'asistente', 'Grooming', 'L-V 8:00-17:00'),
(1, 'Sofía Ríos', 'sofia@vetpro.pe', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'recepcionista', 'Administración', 'L-S 8:00-16:00');

-- ============================================================
-- CLIENTES
-- ============================================================
CREATE TABLE clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sede_id INT DEFAULT 1,
    nombre VARCHAR(150) NOT NULL,
    dni VARCHAR(20),
    ruc VARCHAR(20),
    telefono VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    direccion VARCHAR(255),
    como_conocio ENUM('referido','google','redes_sociales','otro') DEFAULT 'otro',
    notas TEXT,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sede_id) REFERENCES sedes(id)
);

INSERT INTO clientes (sede_id, nombre, dni, telefono, email, direccion) VALUES
(1, 'María García', '45231876', '987654321', 'maria@gmail.com', 'Av. Lima 234, Miraflores'),
(1, 'Carlos Rodríguez', '38901234', '999123456', 'carlos@gmail.com', 'Jr. Miraflores 890'),
(1, 'Ana López', '52314789', '956789012', 'ana@gmail.com', 'Calle Arequipa 45'),
(1, 'Pedro Martínez', '41256789', '943210987', 'pedro@gmail.com', 'Av. Brasil 567');

-- ============================================================
-- MASCOTAS
-- ============================================================
CREATE TABLE mascotas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    especie ENUM('perro','gato','conejo','ave','reptil','roedor','otro') NOT NULL,
    raza VARCHAR(100),
    sexo ENUM('macho','hembra') DEFAULT 'macho',
    fecha_nacimiento DATE,
    peso DECIMAL(5,2),
    color VARCHAR(100),
    chip_numero VARCHAR(50),
    foto VARCHAR(255),
    alergias TEXT,
    condiciones TEXT,
    estado ENUM('activo','fallecido','dado_en_adopcion') DEFAULT 'activo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
);

INSERT INTO mascotas (cliente_id, nombre, especie, raza, sexo, fecha_nacimiento, peso, alergias) VALUES
(1, 'Luna', 'perro', 'Golden Retriever', 'hembra', '2022-03-15', 28.0, 'Alergia al pollo'),
(1, 'Michi', 'gato', 'Siamés', 'hembra', '2020-06-20', 4.2, NULL),
(2, 'Rocky', 'perro', 'Labrador', 'macho', '2023-01-10', 32.0, NULL),
(3, 'Toby', 'perro', 'Poodle', 'macho', '2018-09-05', 5.0, NULL),
(3, 'Lola', 'conejo', 'Holandés', 'hembra', '2024-02-14', 1.8, NULL),
(4, 'Max', 'perro', 'Rottweiler', 'macho', '2021-07-22', 45.0, NULL);

-- ============================================================
-- CITAS
-- ============================================================
CREATE TABLE citas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sede_id INT DEFAULT 1,
    mascota_id INT NOT NULL,
    veterinario_id INT NOT NULL,
    tipo ENUM('consulta','vacuna','control','cirugia','bano','grooming','emergencia','hospitalizacion') DEFAULT 'consulta',
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    duracion_minutos INT DEFAULT 30,
    estado ENUM('pendiente','confirmada','atendida','cancelada','no_asistio') DEFAULT 'pendiente',
    motivo TEXT,
    notas TEXT,
    recordatorio_enviado TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sede_id) REFERENCES sedes(id),
    FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
    FOREIGN KEY (veterinario_id) REFERENCES usuarios(id)
);

INSERT INTO citas (sede_id, mascota_id, veterinario_id, tipo, fecha, hora, estado, motivo) VALUES
(1, 1, 1, 'consulta', CURDATE(), '08:30:00', 'pendiente', 'Revisión general'),
(1, 3, 2, 'vacuna', CURDATE(), '09:00:00', 'confirmada', 'Vacuna óctuple'),
(1, 4, 1, 'control', CURDATE(), '10:30:00', 'pendiente', 'Control cardíaco'),
(1, 2, 1, 'cirugia', CURDATE(), '11:00:00', 'confirmada', 'Esterilización');

-- ============================================================
-- HISTORIA CLÍNICA
-- ============================================================
CREATE TABLE consultas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cita_id INT NULL,
    mascota_id INT NOT NULL,
    veterinario_id INT NOT NULL,
    sede_id INT DEFAULT 1,
    tipo ENUM('consulta','control','emergencia','cirugia','vacuna','hospitalizacion') DEFAULT 'consulta',
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    peso_actual DECIMAL(5,2),
    temperatura DECIMAL(4,1),
    frecuencia_cardiaca INT,
    frecuencia_respiratoria INT,
    sintomas TEXT,
    diagnostico TEXT,
    tratamiento TEXT,
    observaciones TEXT,
    proximo_control DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cita_id) REFERENCES citas(id) ON DELETE SET NULL,
    FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
    FOREIGN KEY (veterinario_id) REFERENCES usuarios(id)
);

INSERT INTO consultas (mascota_id, veterinario_id, tipo, fecha, peso_actual, temperatura, sintomas, diagnostico, tratamiento) VALUES
(1, 1, 'consulta', '2025-04-12 08:45:00', 28.0, 38.5, 'Sacude la cabeza, se rasca las orejas', 'Otitis externa bilateral', 'Limpieza ótica + Otosan gotas 2 veces al día por 10 días'),
(3, 2, 'vacuna', '2025-04-10 09:20:00', 32.0, 38.2, 'Control rutinario', 'Control rutinario - sano', 'Vacuna Óctuple aplicada. Próxima en 1 año'),
(4, 1, 'control', '2025-04-08 10:30:00', 5.0, 38.6, 'Control mensual cardíaco', 'Cardiopatía estable', 'Enalapril 2.5mg cada 12 horas'),
(2, 2, 'consulta', '2025-04-05 11:00:00', 4.2, 38.8, 'Dificultad para orinar, llora', 'Urolitiasis', 'Dieta Hills c/d + hidratación forzada');

-- ============================================================
-- RECETAS MÉDICAS
-- ============================================================
CREATE TABLE recetas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consulta_id INT NOT NULL,
    mascota_id INT NOT NULL,
    veterinario_id INT NOT NULL,
    fecha DATE NOT NULL,
    indicaciones TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consulta_id) REFERENCES consultas(id),
    FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
    FOREIGN KEY (veterinario_id) REFERENCES usuarios(id)
);

CREATE TABLE receta_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receta_id INT NOT NULL,
    medicamento VARCHAR(200) NOT NULL,
    dosis VARCHAR(200),
    frecuencia VARCHAR(100),
    duracion VARCHAR(100),
    via VARCHAR(50),
    FOREIGN KEY (receta_id) REFERENCES recetas(id) ON DELETE CASCADE
);

-- ============================================================
-- VACUNAS
-- ============================================================
CREATE TABLE vacunas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mascota_id INT NOT NULL,
    veterinario_id INT NOT NULL,
    consulta_id INT NULL,
    tipo_vacuna VARCHAR(100) NOT NULL,
    laboratorio VARCHAR(100),
    lote VARCHAR(50),
    fecha_aplicacion DATE NOT NULL,
    fecha_vencimiento DATE,
    proxima_dosis DATE,
    recordatorio_enviado TINYINT(1) DEFAULT 0,
    notas TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mascota_id) REFERENCES mascotas(id),
    FOREIGN KEY (veterinario_id) REFERENCES usuarios(id)
);

INSERT INTO vacunas (mascota_id, veterinario_id, tipo_vacuna, laboratorio, lote, fecha_aplicacion, fecha_vencimiento, proxima_dosis) VALUES
(1, 1, 'Antirrábica', 'Zoetis', 'L2024-RA-01', '2025-01-15', '2027-01-15', '2026-01-15'),
(3, 2, 'Óctuple', 'Nobivac', 'L2025-OC-02', '2025-03-10', '2027-03-10', '2026-03-10'),
(4, 1, 'Antirrábica', 'Zoetis', 'L2024-RA-03', '2024-04-20', '2026-04-20', '2025-04-20'),
(2, 2, 'Triple Felina', 'Merial', 'L2025-TF-01', '2025-02-05', '2027-02-05', '2026-02-05'),
(6, 1, 'Óctuple', 'Nobivac', 'L2024-OC-05', '2024-10-12', '2026-10-12', '2025-04-12'),
(5, 2, 'Mixomatosis', 'Hipra', 'L2024-MX-01', '2024-12-18', '2026-12-18', '2025-12-18');

-- ============================================================
-- INVENTARIO / FARMACIA
-- ============================================================
CREATE TABLE categorias_producto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion TEXT
);

INSERT INTO categorias_producto (nombre) VALUES
('Antibiótico'),('Antiparasitario'),('Analgésico'),('Corticoide'),
('Nutrición'),('Accesorio'),('Vacuna'),('Antiséptico'),('Suplemento');

CREATE TABLE productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sede_id INT DEFAULT 1,
    categoria_id INT,
    nombre VARCHAR(200) NOT NULL,
    descripcion TEXT,
    presentacion VARCHAR(100),
    laboratorio VARCHAR(100),
    codigo_barras VARCHAR(50),
    stock INT DEFAULT 0,
    stock_minimo INT DEFAULT 5,
    precio_costo DECIMAL(10,2) DEFAULT 0,
    precio_venta DECIMAL(10,2) DEFAULT 0,
    lote VARCHAR(50),
    fecha_vencimiento DATE,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sede_id) REFERENCES sedes(id),
    FOREIGN KEY (categoria_id) REFERENCES categorias_producto(id)
);

INSERT INTO productos (sede_id, categoria_id, nombre, presentacion, stock, stock_minimo, precio_costo, precio_venta, lote, fecha_vencimiento) VALUES
(1, 1, 'Amoxicilina 500mg', 'Tabletas x100', 45, 20, 1.50, 2.50, 'L2024-09', '2025-09-30'),
(1, 2, 'Ivermectina 1%', 'Frasco 50ml', 8, 15, 3.00, 4.80, 'L2024-11', '2026-11-30'),
(1, 3, 'Meloxicam 15mg', 'Tabletas x50', 30, 10, 2.00, 3.20, 'L2025-01', '2027-01-31'),
(1, 3, 'Tramadol 50mg', 'Tabletas x30', 3, 10, 3.50, 5.50, 'L2024-08', '2025-08-31'),
(1, 4, 'Dexametasona 4mg', 'Ampolla x10', 22, 8, 4.00, 6.00, 'L2025-02', '2027-02-28'),
(1, 5, 'Royal Canin Medium Adult 3kg', 'Bolsa 3kg', 15, 5, 65.00, 89.90, NULL, '2025-12-31'),
(1, 5, 'Hills Science Diet c/d', 'Bolsa 1.8kg', 8, 3, 75.00, 105.00, NULL, '2026-03-31'),
(1, 2, 'Drontal Plus', 'Tabletas x10', 25, 10, 5.00, 8.00, 'L2025-03', '2027-03-31');

-- Kardex de movimientos de inventario
CREATE TABLE kardex (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    usuario_id INT NOT NULL,
    tipo ENUM('entrada','salida','ajuste','venta') NOT NULL,
    cantidad INT NOT NULL,
    stock_anterior INT NOT NULL,
    stock_nuevo INT NOT NULL,
    referencia VARCHAR(100),
    notas TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ============================================================
-- SERVICIOS
-- ============================================================
CREATE TABLE servicios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sede_id INT DEFAULT 1,
    nombre VARCHAR(200) NOT NULL,
    descripcion TEXT,
    tipo ENUM('consulta','cirugia','vacuna','bano','grooming','hospitalizacion','laboratorio','otro') DEFAULT 'consulta',
    precio DECIMAL(10,2) NOT NULL,
    duracion_minutos INT DEFAULT 30,
    activo TINYINT(1) DEFAULT 1,
    FOREIGN KEY (sede_id) REFERENCES sedes(id)
);

INSERT INTO servicios (sede_id, nombre, tipo, precio, duracion_minutos) VALUES
(1, 'Consulta general', 'consulta', 50.00, 30),
(1, 'Consulta especializada', 'consulta', 80.00, 45),
(1, 'Vacuna (aplicación)', 'vacuna', 15.00, 15),
(1, 'Baño y secado (mediano)', 'bano', 35.00, 60),
(1, 'Baño y corte (mediano)', 'bano', 50.00, 90),
(1, 'Cirugía menor', 'cirugia', 200.00, 120),
(1, 'Cirugía mayor', 'cirugia', 500.00, 240),
(1, 'Hospitalización (día)', 'hospitalizacion', 80.00, 0),
(1, 'Esterilización (hembra)', 'cirugia', 350.00, 180),
(1, 'Esterilización (macho)', 'cirugia', 200.00, 90),
(1, 'Ecografía', 'laboratorio', 120.00, 30),
(1, 'Hemograma completo', 'laboratorio', 85.00, 15);

-- ============================================================
-- VENTAS / FACTURACIÓN
-- ============================================================
CREATE TABLE ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sede_id INT DEFAULT 1,
    cliente_id INT NOT NULL,
    mascota_id INT NULL,
    consulta_id INT NULL,
    usuario_id INT NOT NULL,
    tipo_comprobante ENUM('boleta','factura','ticket') DEFAULT 'boleta',
    serie VARCHAR(10) DEFAULT 'B001',
    numero INT NOT NULL DEFAULT 0,        -- Se calcula desde PHP: MAX(numero)+1 por serie
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    subtotal DECIMAL(10,2) DEFAULT 0,
    igv DECIMAL(10,2) DEFAULT 0,
    descuento DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    metodo_pago ENUM('efectivo','yape','plin','tarjeta_debito','tarjeta_credito','transferencia') DEFAULT 'efectivo',
    estado ENUM('pendiente','pagado','anulado') DEFAULT 'pendiente',
    notas TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_serie_numero (serie, numero),   -- Evita duplicados por serie
    FOREIGN KEY (sede_id) REFERENCES sedes(id),
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE venta_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    tipo ENUM('producto','servicio') NOT NULL,
    referencia_id INT NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    cantidad INT DEFAULT 1,
    precio_unitario DECIMAL(10,2) NOT NULL,
    descuento DECIMAL(10,2) DEFAULT 0,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE
);

INSERT INTO ventas (sede_id, cliente_id, mascota_id, usuario_id, tipo_comprobante, serie, numero, subtotal, igv, total, metodo_pago, estado) VALUES
(1, 1, 1, 1, 'boleta', 'B001', 1, 72.03, 12.97, 85.00, 'yape', 'pagado'),
(1, 2, 3, 2, 'boleta', 'B001', 2, 38.14, 6.86, 45.00, 'efectivo', 'pagado'),
(1, 3, 4, 1, 'boleta', 'B001', 3, 101.69, 18.31, 120.00, 'tarjeta_debito', 'pagado'),
(1, 4, 6, 3, 'ticket', 'B001', 4, 29.66, 5.34, 35.00, 'efectivo', 'pendiente');

-- ============================================================
-- CAJA
-- ============================================================
CREATE TABLE cajas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sede_id INT DEFAULT 1,
    usuario_id INT NOT NULL,
    fecha_apertura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre DATETIME NULL,
    monto_apertura DECIMAL(10,2) DEFAULT 0,
    monto_cierre DECIMAL(10,2) NULL,
    estado ENUM('abierta','cerrada') DEFAULT 'abierta',
    notas TEXT,
    FOREIGN KEY (sede_id) REFERENCES sedes(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

CREATE TABLE movimientos_caja (
    id INT AUTO_INCREMENT PRIMARY KEY,
    caja_id INT NOT NULL,
    usuario_id INT NOT NULL,
    tipo ENUM('ingreso','egreso') NOT NULL,
    concepto VARCHAR(255) NOT NULL,
    monto DECIMAL(10,2) NOT NULL,
    metodo_pago ENUM('efectivo','yape','plin','tarjeta_debito','tarjeta_credito','transferencia') DEFAULT 'efectivo',
    categoria ENUM('servicio','producto','gasto_administrativo','compra_insumos','otro') DEFAULT 'servicio',
    venta_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (caja_id) REFERENCES cajas(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

INSERT INTO cajas (sede_id, usuario_id, monto_apertura, estado) VALUES (1, 1, 500.00, 'abierta');

-- ============================================================
-- WHATSAPP LOG
-- ============================================================
CREATE TABLE whatsapp_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    mascota_id INT NULL,
    usuario_id INT NOT NULL,
    tipo ENUM('cita','recibo','informe','historial','vacuna','recordatorio','receta','personalizado') NOT NULL,
    mensaje TEXT NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    url_generada TEXT,
    estado ENUM('generado','enviado') DEFAULT 'generado',
    referencia_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
);

-- ============================================================
-- ARCHIVOS CLÍNICOS
-- ============================================================
CREATE TABLE archivos_clinicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consulta_id INT NULL,
    mascota_id INT NOT NULL,
    tipo ENUM('radiografia','ecografia','analisis','foto','receta_pdf','certificado','otro') DEFAULT 'otro',
    nombre VARCHAR(255) NOT NULL,
    ruta VARCHAR(500) NOT NULL,
    tamanio INT,
    subido_por INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consulta_id) REFERENCES consultas(id),
    FOREIGN KEY (mascota_id) REFERENCES mascotas(id)
);

-- ============================================================
-- CONFIGURACIÓN DEL SISTEMA
-- ============================================================
CREATE TABLE configuracion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(100) UNIQUE NOT NULL,
    valor TEXT,
    descripcion VARCHAR(255)
);

INSERT INTO configuracion (clave, valor, descripcion) VALUES
('nombre_clinica', 'VetPro Veterinaria', 'Nombre de la clínica'),
('ruc_clinica', '20123456789', 'RUC de la clínica'),
('telefono_clinica', '01-444-5678', 'Teléfono principal'),
('email_clinica', 'info@vetpro.pe', 'Email de contacto'),
('direccion_clinica', 'Av. Principal 234, Miraflores, Lima', 'Dirección principal'),
('igv_porcentaje', '18', 'Porcentaje de IGV'),
('serie_boleta', 'B001', 'Serie de boletas'),
('serie_factura', 'F001', 'Serie de facturas'),
('logo_path', 'assets/logo.png', 'Ruta del logo'),
('recordatorio_dias_vacuna', '7', 'Días de anticipación para recordatorio de vacuna'),
('recordatorio_horas_cita', '24', 'Horas de anticipación para recordatorio de cita');

-- ============================================================
-- ÍNDICES PARA PERFORMANCE
-- ============================================================
CREATE INDEX idx_citas_fecha ON citas(fecha);
CREATE INDEX idx_citas_mascota ON citas(mascota_id);
CREATE INDEX idx_consultas_mascota ON consultas(mascota_id);
CREATE INDEX idx_vacunas_mascota ON vacunas(mascota_id);
CREATE INDEX idx_vacunas_proxima ON vacunas(proxima_dosis);
CREATE INDEX idx_ventas_cliente ON ventas(cliente_id);
CREATE INDEX idx_ventas_fecha ON ventas(fecha);
CREATE INDEX idx_productos_stock ON productos(stock, stock_minimo);
CREATE INDEX idx_whatsapp_cliente ON whatsapp_log(cliente_id);
