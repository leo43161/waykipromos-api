# Bitácora · API

Registro de sesiones de trabajo. Lo más nuevo arriba. Formato: fecha, qué se hizo, decisiones, pendientes.

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
