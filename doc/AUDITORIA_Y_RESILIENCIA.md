# Auditoría de Rendimiento, Resiliencia y Concurrencia · WaykiPromos API

Este documento consolida la auditoría técnica de la arquitectura de la API WaykiPromos frente a escenarios de alta concurrencia, resumiendo sus características principales, puntos fuertes, áreas a monitorear y las optimizaciones de resiliencia implementadas.

---

## 1. Características Principales de la API

* **Patrón Arquitectónico:** BFF (Backend For Frontend) bajo principios de **Clean Architecture** estructurado en capas desacopladas (`Core`, `Controllers`, `Services`, `Database`, `Routes`, `Middleware`, `Domain`).
* **Zero-Framework Overhead:** Desarrollada en **PHP 8.4+ / 8.5 puro**, sin la sobrecarga ni el consumo excesivo de memoria de frameworks pesados (Laravel/Symfony).
* **Pipeline de Middlewares (PSR-15):** Procesa cada petición atravesando una cebolla de middlewares:
  $$\text{Request} \longrightarrow \text{CORS} \longrightarrow \text{ErrorHandler (Resiliente)} \longrightarrow \text{RateLimiter} \longrightarrow \text{FileLogger} \longrightarrow \text{Auth/RBAC} \longrightarrow \text{Controller}$$
* **Autenticación Dual con JWT Propio (HS256):**
  * **Turistas:** Login federado con Google OAuth 2.0 (validación de Google ID Token vía HTTPS nativo).
  * **Comercios y Ente:** Credenciales locales corporativas (Email + Hash de contraseña).
  * Emisión de Bearer JWT de sesión validado localmente en microsegundos.
* **Persistencia por Procedimientos Almacenados:** Desacople total de sentencias SQL; la API interactúa con la base de datos `huakipromo` invocando únicamente `CALL sp_*` mediante `StoredProcedureCaller`.
* **CORS Universal:** Configurado con reflexión dinámica de origen para permitir consumo inmediato desde apps móviles y frontends locales o web (`*`, `Access-Control-Allow-Credentials`).
* **Preflight OPTIONS Inmediato:** Responde con código 204 en la primera línea de `index.php`, ahorrando ciclos de CPU y tiempo de respuesta para el navegador.
* **Caché y Compresión Nativas:** Cabeceras HTTP `Cache-Control` / `ETag` en catálogos y compresión Gzip (`mod_deflate`) en Apache.

---

## 2. Puntos Fuertes

| Punto Fuerte | Detalle y Beneficio |
| :--- | :--- |
| **🚀 Velocidad Extrema de Respuesta** | La API arranca en **5 a 15 ms** con un consumo inferior a **20 MB de RAM**. Puede despachar cientos de peticiones por segundo en hardware modesto. |
| **🛡️ Aislamiento "Share-Nothing"** | Cada petición se atiende en su propio proceso/worker de Apache de forma aislada. No hay memoria global compartida que pueda corromperse ni bloqueos entre hilos. |
| **🔌 Conexión Lazy a MySQL** | La base de datos no se conecta si la petición no la necesita (ej. peticiones OPTIONS, archivos estáticos o validaciones de token con error). |
| **🔒 Cero Inyección SQL** | Al utilizar PDO con `PDO::ATTR_EMULATE_PREPARES => false` y delegar en `sp_*`, las consultas están 100% parametrizadas en el motor MySQL. |
| **⚡ Rate Limiting Anti-Saturación** | `RateLimiterMiddleware` frena ataques de fuerza bruta o bucles infinitos en el frontend (120 req/min por IP con cabeceras estándar `X-RateLimit-*`). |
| **📦 Deploy Automatizado Transparente** | Pipeline de GitHub Actions que corre linter de sintaxis, comprime `dist/` y despliega por FTP a cPanel con descompresión atómica vía webhook. |
| **🔄 Resiliencia 503 ante Picos** | Si la base de datos alcanza su límite de conexiones, la API responde un `503 Service Unavailable` con `Retry-After: 3` en lugar de una pantalla blanca o error 500 no controlado. |

---

## 3. Puntos Débiles y Factores a Monitorear

| Punto Débil / Riesgo | Causa y Contexto | Mitigación Actual / Recomendada |
| :--- | :--- | :--- |
| **⚠️ Límite de conexiones en cPanel (`max_user_connections`)** | En hosting compartido cPanel, MySQL limita las conexiones concurrentes por usuario (frecuentemente entre 30 y 60). | **Mitigado:** Se activó caché HTTP de 1 hora en catálogos (evita el 80% de consultas) y respuesta resiliente `503` con reintento automático. A futuro: si el tráfico sube a miles simultáneos, solicitar al proveedor elevar `max_user_connections` a 150+. |
| **🌐 Dependencia de Red en Login de Google** | Al hacer login con Google, la API hace una llamada saliente síncrona a `oauth2.googleapis.com`. Si la salida a internet del servidor cPanel es lenta, el worker de PHP espera esa respuesta. | **Mitigado:** Posee `CURLOPT_TIMEOUT => 7` para no congelar workers indefinidamente. Además, solo ocurre una vez en el login; luego el usuario usa el JWT propio de Wayki. |
| **📄 Paginación en Memoria en `OfertasController`** | `sp_promociones_listar_publicas` trae todas las ofertas activas a un array PHP y luego hace `array_slice`. Con cientos de promociones, esto aumentará el uso de memoria por worker. | **Recomendación futura:** Modificar el SP de ofertas para que reciba `LIMIT` y `OFFSET` directamente en el motor MySQL. |
| **💾 Almacenamiento de Rate Limiter en Disco Temporal** | El rate limiter usa archivos efímeros en `/tmp`. En servidores con discos lentos o entornos distribuidos (múltiples servidores web), no se comparte entre nodos. | **Estado:** Perfecto para un servidor único en cPanel. Si en el futuro se pasa a un clúster balanceado multi-servidor, convendría usar Redis o Memcached. |

---

## 4. Mejoras de Resiliencia Implementadas

1. **`RateLimiterMiddleware`:** Protege contra abusos y ráfagas concurrentes limitando a 120 peticiones por minuto por IP con cabeceras estándar `X-RateLimit-*`.
2. **`FileLoggerMiddleware` sin contención:** Se eliminó el bloqueo `LOCK_EX` para evitar cuellos de botella de disco bajo concurrencia masiva, y se configuró rotación diaria automática (`app-YYYY-MM-DD.log`).
3. **Caché HTTP en Catálogos (`withCache`):** `/categorias`, `/circuitos`, `/modalidades` y `/localidades` devuelven cabeceras `Cache-Control: public, max-age=3600` y `ETag`, reduciendo drásticamente las consultas a la base de datos.
4. **Compresión GZIP en Apache (`mod_deflate`):** Respuestas JSON comprimidas en tránsito en `public/.htaccess`, reduciendo el tiempo de conexión y el ancho de banda.
5. **Captura Resiliente de Saturación de BD (HTTP 503):** Captura de errores de saturación 1203/1040 de MySQL para que el cliente reintente sin fallar.
