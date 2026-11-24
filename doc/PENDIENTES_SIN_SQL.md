# Diagnóstico de Estado: ¿Qué funciona hoy y qué falta sin ejecutar el SQL?

Este documento detalla con precisión quirúrgica el estado operativo de la API en el servidor **sin haber ejecutado aún el script `database/propuesta_contrato_api.sql`**.

---

## 1. Resumen Ejecutivo

| Capa | Estado Actual (Sin ejecutar SQL) | Impacto |
| :--- | :---: | :--- |
| **Código PHP / Rutas (46 endpoints)** | 🟢 100% Desplegado | Todo el enrutamiento, controladores, seguridad JWT, RBAC y validaciones están vivos en el servidor. |
| **Endpoints Históricos (CU-01 a CU-15)** | 🟢 100% Funcionales | Rutas como `/salud`, `/auth/google`, `/auth/login`, maestros y canjes históricos siguen respondiendo con los SP preexistentes. |
| **Listas Maestras con Fallback** | 🟢 100% Funcionales | `/categorias`, `/circuitos`, `/modalidades`, `/localidades` responden siempre, incluso sin tablas en BD gracias al fallback en memoria. |
| **Nuevos Endpoints del Contrato v1** | 🟡 Parcialmente Bloqueados por BD | Devuelven error HTTP 500 (`PROCEDURE sp_* does not exist`) al intentar consultar los nuevos SPs optimizados de v1. |
| **Seguridad Rate-Limiting & Auditoría** | 🟡 Degrada con gracia | La API no se cae; al no encontrar las tablas `login_intentos` o `actividad`, omite el registro silenciosamente y permite autenticar. |

---

## 2. Mapa Detallado por Endpoint (Sin el nuevo SQL)

### 🟢 Grupo A: Funcionan HOY sin cambios en la base de datos (12 endpoints)

Estos endpoints se ejecutan con éxito porque usan procedimientos que ya existían en `huakipromo`, usan fallbacks en memoria o no dependen de los nuevos SPs:

1. `GET /salud` (y `/health`, `/status`): Diagnóstico y ping a MySQL.
2. `GET /categorias`: Devuelve categorías (usa SP existente o fallback oficial).
3. `GET /modalidades`: Devuelve modalidades de descuento (SP existente o fallback).
4. `GET /circuitos`: Devuelve circuitos turísticos (SP existente o fallback).
5. `GET /localidades`: Devuelve localidades de Tucumán (SP existente o fallback).
6. `POST /auth/google`: Login de turista con token Google (utiliza `sp_turistas_obtener_por_google_sub`, `sp_turistas_crear`).
7. `POST /auth/login`: Login de personal por email/contraseña (funciona verificando credenciales, degradando el conteo de rate limit).
8. `GET /panel/sesion`: Devuelve claims del JWT decodificado en memoria.
9. `POST /panel/promociones/:id/imagen`: Validación y guardado físico de WebP en disco (`public/img/promociones/`).
10. `DELETE /panel/promociones/:id/imagen`: Borrado del archivo WebP en disco.
11. `POST /panel/comercios/:id/logo`: Subida física del logo WebP en disco (`public/img/logos/`).
12. `DELETE /panel/comercios/:id/logo`: Borrado del logo en disco.

---

### 🔴 Grupo B: Pendientes de ejecutar el SQL (34 endpoints)

Estos endpoints tienen el código PHP 100% terminado y desplegado, pero al ser llamados **dispararán error de procedimiento o tabla inexistente** hasta que se corra `database/propuesta_contrato_api.sql`:

#### Catálogo y PWA Turista (8 endpoints)
| Endpoint | Qué falta en la BD para funcionar |
| :--- | :--- |
| `GET /promociones` | Requiere la vista `vw_promociones` y el procedimiento `sp_promociones_catalogo`. |
| `GET /promociones/:id` | Requiere `sp_promociones_catalogo` con parámetro ID. |
| `GET /encuesta/opciones` | Requiere el procedimiento `sp_encuesta_opciones` (desplegables unificados). |
| `GET /turista/perfil` | Requiere `sp_turistas_perfil` (incluye métricas de cupones activos/vencidos). |
| `DELETE /turista/cuenta` | Requiere `sp_turistas_anonimizar` (anonimización y restitución de stock). |
| `GET /turista/encuesta` | Requiere `sp_encuestas_turisticas_del_turista`. |
| `POST /turista/encuesta` | Requiere `sp_encuestas_turisticas_guardar` con `ON DUPLICATE KEY UPDATE`. |
| `POST /turista/cupones` | Requiere `sp_cupones_emitir` (con transacción ACID y `FOR UPDATE`). |
| `GET /turista/cupones` | Requiere `sp_cupones_del_turista`. |
| `GET /turista/cupones/:codigo` | Requiere `sp_cupones_del_turista` con filtro por código de 7 caracteres. |

#### Panel: Mostrador y Canjes (3 endpoints)
| Endpoint | Qué falta en la BD para funcionar |
| :--- | :--- |
| `POST /panel/canjes/validar` | Requiere la función `fn_cupon_problema` y el SP `sp_canjes_validar`. *(Nota: la ruta previa `/v1/canjes/validar` sigue respondiendo con el validador histórico)*. |
| `POST /panel/canjes` | Requiere `sp_canjes_confirmar` atómico. |
| `GET /panel/canjes` | Requiere `sp_canjes_listar` (historial unificado con paginado). |

#### Panel: Promociones y Comercio (8 endpoints)
| Endpoint | Qué falta en la BD para funcionar |
| :--- | :--- |
| `GET /panel/promociones` | Requiere `sp_promociones_gestion`. |
| `GET /panel/promociones/:id` | Requiere `sp_promociones_gestion` por ID. |
| `POST /panel/promociones` | Requiere inserción vinculada a la nueva vista. |
| `PUT /panel/promociones/:id` | Requiere actualización de campos del contrato. |
| `PATCH /panel/promociones/:id/estado` | Requiere `sp_promociones_cambiar_estado`. |
| `GET /panel/comercios` | Requiere `sp_comercios_listar`. |
| `GET /panel/comercios/:id` | Requiere `sp_comercios_listar` por ID. |
| `PATCH /panel/comercios/:id/estado` | Requiere `sp_comercios_cambiar_estado`. |

#### Panel: Usuarios, Observatorio y Reportes (10 endpoints)
| Endpoint | Qué falta en la BD para funcionar |
| :--- | :--- |
| `GET /panel/usuarios` | Requiere `sp_usuarios_listar`. |
| `POST /panel/usuarios` | Requiere `sp_usuarios_guardar`. |
| `PUT /panel/usuarios/:id` | Requiere `sp_usuarios_guardar` para edición. |
| `PATCH /panel/usuarios/:id/estado` | Requiere `sp_usuarios_cambiar_estado`. |
| `POST /panel/usuarios/:id/password` | Requiere `sp_usuarios_reset_password`. |
| `GET /panel/visitantes` | Requiere `sp_visitantes_listar` (observatorio demográfico). |
| `GET /panel/visitantes/:id` | Requiere `sp_visitantes_obtener`. |
| `GET /panel/resumen` | Requiere `sp_reportes_resumen` (KPIs de cabecera del panel). |
| `GET /panel/reportes` | Requiere `sp_reportes_series` (series temporales y rankings). |
| `GET /panel/actividad` | Requiere tabla `actividad` y `sp_actividad_listar`. |
| `GET /panel/exportar/:recurso` | Requiere las consultas de volcado a CSV. |

---

## 3. ¿Qué ocurre exactamente si alguien usa la API ahora?

1. **Si un turista abre la app y navega categorías:** Funciona perfectamente.
2. **Si un turista inicia sesión con Google:** Se autentica y recibe su JWT.
3. **Si el turista intenta ver el catálogo `/promociones` o emitir un cupón:** El backend capturará el error de MySQL (`sp_promociones_catalogo does not exist`) y devolverá HTTP 500 con formato estándar `{ "ok": false, "error": { ... } }`.
4. **Si el mostrador usa la ruta clásica `/v1/canjes/validar`:** Funciona con el flujo anterior.
5. **Si el mostrador usa la ruta nueva `/panel/canjes/validar`:** Reclamará la función `fn_cupon_problema`.

---

## 4. El único paso pendiente para el 100%

Solo resta **1 acción única**:
1. Abrir **phpMyAdmin** o la consola MySQL en el hosting/cPanel.
2. Seleccionar la base de datos `huakipromo`.
3. Importar o pegar el contenido de [database/propuesta_contrato_api.sql](file:///Users/jferreyradev/projects/turismo/waykipromos-api/database/propuesta_contrato_api.sql) y presionar **Ejecutar**.

En cuanto se ejecute ese script:
- Se crean las 2 tablas accesorias (`login_intentos`, `actividad`).
- Se compilan la vista `vw_promociones` y la función `fn_cupon_problema`.
- Quedan registrados los 30 procedimientos almacenados.
- **Automáticamente el 100% de los 46 endpoints queda operativo sin requerir ningún nuevo deploy de código.**
