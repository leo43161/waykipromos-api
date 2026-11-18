# API — backend PHP de Wayki Promo (prueba)

Leé también `../../CLAUDE.md` (contexto, roles, modelo de datos) y `README.md` (correr y deploy).

- PHP plano, sin framework ni Composer por ahora. Solo `public/` se publica; todo pasa por `public/index.php`.
- Producción: `https://tucumanturismo.gob.ar/waykipromo/api/`, mismo origen que los front (sin CORS).
  En local `php -S localhost:8000` (CORS solo para `localhost`).
- Credenciales solo en `public/config.php` del servidor (en `.gitignore`). `deploy/descomprimir.php` nunca con el secreto real.
- Acá se valida todo lo de seguridad: ID token de Google, permisos por rol, canje atómico e idempotente.
- Si se agrega Composer: sumar `composer install --no-dev` al yml antes del ZIP.
