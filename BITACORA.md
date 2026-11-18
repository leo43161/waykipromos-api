# Bitácora · API

Registro de sesiones de trabajo. Lo más nuevo arriba. Formato: fecha, qué se hizo, decisiones, pendientes.

---

## 2026-11-18 — Conexión a BD, estructura base y autenticación Google / JWT

**Hecho**
- Se creó la rama `feat/conexion-y-estructura-base`.
- Configuración segura: `public/config.php` (ignorado en git) con credenciales provistas y `public/config.example.php` como plantilla.
- Reglas de seguridad en `public/.htaccess` para bloquear descargas de `config.php`, `config.example.php` y la carpeta `lib/`.
- Módulo `public/lib/db.php`: Conexión MySQL mediante PDO nativo con Singleton, modo de errores con excepciones y diagnóstico `check_db()`.
- Módulo `public/lib/jwt.php`: Implementación nativa de JWT HS256 (RFC 7515) sin librerías externas.
- Módulo `public/lib/response.php`: Helpers estandarizados para respuestas JSON y lectura de payload.
- Módulo `public/lib/auth.php`: Validación nativa de Google ID Token vía HTTPS, soporte para extraer Bearer token y middleware de autorización.
- Endpoints en `public/index.php`:
  - `GET /status` y `GET /salud` con diagnóstico de servicio y estado de base de datos.
  - `POST /auth/google` para validación de tokens de Google y emisión de sesión JWT Wayki.
  - `POST /auth/dev-login` para pruebas en desarrollo.
  - `GET /auth/me` protegido por Bearer token.
- Pruebas unitarias y de integración de endpoints superadas con 100% de éxito.

**Decisiones**
- Toda la lógica reside dentro de `public/` (en `public/lib/`) para ser 100% compatible con el workflow de empaquetado y deploy a cPanel.
- Cero dependencias de terceros ni Composer: se aprovecha PDO y las funciones criptográficas nativas de PHP (`hash_hmac`).

**Pendientes**
- Cargar `GOOGLE_CLIENT_ID` real en `public/config.php` cuando esté disponible.
- Continuar con los endpoints de cupones y canje.

---

## 2026-11-17 — Entorno y deploy

**Hecho**
- Router mínimo en `public/index.php` (`GET /` y `GET /salud`), `.htaccess` con reescritura a `index.php`.
- `.github/workflows/deploy.yml` copiado del turista: `php -l` → ZIP de `public/` → FTP a `/api/` →
  `deploy/descomprimir.php` (subido a mano a `public_html/waykipromo/api/`) lo extrae.

**Decisiones**
- `descomprimir.php` fuera de `public/` para que el ZIP no lo pise.
- El secreto del deploy va solo por cabecera `X-Deploy-Secret`, no en la URL.

**Pendientes**
- Subir `descomprimir.php` con el secreto y cargar los secrets en GitHub.
- Endpoints reales (`TODO(backend)` en `index.php`).
