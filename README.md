# API WaykiPromos (PHP 8.4+ / 8.5 - Clean Architecture)

> Backend REST for Frontend (BFF) desarrollado para la plataforma de beneficios y promociones turísticas del **Ente Autárquico Tucumán Turismo**.  
> Arquitectura basada en los estándares de alto rendimiento de `api-turismo`, diseñada para conectarse a la base de datos MySQL dedicada `huakipromo` mediante llamadas a procedimientos almacenados (`CALL sp_*`).

---

## Características Principales

* **Clean Architecture & PSR-Compliant (PHP 8.4+ / 8.5 Puro)**:
  * Sin dependencias pesadas de frameworks en producción (0 overhead, arranque en 10–15 ms).
  * Modelos inmutables de `Request` y `Response` (estilo PSR-7).
  * Pipeline de middlewares cebolla (PSR-15) con `CorsMiddleware`, `ErrorHandlerMiddleware`, `FileLoggerMiddleware`, `JwtAuthMiddleware` y `RoleMiddleware`.
  * Contenedor de Inyección de Dependencias (`Container`) con autowiring por Reflection.
* **Autenticación Dual & RBAC**:
  * **Turistas Móviles**: Validación criptográfica de tokens federados de Google OAuth (`POST /api/v1/auth/google`) y emisión de JWT para app/web.
  * **Comercios y Ente**: Acceso administrativo con correo/contraseña segura (`POST /api/v1/auth/login`).
  * Control de roles por endpoint: `turista`, `empleado`, `encargado`, `admin_ente`, `superadmin`.
* **Persistencia Transaccional con Procedimientos Almacenados**:
  * Capa `StoredProcedureCaller` preparada para ejecutar rutinas con soporte de parámetros de entrada (`IN`) y valores autogenerados (`OUT p_id_generado`).
  * Mapeo automático de errores de integridad referencial y claves duplicadas a excepciones HTTP canónicas (`400`, `409`).
* **Contrato de Respuesta BFF Canónico**:
  * **Éxito (2xx)**: `{ "ok": true, "data": ..., "meta": ... }`
  * **Error (4xx / 5xx)**: `{ "ok": false, "error": { "codigo": "...", "mensaje": "...", "detalles": ... } }`

---

## Estructura del Proyecto

```text
api-waykipromo/
├── bin/
│   └── test-procedures.php     # Smoke test CLI de procedimientos almacenados en huakipromo
├── public/
│   ├── .htaccess               # Enrutamiento Apache y cabeceras de seguridad
│   └── index.php               # Punto de entrada HTTP, enrutador y pipeline
├── src/
│   ├── Config/
│   │   └── Env.php             # Carga y resolución de variables de entorno .env
│   ├── Controllers/
│   │   ├── AdminController.php             # Fiscalización y métricas provinciales del Ente
│   │   ├── AuthController.php              # Login Google OAuth, credenciales comerciales y perfil
│   │   ├── CanjesController.php            # Pre-validación y canje en mostrador con QR/código
│   │   ├── ComercioPromocionesController.php # Gestión comercial de promociones y métricas
│   │   ├── CuponesController.php           # Emisión de cupones para turistas e historial
│   │   ├── EncuestasController.php         # Observatorio turístico y hábitos de viaje
│   │   ├── HealthController.php            # Comprobación de salud y conexión a huakipromo
│   │   ├── MaestrosController.php          # Categorías, circuitos, modalidades y localidades
│   │   └── OfertasController.php           # Catálogo público filtrado y ficha de oferta
│   ├── Core/
│   │   ├── Container/          # Contenedor DI ligero con autowiring
│   │   ├── Http/               # Modelos inmutables Request y Response
│   │   ├── Middleware/         # Interfaces Pipeline, RequestHandler y Middleware
│   │   └── Router/             # Enrutador rápido con parámetros dinámicos (:id, :codigo)
│   ├── Database/
│   │   ├── Database.php        # Conexión Singleton PDO optimizada con utf8mb4
│   │   └── StoredProcedureCaller.php # Ejecución parametrizada de SPs huakipromo
│   ├── Domain/
│   │   └── Exceptions/         # Jerarquía de excepciones HTTP (400, 401, 403, 404, 409, 500)
│   ├── Middleware/
│   │   ├── CorsMiddleware.php
│   │   ├── ErrorHandlerMiddleware.php
│   │   ├── FileLoggerMiddleware.php
│   │   ├── JwtAuthMiddleware.php
│   │   └── RoleMiddleware.php
│   ├── Routes/                 # Módulos de rutas desacoplados por dominio
│   │   ├── AdminRoutes.php     # Rutas de fiscalización y analítica del Ente
│   │   ├── ApiRoutes.php       # Orquestador general y prefijos /api/v1 y /v1
│   │   ├── AuthRoutes.php      # Rutas de autenticación Google y credenciales
│   │   ├── ComercioRoutes.php  # Rutas de canje en mostrador y gestión comercial
│   │   └── TuristaRoutes.php   # Rutas de cupones, catálogo y encuestas
│   └── Services/
│       ├── GoogleAuthService.php # Verificación de tokens de Google OAuth
│       └── JwtService.php        # Codificación y decodificación de tokens HS256
├── storage/
│   ├── cache/
│   └── logs/                   # app.log con rotación y bloqueos atómicos
├── tests/
│   ├── Feature/                # Pruebas funcionales de enrutador y respuestas HTTP
│   └── Unit/                   # Pruebas unitarias de JWT y servicios
├── .env.example
├── composer.json
└── phpunit.xml
```

---

## Catálogo de Endpoints Principales

| Método | Endpoint | Rol Mínimo | Descripción |
| :--- | :--- | :--- | :--- |
| `GET` | `/health` | Público | Comprobación viva de servicio y conexión a `huakipromo` |
| `POST` | `/api/v1/auth/google` | Público | Login turista vía Google OAuth ID Token |
| `POST` | `/api/v1/auth/login` | Público | Login comercial/admin con email y password |
| `GET` | `/api/v1/auth/perfil` | Bearer JWT | Datos del usuario/turista en sesión |
| `GET` | `/api/v1/categorias` | Público | Listado de rubros comerciales |
| `GET` | `/api/v1/circuitos` | Público | Circuitos turísticos de Tucumán |
| `GET` | `/api/v1/modalidades` | Público | Modalidades de descuento (2x1, 10% OFF, etc.) |
| `GET` | `/api/v1/ofertas` | Público | Catálogo de ofertas vigentes con filtros |
| `GET` | `/api/v1/ofertas/:id` | Público | Detalle y condiciones de una oferta puntual |
| `POST` | `/api/v1/cupones` | `turista` | Emisión digital de cupón con código y QR |
| `GET` | `/api/v1/mis-cupones` | `turista` | Cupones emitidos del turista autenticado |
| `GET` | `/api/v1/cupones/:codigo` | Público / Turista | Ficha de cupón para renderizado de QR |
| `POST` | `/api/v1/canjes/validar` | `empleado` / `encargado` | Pre-validación en mostrador (lectura QR) |
| `POST` | `/api/v1/canjes/confirmar` | `empleado` / `encargado` | Canje definitivo y auditoría en mostrador |
| `GET` | `/api/v1/comercio/promociones` | `encargado` | Promociones del comercio logueado |
| `POST` | `/api/v1/comercio/promociones` | `encargado` | Alta de promoción (pasa a fiscalización) |
| `POST` | `/api/v1/encuestas` | Público / Turista | Respuestas para el Observatorio Turístico |
| `GET` | `/api/v1/admin/promociones/pendientes` | `admin_ente` | Cola de fiscalización institucional |
| `POST` | `/api/v1/admin/promociones/:id/aprobar`| `admin_ente` | Publicación formal en cuponera |

---

## Puesta en Marcha

### 1. Variables de Entorno
Copiar el archivo de configuración y ajustar credenciales:
```bash
cp .env.example .env
```

### 2. Autoloader
```bash
composer dump-autoload
```

### 3. Servidor de Desarrollo Local
```bash
composer start
```
El servicio estará disponible en `http://127.0.0.1:3001` (o puerto configurado en `PORT`).

### 4. Ejecución de Tests Automatizados
```bash
composer test
```

---

## Cómo agregar un nuevo endpoint a la API

Gracias a la arquitectura modular y al autowiring del contenedor DI, incorporar nuevos endpoints toma solo 2 pasos:

1. **Crear o ampliar el Controlador (`src/Controllers/`)**:
   Escribir el método recibiendo `Request $request` (y opcionalmente `array $params`) y retornando un `Response`:
   ```php
   public function miNuevoMetodo(Request $request): Response
   {
       $resultado = $this->sp->call('sp_mi_procedimiento', [...]);
       return Response::ok($resultado);
   }
   ```

2. **Registrar la Ruta en su Módulo Correspondiente (`src/Routes/`)**:
   Agregar una línea en `TuristaRoutes.php`, `ComercioRoutes.php`, `AdminRoutes.php` o `AuthRoutes.php`:
   ```php
   $router->get('/mi-ruta', [MiController::class, 'miNuevoMetodo'], [JwtAuthMiddleware::class]);
   ```
   *El sistema la publicará automáticamente bajo `/api/v1/mi-ruta` y `/v1/mi-ruta` con los middlewares aplicados.*
