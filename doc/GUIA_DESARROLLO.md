# Guía para Desarrolladores

Esta guía describe el flujo estándar para trabajar en la API, agregar nuevos endpoints, conectarse a los procedimientos almacenados y ejecutar las suites de pruebas.

---

## 1. Configuración del Entorno de Desarrollo

1. **Variables de entorno:**
   Asegurar que `.env` esté configurado con las credenciales de la base de datos `huakipromo` y el secreto JWT:
   ```dotenv
   PORT=3001
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=huakipromo
   DB_USER=root
   DB_PASSWORD=
   JWT_SECRET=tu_clave_secreta_de_al_menos_32_caracteres_2026
   CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:4321,http://localhost:5173
   ```

2. **Regenerar Autoloader:**
   ```bash
   composer dump-autoload
   ```

3. **Iniciar el Servidor Local:**
   ```bash
   composer start
   ```
   Estará disponible en `http://127.0.0.1:3001`.

---

## 2. Cómo agregar un nuevo Endpoint en 2 Pasos

Gracias a la arquitectura desacoplada por módulos de rutas y al autowiring del contenedor DI, no es necesario tocar `public/index.php`.

### Paso 1: Crear o ampliar el Controlador (`src/Controllers/`)
Crea un método que reciba `Request $request` (y opcionalmente `array $params` para parámetros de URL) y devuelva un `Response`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;

class SucursalesController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller()
    ) {}

    public function index(Request $request): Response
    {
        // 1. Invocación de procedimiento almacenado
        $filas = $this->sp->call('sp_sucursales_obtener', [0]);
        
        // 2. Respuesta estándar
        return Response::ok($filas);
    }
}
```

### Paso 2: Registrar la Ruta en el Módulo Correspondiente (`src/Routes/`)
Abre el módulo temático según corresponda:
* `TuristaRoutes.php` para cupones, catálogo y público.
* `ComercioRoutes.php` para mostrador y prestadores.
* `AdminRoutes.php` para fiscalización institucional.
* `AuthRoutes.php` para sesiones.

Agrega una línea:
```php
$router->get('/comercio/sucursales', [SucursalesController::class, 'index'], [JwtAuthMiddleware::class]);
```

*El enrutador publicará automáticamente el endpoint tanto bajo `/api/v1/comercio/sucursales` como bajo `/v1/comercio/sucursales` con el middleware de autenticación inyectado.*

---

## 3. Manejo de Procedimientos Almacenados (`StoredProcedureCaller`)

Ubicado en `src/Database/StoredProcedureCaller.php`:

* **`call(string $spName, array $params = []): array`**: Devuelve una lista de registros asociativos (para listados).
* **`callSingle(string $spName, array $params = []): ?array`**: Devuelve el primer registro o `null` si no existe (para fichas y búsquedas por clave).
* **`callWithOut(string $spName, array $params = []): int|string`**: Para procedimientos de creación que devuelven `@p_id_generado` en el último parámetro.

---

## 4. Ejecución de Pruebas Automatizadas

```bash
composer test
```
Ejecuta la suite completa de PHPUnit (`tests/Unit/` y `tests/Feature/`) comprobando enrutamiento, generación/validación de JWT y middleware de seguridad.

Para verificar la conexión activa y las firmas de los procedimientos almacenados en MySQL:
```bash
composer run test:procedures
```
