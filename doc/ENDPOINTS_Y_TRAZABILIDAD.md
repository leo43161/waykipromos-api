# Catálogo de Endpoints y Matriz de Trazabilidad

Mapeo exhaustivo entre los **Casos de Uso (CU-01 al CU-15)**, los **Endpoints de la API REST (BFF)** y los **Procedimientos Almacenados (`sp_*`)** de la base de datos `huakipromo`.

---

## 1. Módulo Turista / Experiencia y Observatorio

| Caso de Uso | Endpoint | Método | Rol Mínimo | Procedimiento Almacenado | Descripción Técnica |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **CU-01** (Catálogo) | `/api/v1/ofertas`<br>*(alias: `/api/v1/promociones`)* | `GET` | Público | `CALL sp_promociones_listar_publicas(?, ?, ?, ?, ?)` | Listado público de promociones activas con filtros de circuito, categoría y búsqueda. |
| **CU-01** (Detalle) | `/api/v1/ofertas/:id`<br>*(alias: `/api/v1/promociones/:id`)* | `GET` | Público | `CALL sp_promociones_obtener(?)` | Ficha técnica completa de una oferta con stock remanente y condiciones. |
| **CU-01** (Filtros) | `/api/v1/categorias`<br>`/api/v1/circuitos`<br>`/api/v1/modalidades`<br>`/api/v1/localidades` | `GET` | Público | `CALL sp_categorias_comercio_obtener(0)`<br>`CALL sp_circuitosturisticos_obtener(0)`<br>`CALL sp_modalidades_descuento_obtener(0)`<br>`CALL sp_localidades_obtener(0)` | Catálogos maestros para los selectores y chips de filtro. |
| **CU-02** (Guardar Cupón) | `/api/v1/cupones`<br>*(alias: `/api/v1/turista/cupones`)* | `POST` | `turista` | `CALL sp_promociones_obtener(?)`<br>`CALL sp_cupones_verificar_turista_promo(?, ?)`<br>`CALL sp_cupones_crear(?, ?, ?, ?, ?, @id)` | Emisión de cupón con código legible (`WP-2026-XXXX`) y hash SHA-256 para QR. Descuenta stock atómicamente. |
| **CU-03** (Cuponera) | `/api/v1/mis-cupones`<br>*(alias: `/api/v1/turista/cupones`)* | `GET` | `turista` | `CALL sp_cupones_listar_por_turista(?)` | Recupera los cupones del turista para alimentar las solapas (Guardados, Canjeados, Vencidos). |
| **CU-03** (Ver QR) | `/api/v1/cupones/:codigo`<br>*(alias: `/api/v1/turista/cupones/:codigo`)* | `GET` | `turista` / Público | `CALL sp_cupones_obtener_por_codigo(?)` | Obtiene el ejemplar para renderizar el modal con código visible y token QR. |
| **CU-04** (Login Google) | `/api/v1/auth/google` | `POST` | Público | `CALL sp_turistas_obtener_por_google_sub(?)`<br>`CALL sp_turistas_crear(...)` | Valida credencial Google OAuth, da de alta al turista si es nuevo y emite Bearer JWT. |
| **CU-15** (Encuesta) | `/api/v1/encuestas`<br>*(alias: `/api/v1/turista/encuesta`)* | `POST` | `turista` / Público | `CALL sp_encuestas_turisticas_crear(..., @id)` | Captura respuestas de caracterización turística vinculadas al turista y al comercio de canje. |

---

## 2. Módulo Prestadores y Gestión Comercial

| Caso de Uso | Endpoint | Método | Rol Mínimo | Procedimiento Almacenado | Descripción Técnica |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **CU-05** (Padrón) | `/api/v1/admin/prestadores`<br>*(alias: `/api/v1/admin/comercios`)* | `GET` | `admin_ente` | `CALL sp_comercios_obtener(0)` | Listado general de establecimientos turísticos y su estado de autorización. |
| **CU-06** (Promociones) | `/api/v1/comercio/promociones` | `GET` | `encargado` | `CALL sp_promociones_listar_gestion(?, ?, ?)` | Lista las ofertas propias del comercio en cualquier estado (`BORRADOR`, `PENDIENTE`, `PUBLICADA`). |
| **CU-06** (Alta Oferta) | `/api/v1/comercio/promociones` | `POST` | `encargado` | `CALL sp_promociones_crear(..., @id_promo)` | Alta de oferta con cupos, modalidad y circuito. Queda en `PENDIENTE` para revisión. |
| **CU-06** (Editar Oferta)| `/api/v1/comercio/promociones/:id` | `PUT` | `encargado` | `CALL sp_promociones_actualizar(...)` | Modifica título, cupos o condiciones particulares de una oferta. |
| **CU-07** (Fiscalización)| `/api/v1/admin/promociones/pendientes`<br>*(alias: `/api/v1/admin/aprobaciones`)* | `GET` | `admin_ente` | `CALL sp_promociones_listar_gestion(0, 0, 'PENDIENTE')` | Bandeja institucional de ofertas que requieren aprobación provincial. |
| **CU-07** (Aprobar) | `/api/v1/admin/promociones/:id/aprobar` | `POST` | `admin_ente` | `CALL sp_promociones_actualizar_estado(?, 'PUBLICADA')` | Publica formalmente el beneficio en la cartelera de la app. |
| **CU-07** (Rechazar) | `/api/v1/admin/promociones/:id/rechazar` | `POST` | `admin_ente` | `CALL sp_promociones_actualizar_estado(?, 'RECHAZADA')` | Rechaza la oferta informando motivos de corrección al comercio. |

---

## 3. Módulo Punto de Venta / Canje en Mostrador

| Caso de Uso | Endpoint | Método | Rol Mínimo | Procedimiento Almacenado | Descripción Técnica |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **CU-09** (Validar Cupón) | `/api/v1/canjes/validar`<br>*(alias: `/api/v1/canjes/verificar`)* | `POST` | `empleado` | `CALL sp_cupones_obtener_por_codigo(?)`<br>`CALL sp_promociones_obtener(?)` | **Fase 1 (Atómica y solo lectura):** Verifica existencia, vigencia, pertenencia al comercio y estado no canjeado. |
| **CU-10** (Confirmar Canje)| `/api/v1/canjes/confirmar` | `POST` | `empleado` | `CALL sp_canjes_crear(?, ?, ?, ?, @id_canje)`<br>`CALL sp_cupones_actualizar_estado(?, 'CANJEADO')` | **Fase 2 (Transaccional de cierre):** Registra el canje en auditoría. La restricción `uq_canjes_cupon` bloquea el doble uso a nivel motor. |
| **CU-11** (Historial) | `/api/v1/comercio/historial-canjes`<br>*(alias: `/api/v1/comercio/movimientos`)* | `GET` | `empleado` | `CALL sp_canjes_listar_por_comercio(?)` | Cronología de canjes efectuados en el local con operador, código y fecha. |
| **CU-11** (Métricas) | `/api/v1/comercio/metricas`<br>*(alias: `/api/v1/comercio/resumen`)* | `GET` | `encargado` | `CALL sp_comercio_metricas_resumen(?)` | Cupones emitidos vs. canjeados y efectividad de promociones del prestador. |

---

## 4. Módulo Seguridad, Analítica y Auditoría

| Caso de Uso | Endpoint | Método | Rol Mínimo | Procedimiento Almacenado | Descripción Técnica |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **CU-12** (Usuarios RBAC)| `/api/v1/admin/usuarios` | `GET` | `superadmin` | `CALL sp_usuarios_obtener(0)` | Nómina de usuarios y comercios vinculados. |
| **CU-12** (Editar Rol) | `/api/v1/admin/usuarios/:id/rol` | `PATCH` | `superadmin` | `CALL sp_usuario_roles_eliminar(?)`<br>`CALL sp_usuario_roles_crear(?, ?, ?, @id)` | Asignación de permisos operativos (`turista`, `empleado`, `encargado`, `admin_ente`, `superadmin`). |
| **CU-13** (Métricas Ente)| `/api/v1/admin/metricas-globales`<br>*(alias: `/api/v1/admin/resumen`)* | `GET` | `admin_ente` | `CALL sp_admin_metricas_provinciales()` | Estadísticas provinciales: total de turistas, cupones emitidos vs canjeados y conversión. |
| **CU-14** (Auditoría) | `/api/v1/admin/auditoria`<br>*(alias: `/api/v1/admin/movimientos`)* | `GET` | `admin_ente` | `CALL sp_canjes_obtener(0)` | Bitácora inmutable de eventos sensibles para fiscalización pública. |
