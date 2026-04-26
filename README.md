# VetPro — Guía de Instalación
## Servidor: magus-ecommerce.com

---

## PASO 1 — Subir archivos

Sube la carpeta `vetpro/` completa a tu servidor vía FTP (FileZilla, etc.)
Ruta sugerida: `/public_html/vetpro/`
Quedará accesible en: `https://magus-ecommerce.com/vetpro`

---

## PASO 2 — Crear base de datos MySQL

1. Ingresa a **cPanel → phpMyAdmin**
2. Crea una nueva base de datos llamada `vetpro`
3. Crea un usuario MySQL (ej: `vetpro_user`) y asígnalo a esa base de datos con todos los privilegios
4. Importa el archivo `database.sql` desde phpMyAdmin → Importar

---

## PASO 3 — Configurar la conexión

Edita el archivo `includes/config.php` y cambia estos valores:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'vetpro');
define('DB_USER', 'vetpro_user');   // Tu usuario MySQL de cPanel
define('DB_PASS', 'TU_PASSWORD');   // Tu contraseña MySQL
```

Si tu instalación no está en `/vetpro/` ajusta también:
```php
define('BASE_URL', 'https://magus-ecommerce.com/vetpro');
```

---

## PASO 4 — Crear carpeta de uploads

Crea la carpeta `public/uploads/` y dale permisos 755 (o 775):
```
/public_html/vetpro/public/uploads/
```

---

## PASO 5 — Acceder al sistema

URL: `https://magus-ecommerce.com/vetpro/login.php`

Credenciales iniciales:
- **Admin:** admin@vetpro.pe / password
- **Veterinaria:** ana@vetpro.pe / password

> ⚠️ IMPORTANTE: Cambia las contraseñas después del primer ingreso.

---

## CAMBIAR CONTRASEÑAS

En phpMyAdmin, ejecuta:
```sql
USE vetpro;
UPDATE usuarios SET password = '$2y$10$NUEVO_HASH' WHERE email = 'admin@vetpro.pe';
```

Genera el hash con:
```php
echo password_hash('tu_nueva_password', PASSWORD_BCRYPT);
```

O crea un archivo temporal `hash.php` en el servidor:
```php
<?php echo password_hash('TU_PASSWORD_AQUI', PASSWORD_BCRYPT); ?>
```

---

## ESTRUCTURA DEL PROYECTO

```
vetpro/
├── .htaccess              # Seguridad Apache
├── index.php              # Router principal
├── login.php              # Inicio de sesión
├── logout.php             # Cierre de sesión
├── database.sql           # Base de datos completa
├── includes/
│   ├── config.php         # Configuración y BD
│   ├── header.php         # Layout: sidebar + topbar
│   └── footer.php         # Cierre de layout
├── modules/
│   ├── dashboard.php      # KPIs y resumen
│   ├── clientes.php       # Gestión de clientes ✅ completo
│   ├── mascotas.php       # Gestión de mascotas
│   ├── historial.php      # Historia clínica
│   ├── citas.php          # Agenda y citas
│   ├── vacunas.php        # Vacunación
│   ├── farmacia.php       # Inventario
│   ├── facturacion.php    # Ventas y boletas
│   ├── caja.php           # Caja diaria
│   ├── personal.php       # Staff y usuarios
│   ├── reportes.php       # Reportes y KPIs
│   ├── whatsapp.php       # WhatsApp Web ✅ completo
│   └── portal.php         # Portal del cliente
└── public/
    ├── css/main.css        # Estilos
    ├── js/main.js          # JavaScript
    └── uploads/            # Fotos y archivos (crear manualmente)
```

---

## MÓDULOS COMPLETOS EN ESTA VERSIÓN

| Módulo | Estado |
|--------|--------|
| Login / sesión | ✅ Completo |
| Dashboard con KPIs | ✅ Completo |
| Gestión de Clientes | ✅ Completo |
| WhatsApp Web | ✅ Completo |
| Base de datos | ✅ Completa (todas las tablas) |
| Demás módulos | 🔧 Estructura lista, lógica CRUD a implementar |

Todos los módulos faltantes siguen exactamente el mismo patrón que `clientes.php`.

---

## REQUISITOS DEL SERVIDOR

- PHP 7.4 o superior (recomendado 8.x)
- MySQL 5.7 o superior (o MariaDB 10.3+)
- Módulo mod_rewrite habilitado (Apache)
- Extensiones PHP: PDO, PDO_MySQL, mbstring, json

---

## SOPORTE

Sistema desarrollado para magus-ecommerce.com
VetPro v1.0 — 2025
