# Documentación Técnica: API Wayki Promo (TuCupón)

Backend REST for Frontend (BFF) desarrollado para la plataforma de beneficios turísticos del **Ente Autárquico Tucumán Turismo**.  
Implementado en **PHP 8.4+ / 8.5 Puro**, bajo principios de **Clean Architecture**, alta concurrencia, cero dependencias pesadas de frameworks en producción y persistencia mediante procedimientos almacenados en MySQL (`huakipromo`).

---

## Índice de Contenidos

| Documento | Descripción |
| :--- | :--- |
| 🏗️ [Arquitectura y Estructura](ARQUITECTURA.md) | Principios de diseño, ciclo de vida de la petición, Container DI, Router modular y StoredProcedureCaller. |
| 🔐 [Autenticación y Seguridad JWT](AUTENTICACION_Y_JWT.md) | Validación federada con Google OAuth, credenciales comerciales, emisión de tokens HS256 y Middleware RBAC. |
| 📋 [Endpoints y Trazabilidad](ENDPOINTS_Y_TRAZABILIDAD.md) | Matriz completa de los 15 Casos de Uso (CU-01 a CU-15), contratos REST BFF y llamadas a procedimientos `sp_*`. |
| 🛠️ [Guía para Desarrolladores](GUIA_DESARROLLO.md) | Cómo crear controladores, registrar nuevas rutas modulares, testing con PHPUnit y smoke tests. |

---

## Resumen del Stack Tecnológico

* **Lenguaje:** PHP 8.4+ / PHP 8.5 (Strict types habilitado en todos los archivos).
* **Patrón Arquitectónico:** Clean Architecture / BFF (Backend for Frontend).
* **Middlewares:** Pipeline estilo PSR-15 (CORS, Error Handler, Logger de disco y JWT Auth).
* **Inyección de Dependencias:** Contenedor propio con autowiring por Reflection.
* **Persistencia:** Base de datos dedicada `huakipromo` invocada únicamente mediante `CALL sp_*`.
* **Testing:** PHPUnit 13.4 para pruebas unitarias y de integración de rutas.
