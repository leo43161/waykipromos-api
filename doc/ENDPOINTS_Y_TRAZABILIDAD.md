# Catálogo de Endpoints y Matriz de Trazabilidad · API WaykiPromos v1

Documentación técnica y especificación exhaustiva de los **46 endpoints canónicos** de la API WaykiPromos (versión 1), sus contratos de entrada/salida (DTOs), roles de acceso (RBAC), controladores asociados en `src/Controllers/` y procedimientos almacenados en MySQL (`huakipromo`).

---

## 0. Convenciones Globales y Entorno

- **URL Base de Producción:** `https://tucumanturismo.gob.ar/waykipromo/api/v1`
- **URL Base de Desarrollo Local:** `http://localhost:8000/v1` (o `/api/v1`)
- **Zona Horaria del Servidor y Base:** `America/Argentina/Tucuman` (UTC-3). Todas las fechas se emiten en formato ISO-8601 con offset `-03:00` (ej. `2026-11-24T12:00:00-03:00`).
- **Encabezados HTTP:**
  - `Authorization: Bearer <token>`: Obligatorio en todos los endpoints privados.
  - `Content-Type: application/json`: En todas las peticiones con cuerpo JSON (POST, PUT, PATCH).
  - `Content-Type: multipart/form-data`: Exclusivo para subida de fotos y logos.
- **Formato Estándar de Respuesta (Envelope):**
  - **Éxito (2xx):**
    ```json
    {
      "ok": true,
      "data": { ... }
    }
    ```
  - **Error (4xx / 5xx):**
    ```json
    {
      "ok": false,
      "error": {
        "codigo": "CODIGO_ERROR",
        "mensaje": "Descripción legible para el usuario o desarrollador.",
        "detalles": null
      }
    }
    ```
- **Códigos de Error Comunes:**
  - `SESION_INVALIDA` (401): Token JWT ausente, expirado o con firma no válida.
  - `ROL_NO_AUTORIZADO` (403): El rol no posee permisos para el endpoint o la acción.
  - `SIN_COMERCIO` (403): El usuario del personal no tiene asignado un `comercio_id`.
  - `RECURSO_NO_ENCONTRADO` (404): ID o recurso inexistente o no perteneciente al comercio.
  - `VALIDACION` (400): Campos requeridos ausentes o tipos de datos inválidos.
  - `DEMASIADOS_INTENTOS` (429): Límite de intentos de login excedido (5 intentos cada 15 min).
  - `IMAGEN_INVALIDA` (400/413/415): Archivo superior a 2MB o MIME distinto de WebP, JPEG, PNG.

---

## 1. Matriz de Roles y Autorización (RBAC)

| Rol | Ámbito | Descripción |
| :--- | :--- | :--- |
| **Público** | Sin autenticación | Navegación de promociones, maestros y pantallas de login. |
| **`turista`** | PWA Turista | Registro Google, encuesta de perfil, emisión y visualización de cupones. |
| **`empleado`** | Mostrador | Validación y confirmación de canjes en el local, historial de canjes. |
| **`encargado`** | Comercio | Todo lo del empleado + gestión de promociones, datos del comercio y equipo de usuarios. |
| **`admin_ente`** | Ente de Turismo | Control provincial: padrón de comercios, visitantes, reportes globales, auditoría y promociones. |

---

## 2. Catálogo Público y Listas Maestras (8 Endpoints)

Rutas públicas de consulta abierta para la App del Turista y el Panel.

| Método | Endpoint | Controlador | Procedimiento / Origen | Descripción |
| :---: | :--- | :--- | :--- | :--- |
| `GET` | `/promociones` | `CatalogoController@listar` | `sp_promociones_listar_publicas` | Catálogo de promociones activas con filtros (`categoria`, `circuito`, `modalidad`, `buscar`, `pagina`). |
| `GET` | `/promociones/:id` | `CatalogoController@ficha` | `sp_promociones_obtener` | Ficha detallada de la oferta con comercio, días, vigencia y cupos disponibles. |
| `GET` | `/categorias` | `MaestrosController@categorias` | `sp_categorias_comercio_obtener` | Lista de categorías `{ id, nombre }`. |
| `GET` | `/modalidades` | `MaestrosController@modalidades` | `sp_modalidades_descuento_obtener` | Lista de modalidades de descuento `{ id, nombre }`. |
| `GET` | `/circuitos` | `MaestrosController@circuitos` | `sp_circuitosturisticos_obtener` | Lista de circuitos turísticos `{ id, nombre }`. |
| `GET` | `/localidades` | `MaestrosController@localidades` | `sp_localidades_obtener` | Lista de localidades `{ id, nombre }`. |
| `GET` | `/encuesta/opciones` | `TuristaEncuestaController@opciones` | `sp_encuesta_opciones` | Opciones maestras para los desplegables de la encuesta turística. |
| `GET` | `/salud` *(alias: `/health`)* | `HealthController@check` | Ping MySQL | Diagnóstico de salud y conexión activa a base de datos. |

---

## 3. Autenticación (2 Endpoints)

| Método | Endpoint | Rol | Controlador | Descripción |
| :---: | :--- | :---: | :--- | :--- |
| `POST` | `/auth/google` | **Público** | `AuthController@googleLogin` | Autenticación del turista vía Google ID Token (`id_token`). Emite JWT `turista`. |
| `POST` | `/auth/login` | **Público** | `LoginController@ingresar` | Autenticación de personal con email y contraseña. Rate-limiting de 5 intentos por IP. Emite JWT 12h. |

---

## 4. App del Turista (6 Endpoints)

Rutas protegidas con rol `turista`.

| Método | Endpoint | Controlador | Procedimiento / Origen | Descripción |
| :---: | :--- | :--- | :--- | :--- |
| `GET` | `/turista/perfil` | `TuristaController@perfil` | `sp_turistas_obtener` | Perfil del turista autenticado, datos de Google y estado de la encuesta. |
| `DELETE` | `/turista/cuenta` | `TuristaController@borrarCuenta` | `sp_turistas_borrar` | Derecho al olvido (RGPD): anonimiza/elimina la cuenta del turista y sus cupones no canjeados. |
| `GET` | `/turista/encuesta` | `TuristaEncuestaController@ver` | `sp_turista_encuesta_obtener` | Obtiene las respuestas previamente guardadas en la encuesta de caracterización. |
| `POST` | `/turista/encuesta` | `TuristaEncuestaController@guardar` | `sp_turista_encuesta_guardar` | Guarda o actualiza las respuestas de la encuesta (procedencia, estadía, transporte, etc.). |
| `GET` | `/turista/cupones` | `TuristaCuponesController@listar` | `sp_cupones_listar_por_turista` | Cuponera personal: cupones activos, canjeados y vencidos del turista. |
| `POST` | `/turista/cupones` | `TuristaCuponesController@emitir` | `sp_cupones_emitir` | Emite un nuevo cupón con código canónico de 7 chars (`W` + 6 alfanuméricos). Máx 5 activos. |
| `GET` | `/turista/cupones/:codigo` | `TuristaCuponesController@ver` | `sp_cupones_obtener_por_codigo` | Ficha del cupón con QR, instrucciones y estado para presentar en mostrador. |

---

## 5. Panel: Sesión y Resumen (3 Endpoints)

Rutas para personal autenticado (`empleado`, `encargado`, `admin_ente`).

| Método | Endpoint | Rol Mínimo | Controlador | Descripción |
| :---: | :--- | :---: | :--- | :--- |
| `GET` | `/panel/sesion` | `empleado` | `LoginController@sesion` | Claims de sesión activa, datos del usuario, comercio y permisos. |
| `PUT` | `/panel/password` | `empleado` | `LoginController@cambiarPassword` | Cambio de contraseña propia (requiere `password_actual` y `password_nueva`). |
| `GET` | `/panel/resumen` | `empleado` | `ReportesController@resumen` | Dashboard inicial con KPIs resumidos según el rol (comercio o provincial). |

---

## 6. Panel: Canje en Mostrador (3 Endpoints)

Operatoria rápida de caja y mostrador.

| Método | Endpoint | Rol Mínimo | Controlador | Procedimiento | Descripción |
| :---: | :--- | :---: | :--- | :--- | :--- |
| `POST` | `/panel/canjes/validar` | `empleado` | `CanjesController@validar` | `fn_cupon_problema` | Valida el código de 7 caracteres sin canjearlo. Retorna `{ valido, cupon, problema }`. |
| `POST` | `/panel/canjes` | `empleado` | `CanjesController@confirmar` | `sp_canjes_confirmar` | **Transaccional / Atómico:** Registra el canje con `monto_descuento_estimado`. |
| `GET` | `/panel/canjes` | `empleado` | `CanjesController@listar` | `sp_canjes_listar_por_comercio` | Historial paginado de canjes del comercio con filtros de fecha y búsqueda. |

---

## 7. Panel: Promociones (7 Endpoints)

Gestión del ciclo de vida de ofertas (`encargado`, `admin_ente`).

| Método | Endpoint | Rol Mínimo | Controlador | Descripción |
| :---: | :--- | :---: | :--- | :--- |
| `GET` | `/panel/promociones` | `encargado` | `PromocionesPanelController@listar` | Listado de promociones del comercio (o todas si es Ente) con filtros de estado. |
| `GET` | `/panel/promociones/:id` | `encargado` | `PromocionesPanelController@ver` | Detalle completo de la promoción para edición o revisión. |
| `POST` | `/panel/promociones` | `encargado` | `PromocionesPanelController@crear` | Crea una promoción en estado `borrador` o `publicada` (según `autopublicar`). |
| `PUT` | `/panel/promociones/:id` | `encargado` | `PromocionesPanelController@editar` | Modifica título, descuento, vigencia, cupos y condiciones. |
| `PATCH` | `/panel/promociones/:id/estado` | `encargado` | `PromocionesPanelController@cambiarEstado` | Transición de estado (`publicada`, `pausada`, `finalizada`, `baja`). |
| `POST` | `/panel/promociones/:id/imagen` | `encargado` | `ImagenesController@subirPromocion` | Sube la foto promocional (optimización a WebP max 1200px, 2MB max). |
| `DELETE` | `/panel/promociones/:id/imagen` | `encargado` | `ImagenesController@borrarPromocion` | Elimina la imagen asociada y limpia la URL en la oferta. |

---

## 8. Panel: Comercios y Padrón (7 Endpoints)

| Método | Endpoint | Rol Mínimo | Controlador | Descripción |
| :---: | :--- | :---: | :--- | :--- |
| `GET` | `/panel/comercios` | `admin_ente` | `ComerciosController@listar` | Padrón provincial de comercios con filtros (`estado`, `circuito`, `categoria`). |
| `GET` | `/panel/comercios/:id` | `encargado` | `ComerciosController@ver` | Ficha técnica del comercio (aislado: el encargado solo ve el suyo). |
| `POST` | `/panel/comercios` | `admin_ente` | `ComerciosController@crear` | Alta de nuevo comercio con CUIT, razón social, circuito y coordenadas. |
| `PUT` | `/panel/comercios/:id` | `encargado` | `ComerciosController@editar` | Edita datos de contacto, dirección, horarios y descripción del comercio. |
| `PATCH` | `/panel/comercios/:id/estado` | `admin_ente` | `ComerciosController@cambiarEstado` | Modifica estado (`activo`, `suspendido`, `baja`) y flag `autopublicar`. |
| `POST` | `/panel/comercios/:id/logo` | `encargado` | `ImagenesController@subirLogo` | Sube el logo comercial (WebP, max 800px). |
| `DELETE` | `/panel/comercios/:id/logo` | `encargado` | `ImagenesController@borrarLogo` | Quita el logo comercial. |

---

## 9. Panel: Equipo de Usuarios (5 Endpoints)

Administración de operadores y encargados (`encargado`, `admin_ente`).

| Método | Endpoint | Rol Mínimo | Controlador | Descripción |
| :---: | :--- | :---: | :--- | :--- |
| `GET` | `/panel/usuarios` | `encargado` | `UsuariosPanelController@listar` | Listado del personal del comercio (o global para el Ente). |
| `POST` | `/panel/usuarios` | `encargado` | `UsuariosPanelController@crear` | Alta de usuario (`empleado`, `encargado`, `admin_ente`) con hash seguro. |
| `PUT` | `/panel/usuarios/:id` | `encargado` | `UsuariosPanelController@editar` | Actualiza nombre, teléfono y asignación de rol. |
| `PATCH` | `/panel/usuarios/:id/estado` | `encargado` | `UsuariosPanelController@cambiarEstado` | Cambia estado a `activo`, `bloqueado` o `baja`. |
| `POST` | `/panel/usuarios/:id/password` | `encargado` | `UsuariosPanelController@nuevaPassword` | Restablece la contraseña de un usuario del equipo. |

---

## 10. Panel: Observatorio y Ente de Turismo (5 Endpoints)

Herramientas exclusivas del Ente Autárquico Tucumán Turismo.

| Método | Endpoint | Rol Mínimo | Controlador | Descripción |
| :---: | :--- | :---: | :--- | :--- |
| `GET` | `/panel/visitantes` | `admin_ente` | `VisitantesController@listar` | Perfiles de turistas registrados con datos demográficos de la encuesta. |
| `GET` | `/panel/visitantes/:id` | `admin_ente` | `VisitantesController@ver` | Ficha completa del visitante, histórico de cupones emitidos y canjes. |
| `GET` | `/panel/reportes` | `admin_ente` | `ReportesController@reportes` | Analítica avanzada: series temporales, ranking por circuito, categoría y tasas de canje. |
| `GET` | `/panel/actividad` | `encargado` | `ReportesController@actividad` | Registro inmutable de eventos de auditoría (`sp_actividad_listar`). |
| `GET` | `/panel/exportar/:recurso` | `empleado` | `ReportesController@exportar` | Descarga de datos en formato CSV plano (`canjes`, `promociones`, `comercios`, `visitantes`). |

---

## 11. Formato de Códigos de Cupón

Los cupones emitidos por la API responden a la especificación canónica:
- **Formato:** Cadena de exactamente **7 caracteres alfanuméricos en mayúsculas**.
- **Prefijo:** Comienza siempre con la letra `W` (Wayki).
- **Cuerpo:** 6 caracteres aleatorios de un alfabeto sin caracteres visualmente ambiguos:  
  `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (excluye `0`, `O`, `1`, `I`).
- **Ejemplo:** `W7K9M2P`
- **Normalización:** La API acepta entradas de usuario con espacios o guiones (`W 7K9 M2P`, `W-7K9-M2P`) y las normaliza a `W7K9M2P` antes de consultar la base de datos.

---

## 12. Scripts de Base de Datos y Pruebas

- **Script SQL con Procedimientos y Tablas:** [database/propuesta_contrato_api.sql](file:///Users/jferreyradev/projects/turismo/waykipromos-api/database/propuesta_contrato_api.sql)
- **Suite de Pruebas Unitarias e Integración:** [tests/Feature/ContratoApiTest.php](file:///Users/jferreyradev/projects/turismo/waykipromos-api/tests/Feature/ContratoApiTest.php)
- **Ejecución de Pruebas:**
  ```bash
  ./vendor/bin/phpunit
  ```
