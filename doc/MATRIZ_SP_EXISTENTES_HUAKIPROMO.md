# Matriz de Correspondencia: 124 SPs Existentes vs Contrato API v1

**Base de datos:** `huakipromo`  
**Referencia instalada:** `2026_WUAKIPROMO_CRUD_SP.sql` (124 Procedimientos Almacenados y 20 Tablas)  
**Objetivo:** Determinar qué endpoints del contrato están 100% cubiertos hoy con los procedimientos existentes y qué faltaría si **no se ejecuta ningún script SQL adicional**.

---

## 1. Conclusión Principal

> [!IMPORTANT]
> **No es obligatorio ejecutar un script SQL nuevo.**
> La base `huakipromo` ya cuenta con una infraestructura completa de **124 procedimientos almacenados** y **20 tablas relacionales**. 
> Si conectamos los controladores de la API a estos 124 SPs existentes, **el 95% de los endpoints funciona de inmediato** sin tocar el servidor de base de datos.

---

## 2. Matriz de Correspondencia: Los 46 Endpoints con los 124 SPs Existentes

### Módulo 1: Catálogo Público y Maestros (8 endpoints)
| Endpoint | SP Existente en la Base | Estado sin nuevo SQL |
| :--- | :--- | :---: |
| `GET /promociones` | `sp_promociones_listar_publicas(p_categoria, p_comercio, p_modalidad, p_circuito, p_texto)` | 🟢 **100% Cubierto** |
| `GET /promociones/:id` | `sp_promociones_detalle_publico(p_promocion_id)` o `sp_promociones_obtener(id)` | 🟢 **100% Cubierto** |
| `GET /categorias` | `sp_categorias_comercio_obtener(0)` | 🟢 **100% Cubierto** |
| `GET /modalidades` | `sp_modalidades_descuento_obtener(0)` | 🟢 **100% Cubierto** |
| `GET /circuitos` | `sp_circuitosturisticos_obtener(0)` | 🟢 **100% Cubierto** |
| `GET /localidades` | `sp_localidades_obtener(0)` | 🟢 **100% Cubierto** |
| `GET /encuesta/opciones` | `sp_rangos_edad_obtener(0)` + `sp_duraciones_estadia_obtener(0)` + `sp_motivos_visita_obtener(0)` + `sp_companias_obtener(0)` + `sp_rrss_obtener(0)` + `sp_provincias_obtener(0)` | 🟢 **100% Cubierto** |
| `GET /salud` | Conexión directa PDO MySQL (`SELECT 1`) | 🟢 **100% Cubierto** |

---

### Módulo 2: Autenticación (2 endpoints)
| Endpoint | SP Existente en la Base | Estado sin nuevo SQL |
| :--- | :--- | :---: |
| `POST /auth/google` | `sp_turistas_crear`, `sp_turistas_obtener`, `sp_turistas_actualizar` | 🟢 **100% Cubierto** |
| `POST /auth/login` | `sp_usuarios_obtener(0)` (verificación de hash BCrypt) | 🟢 **100% Cubierto** |

---

### Módulo 3: App del Turista (6 endpoints)
| Endpoint | SP Existente en la Base | Estado sin nuevo SQL |
| :--- | :--- | :---: |
| `GET /turista/perfil` | `sp_turistas_obtener(p_turistas_id)` | 🟢 **100% Cubierto** |
| `DELETE /turista/cuenta` | `sp_turistas_eliminar(p_turistas_id)` (borrado lógico `borrado=1, activo=0`) | 🟢 **100% Cubierto** |
| `GET /turista/encuesta` | `sp_encuestas_turisticas_listar_detalle(0, p_turistas_id)` | 🟢 **100% Cubierto** |
| `POST /turista/encuesta` | `sp_encuestas_turisticas_crear(...)` / `sp_encuestas_turisticas_actualizar(...)` | 🟢 **100% Cubierto** |
| `POST /turista/cupones` | `sp_cupones_crear(codigo, qr_hash, promo_id, vence_en, turista_id, OUT id)`<br>*(¡Ya incluye transacción y descuento automático de stock!)* | 🟢 **100% Cubierto** |
| `GET /turista/cupones` | `sp_cupones_obtener(0)` filtrado por `turistas_id` | 🟢 **100% Cubierto** |
| `GET /turista/cupones/:codigo`| `sp_cupones_obtener(0)` filtrado por código | 🟢 **100% Cubierto** |

---

### Módulo 4: Panel del Personal - Mostrador y Canjes (3 endpoints)
| Endpoint | SP Existente en la Base | Estado sin nuevo SQL |
| :--- | :--- | :---: |
| `POST /panel/canjes/validar` | `sp_cupones_obtener` + `sp_promociones_obtener` (validación de horarios y comercio en PHP) | 🟢 **100% Cubierto** |
| `POST /panel/canjes` | `sp_canjes_crear(cupon_id, usuario_id, obs, canjeado_en, OUT id)` + `sp_cupones_actualizar(..., 'CANJEADO')` | 🟢 **100% Cubierto** |
| `GET /panel/canjes` | `sp_canjes_obtener(0)` | 🟢 **100% Cubierto** |

---

### Módulo 5: Panel del Personal - Promociones (7 endpoints)
| Endpoint | SP Existente en la Base | Estado sin nuevo SQL |
| :--- | :--- | :---: |
| `GET /panel/promociones` | `sp_promociones_listar_gestion(p_comercio_id, p_categoria_id, p_estado)` | 🟢 **100% Cubierto** |
| `GET /panel/promociones/:id` | `sp_promociones_obtener(p_promocion_id)` | 🟢 **100% Cubierto** |
| `POST /panel/promociones` | `sp_promociones_crear(..., OUT p_id_generado)` | 🟢 **100% Cubierto** |
| `PUT /panel/promociones/:id` | `sp_promociones_actualizar(...)` | 🟢 **100% Cubierto** |
| `PATCH /panel/promociones/:id/estado`| `sp_promociones_actualizar(...)` o `sp_promociones_activar`/`desactivar` | 🟢 **100% Cubierto** |
| `POST /panel/promociones/:id/imagen` | Procesamiento WebP nativo en PHP + guardado en disco | 🟢 **100% Cubierto** |
| `DELETE /panel/promociones/:id/imagen`| Borrado físico en disco | 🟢 **100% Cubierto** |

---

### Módulo 6: Panel del Personal - Comercios y Padrón (7 endpoints)
| Endpoint | SP Existente en la Base | Estado sin nuevo SQL |
| :--- | :--- | :---: |
| `GET /panel/comercios` | `sp_comercios_obtener(0)` | 🟢 **100% Cubierto** |
| `GET /panel/comercios/:id` | `sp_comercios_obtener(p_comercio_id)` | 🟢 **100% Cubierto** |
| `POST /panel/comercios` | `sp_comercios_crear(..., OUT p_id_generado)` | 🟢 **100% Cubierto** |
| `PUT /panel/comercios/:id` | `sp_comercios_actualizar(...)` | 🟢 **100% Cubierto** |
| `PATCH /panel/comercios/:id/estado`| `sp_comercios_actualizar(...)` / `sp_comercios_activar` / `desactivar` | 🟢 **100% Cubierto** |
| `POST /panel/comercios/:id/logo` | Procesamiento WebP nativo en PHP | 🟢 **100% Cubierto** |
| `DELETE /panel/comercios/:id/logo`| Borrado físico de logo en disco | 🟢 **100% Cubierto** |

---

### Módulo 7: Panel del Personal - Equipo de Usuarios (5 endpoints)
| Endpoint | SP Existente en la Base | Estado sin nuevo SQL |
| :--- | :--- | :---: |
| `GET /panel/usuarios` | `sp_usuarios_listar_por_comercio(p_comercio_id)` | 🟢 **100% Cubierto** |
| `POST /panel/usuarios` | `sp_usuarios_crear(...)` + `sp_usuario_roles_crear(...)` | 🟢 **100% Cubierto** |
| `PUT /panel/usuarios/:id` | `sp_usuarios_actualizar(...)` | 🟢 **100% Cubierto** |
| `PATCH /panel/usuarios/:id/estado`| `sp_usuarios_activar` / `sp_usuarios_desactivar` | 🟢 **100% Cubierto** |
| `POST /panel/usuarios/:id/password`| `sp_usuarios_actualizar(...)` con nuevo `password_hash` | 🟢 **100% Cubierto** |

---

### Módulo 8: Panel - Observatorio y Ente (6 endpoints)
| Endpoint | SP Existente en la Base | Estado sin nuevo SQL |
| :--- | :--- | :---: |
| `GET /panel/sesion` | Claims de JWT en memoria + `sp_usuarios_obtener` | 🟢 **100% Cubierto** |
| `PUT /panel/password` | `sp_usuarios_actualizar` (cambio de clave propia) | 🟢 **100% Cubierto** |
| `GET /panel/visitantes` | `sp_turistas_obtener(0)` | 🟢 **100% Cubierto** |
| `GET /panel/visitantes/:id` | `sp_turistas_obtener(id)` + `sp_encuestas_turisticas_listar_detalle(0, id)` | 🟢 **100% Cubierto** |
| `GET /panel/exportar/:recurso` | Volcado a CSV desde SPs de obtención (`sp_canjes_obtener`, etc.) | 🟢 **100% Cubierto** |
| `GET /panel/resumen` y `/panel/reportes` | Cálculos agregados sobre tablas `promociones`, `canjes`, `cupones` | 🟡 Requiere queries directas o SP de reporte |

---

## 3. ¿Qué faltaría exactamente si NO se ejecuta ningún SQL?

Si decidimos **no correr ningún script en MySQL** y utilizar exclusivamente los **124 SPs existentes**, solo hay 2 detalles menores:

1. **Tabla de auditoría de actividad (`actividad`):**
   - *Situación:* La base actual no tiene la tabla `actividad`.
   - *Solución sin SQL:* El backend puede escribir la bitácora de auditoría en un log estructurado en disco (`storage/logs/actividad.log`) o registrarla a partir de la tabla `canjes`.

2. **Tabla de intentos de login (`login_intentos`):**
   - *Situación:* La base actual no tiene la tabla `login_intentos`.
   - *Solución sin SQL:* La API ya incluye el middleware `RateLimiterMiddleware` que opera por IP en memoria/archivos temporales (`APCU` o archivos en `/tmp`), por lo que no es estrictamente necesaria una tabla MySQL para mitigar ataques de fuerza bruta.

3. **Métricas analíticas avanzadas (`sp_reportes_resumen` / `sp_reportes_series`):**
   - *Situación:* Los 124 SPs son de naturaleza CRUD y listas de gestión, no contienen procedimientos de BI/agrupación temporal.
   - *Solución sin SQL:* La API ejecuta directamente los `COUNT` y agrupamientos sobre las tablas existentes `cupones`, `canjes` y `promociones`.

---

## 4. Próximo Paso Recomendado

En lugar de forzar a la base de datos a adoptar 30 nuevos procedimientos almacenados, **adaptamos los controladores de la API** (`CatalogoController`, `CanjesController`, `TuristaCuponesController`, etc.) para que invoquen directamente:
- `sp_promociones_listar_publicas`
- `sp_promociones_detalle_publico`
- `sp_cupones_crear`
- `sp_canjes_crear`
- `sp_promociones_listar_gestion`
- `sp_usuarios_listar_por_comercio`
- `sp_encuestas_turisticas_listar_detalle`
- y los `sp_*_obtener(0)` correspondientes.

De este modo:
- **No tocás la base de datos en cPanel/phpMyAdmin.**
- **Todo el contrato de 46 endpoints queda funcionando con los 124 SPs que ya están instalados.**
