# Catálogo de Endpoints y Matriz de Trazabilidad · WaykiPromos

Mapeo exhaustivo entre la **Infraestructura Base**, los **Casos de Uso (CU-01 al CU-15)**, los **Endpoints de la API REST (BFF)** y los **Procedimientos Almacenados (`sp_*`)** de la base de datos `huakipromo`.

---

## 0. Convenciones Globales de la API

* **URL Base de Producción:** `https://tucumanturismo.gob.ar/waykipromo/api/`
* **URL Base de Desarrollo Local:** `http://localhost:8000/`
* **Prefijos Admitidos:**
  * Prefijo canónico versionado: `/api/v1/...`
  * Alias corto: `/v1/...`
  * Rutas base directas (compatibilidad frontend): `/salud`, `/health`, `/status`, `/auth/...`
* **Política CORS:** Totalmente irrestricta (`Access-Control-Allow-Origin: *` con reflexión dinámica de origen para soportar cookies/credenciales locales y producción). Métodos: `GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD`.
* **Formato de Envoltorio Estándar (Envelope):**
  * **Éxito (2xx):**
    ```json
    {
      "ok": true,
      "data": { ... }
    }
    ```
  * **Error (4xx / 5xx):**
    ```json
    {
      "ok": false,
      "error": {
        "codigo": "NO_AUTORIZADO",
        "mensaje": "Token de autorización requerido en cabecera Bearer.",
        "detalles": null
      }
    }
    ```

---

## 1. Módulo Diagnóstico, Estado y Salud

| Endpoint | Método | Rol Mínimo | Descripción Técnica |
| :--- | :---: | :---: | :--- |
| `/salud`<br>`/api/v1/salud`<br>*(alias: `/health`, `/status`, `/v1/salud`, `/v1/health`)* | `GET` | **Público** | Comprobación viva del servicio y diagnóstico en tiempo real de la conexión MySQL a `huakipromo`. Devuelve HTTP 200 si la base está conectada y 503 si está degradada. |

### Ejemplo de Respuesta `GET /salud`:
```json
{
  "ok": true,
  "data": {
    "servicio": "API REST WaykiPromos",
    "service": "API REST WaykiPromos",
    "version": "1.0.0",
    "estado": "operativo",
    "status": "healthy",
    "php": "8.5.12",
    "hora": "2026-11-19 14:23:20 -03",
    "time": "2026-11-19 14:23:20 -03",
    "base_de_datos": {
      "esquema": "huakipromo",
      "estado": "conectada",
      "error": null
    },
    "database": {
      "schema": "huakipromo",
      "status": "connected",
      "error": null
    }
  }
}
```

---

## 2. Módulo Autenticación y Sesión (CU-04)

| Caso de Uso | Endpoint | Método | Rol Mínimo | Procedimiento Almacenado / Servicio | Descripción Técnica |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **CU-04** (Login Google) | `/api/v1/auth/google`<br>*(alias: `/auth/google`)* | `POST` | **Público** | `CALL sp_turistas_obtener_por_google_sub(?)`<br>`CALL sp_turistas_crear(...)`<br>`CALL sp_turistas_actualizar_ultimo_acceso(?)` | Recibe `{ "id_token": "..." }`. Valida contra Google OAuth2 nativamente vía HTTPS, registra al turista si es nuevo y emite Bearer JWT (HS256). |
| **CU-04** (Login Credenciales) | `/api/v1/auth/login`<br>*(alias: `/auth/login`)* | `POST` | **Público** | `CALL sp_usuarios_obtener_por_email(?)` | Autenticación corporativa para operadores de comercio y administradores del Ente (Email + Password hash). Emite Bearer JWT. |
| **CU-04** (Perfil de Sesión) | `/api/v1/auth/perfil`<br>*(alias: `/api/v1/auth/me`, `/auth/perfil`, `/auth/me`)* | `GET` | `Bearer JWT` | Inyección en middleware | Retorna los claims y datos del usuario/turista autenticado en la sesión actual. |
| **CU-04** (Cierre de Sesión) | `/api/v1/auth/logout`<br>*(alias: `/auth/logout`)* | `POST` | **Público** | N/A | Invalida la sesión del lado del cliente. |

---

## 3. Módulo Turista / Experiencia, Catálogo y Observatorio

| Caso de Uso | Endpoint | Método | Rol Mínimo | Procedimiento Almacenado | Descripción Técnica |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **CU-01** (Catálogo Ofertas) | `/api/v1/ofertas`<br>*(alias: `/api/v1/promociones`)* | `GET` | `Bearer JWT` | `CALL sp_promociones_listar_publicas(?, ?, ?, ?, ?)` | Listado de promociones activas con filtros de circuito, categoría y término de búsqueda. |
| **CU-01** (Detalle de Oferta) | `/api/v1/ofertas/:id`<br>*(alias: `/api/v1/promociones/:id`)* | `GET` | `Bearer JWT` | `CALL sp_promociones_obtener(?)` | Ficha técnica completa de la oferta con stock remanente, días válidos y condiciones. |
| **CU-01** (Filtros Maestros) | `/api/v1/categorias`<br>`/api/v1/circuitos`<br>`/api/v1/modalidades`<br>`/api/v1/localidades` | `GET` | `Bearer JWT` | `CALL sp_categorias_comercio_obtener(0)`<br>`CALL sp_circuitosturisticos_obtener(0)`<br>`CALL sp_modalidades_descuento_obtener(0)`<br>`CALL sp_localidades_obtener(0)` | Catálogos para selectores y chips de búsqueda. Posee fallback en memoria con datos oficiales si la BD no está sembrada. |
| **CU-02** (Guardar Cupón) | `/api/v1/cupones`<br>*(alias: `/api/v1/promociones/:id/canjear`, `/api/v1/turista/cupones`)* | `POST` | `turista` | `CALL sp_promociones_obtener(?)`<br>`CALL sp_cupones_verificar_turista_promo(?, ?)`<br>`CALL sp_cupones_crear(?, ?, ?, ?, ?, @id)` | Emisión y guardado en billetera con código legible (`WYK-CAL-XXXX`), hash QR y vigencia de 14 días. Límite máx. 5 cupones activos. |
| **CU-03** (Cuponera del Turista) | `/api/v1/mis-cupones`<br>*(alias: `/api/v1/turista/cupones`)* | `GET` | `turista` | `CALL sp_cupones_listar_por_turista(?)` | Recupera los cupones guardados, canjeados y vencidos del turista en sesión. |
| **CU-03** (Detalle / Ver QR) | `/api/v1/cupones/:codigo`<br>*(alias: `/api/v1/turista/cupones/:codigo`)* | `GET` | `turista` | `CALL sp_cupones_obtener_por_codigo(?)` | Obtiene el ejemplar para renderizar el modal con código visible y token QR. |
| **CU-15** (Encuesta de Satisfacción) | `/api/v1/encuestas`<br>*(alias: `/api/v1/turista/encuesta`)* | `POST` | `turista` | `CALL sp_encuestas_turisticas_crear(..., @id)` | Captura respuestas de caracterización turística vinculadas al turista y al comercio de canje. |

---

## 4. Módulo Prestadores y Gestión Comercial

| Caso de Uso | Endpoint | Método | Rol Mínimo | Procedimiento Almacenado | Descripción Técnica |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **CU-05** (Padrón de Prestadores)| `/api/v1/admin/prestadores`<br>*(alias: `/api/v1/admin/comercios`)* | `GET` | `admin_ente` | `CALL sp_comercios_obtener(0)` | Listado general de establecimientos turísticos y su estado de autorización. |
| **CU-06** (Listar Promociones) | `/api/v1/comercio/promociones` | `GET` | `encargado` | `CALL sp_promociones_listar_gestion(?, ?, ?)` | Lista las ofertas propias del comercio (`BORRADOR`, `PENDIENTE`, `PUBLICADA`). |
| **CU-06** (Crear Oferta) | `/api/v1/comercio/promociones` | `POST` | `encargado` | `CALL sp_promociones_crear(..., @id_promo)` | Alta de oferta con cupos, modalidad y circuito. Queda en `PENDIENTE` para revisión del Ente. |
| **CU-06** (Modificar Oferta) | `/api/v1/comercio/promociones/:id` | `PUT` | `encargado` | `CALL sp_promociones_actualizar(...)` | Modifica título, cupos o condiciones particulares de una oferta del comercio. |
| **CU-07** (Bandeja Fiscalización) | `/api/v1/admin/promociones/pendientes`<br>*(alias: `/api/v1/admin/aprobaciones`)* | `GET` | `admin_ente` | `CALL sp_promociones_listar_gestion(0, 0, 'PENDIENTE')` | Bandeja institucional de ofertas que requieren aprobación provincial. |
| **CU-07** (Aprobar Oferta) | `/api/v1/admin/promociones/:id/aprobar` | `POST` | `admin_ente` | `CALL sp_promociones_actualizar_estado(?, 'PUBLICADA')` | Publica formalmente el beneficio en la cartelera de WaykiPromos. |
| **CU-07** (Rechazar Oferta) | `/api/v1/admin/promociones/:id/rechazar` | `POST` | `admin_ente` | `CALL sp_promociones_actualizar_estado(?, 'RECHAZADA')` | Rechaza la oferta informando motivos de corrección al prestador. |
| **CU-08** (Autopublicar Prestador) | `/api/v1/admin/comercios/:id/autopublicar` | `PATCH` | `admin_ente` | `UPDATE comercios SET autopublicar = ? WHERE comercio_id = ?` | Habilita o desactiva la publicación directa de ofertas sin aprobación previa. |

---

## 5. Módulo Punto de Venta / Canje en Mostrador

| Caso de Uso | Endpoint | Método | Rol Mínimo | Procedimiento Almacenado | Descripción Técnica |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **CU-09** (Validar Cupón) | `/api/v1/canjes/validar`<br>*(alias: `/api/v1/canjes/verificar`)* | `POST` | `empleado` | `CALL sp_cupones_obtener_por_codigo(?)`<br>`CALL sp_promociones_obtener(?)` | **Fase 1 (Atómica y solo lectura):** Verifica existencia, vigencia, pertenencia al comercio del operador y estado no canjeado. |
| **CU-10** (Confirmar Canje) | `/api/v1/canjes/confirmar` | `POST` | `empleado` | `CALL sp_canjes_crear(?, ?, ?, ?, @id_canje)`<br>`CALL sp_cupones_actualizar_estado(?, 'CANJEADO')` | **Fase 2 (Transaccional de cierre):** Registra el canje en auditoría. La restricción única `uq_canjes_cupon` bloquea el doble uso a nivel motor. |
| **CU-11** (Historial de Canjes) | `/api/v1/comercio/historial-canjes`<br>*(alias: `/api/v1/comercio/movimientos`)* | `GET` | `empleado` | `CALL sp_canjes_listar_por_comercio(?)` | Cronología de canjes efectuados en el local con operador, código y fecha. |
| **CU-11** (Métricas de Comercio) | `/api/v1/comercio/metricas`<br>*(alias: `/api/v1/comercio/resumen`)* | `GET` | `encargado` | `CALL sp_comercio_metricas_resumen(?)` | Cupones emitidos vs. canjeados y efectividad de promociones del prestador. |

---

## 6. Módulo Seguridad, Analítica y Auditoría

| Caso de Uso | Endpoint | Método | Rol Mínimo | Procedimiento Almacenado / SQL | Descripción Técnica |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **CU-12** (Listar Usuarios RBAC) | `/api/v1/admin/usuarios` | `GET` | `admin_ente` / `superadmin` | `CALL sp_usuarios_obtener(0)` | Nómina completa de operadores, encargados y auditores con roles y comercios asociados. |
| **CU-12** (Crear Usuario Personal) | `/api/v1/admin/usuarios` | `POST` | `superadmin` | `CALL sp_usuarios_crear(...)` | Alta de nuevo personal de comercio o Ente con contraseña encriptada (BCrypt) y rol asignado. |
| **CU-12** (Reasignar Rol RBAC) | `/api/v1/admin/usuarios/:id/rol` | `PATCH` | `superadmin` | `CALL sp_usuario_roles_asignar(?, ?)` | Modificación inmediata del rol contractual (`superadmin`, `admin_ente`, `encargado`, `empleado`). |
| **CU-12** (Bloquear / Activar Usuario) | `/api/v1/admin/usuarios/:id/estado` | `PATCH` | `superadmin` | `CALL sp_usuarios_actualizar_estado(?, ?)` | Habilita o revoca el acceso del usuario cambiando su estado entre `ACTIVO` y `BLOQUEADO`. |
| **CU-13** (Métricas Globales) | `/api/v1/admin/metricas-globales`<br>*(alias: `/api/v1/admin/resumen`)* | `GET` | `admin_ente` | `CALL sp_admin_metricas_provinciales()` | Estadísticas provinciales: total de prestadores, turistas, cupones emitidos vs canjeados y conversión. |
| **CU-14** (Auditoría Global) | `/api/v1/admin/auditoria`<br>*(alias: `/api/v1/admin/movimientos`)* | `GET` | `admin_ente` | `CALL sp_canjes_obtener(0)` | Bitácora inmutable de eventos sensibles para fiscalización pública. |
| **Políticas** (Consultar Configuración) | `/api/v1/admin/configuracion` | `GET` | `admin_ente` / `superadmin` | Memoria / Tabla `configuracion` | Consulta de vigencia de cupones, límites por turista y parámetros del Observatorio (CU-15). |
| **Políticas** (Guardar Configuración) | `/api/v1/admin/configuracion` | `PUT` | `superadmin` | Memoria / Tabla `configuracion` | Actualización de reglas globales del sistema, caducidades y sesiones. |
