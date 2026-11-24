# Documentación Técnica: API WaykiPromos

Backend REST for Frontend (BFF) desarrollado para la plataforma de beneficios turísticos del **Ente Autárquico Tucumán Turismo**.  
Implementado en **PHP 8.4+ / 8.5 Puro**, bajo principios de **Clean Architecture**, alta concurrencia, cero dependencias pesadas de frameworks en producción y persistencia mediante procedimientos almacenados en MySQL (`huakipromo`).

---

## Índice de Contenidos

| Documento | Descripción |
| :--- | :--- |
| 📋 [Endpoints y Trazabilidad](ENDPOINTS_Y_TRAZABILIDAD.md) | Especificación exhaustiva de los **46 endpoints canónicos (Contrato v1)**, DTOs, roles RBAC y procedimientos `sp_*`. |
| 🗄️ [Matriz SPs huakipromo](MATRIZ_SP_EXISTENTES_HUAKIPROMO.md) | Mapeo detallado de los **124 procedimientos almacenados existentes** en la base de datos contra el contrato v1. |
| 🏗️ [Arquitectura y Estructura](ARQUITECTURA.md) | Principios de diseño, ciclo de vida de la petición, Container DI, Router modular y StoredProcedureCaller. |
| 🔐 [Autenticación y Seguridad JWT](AUTENTICACION_Y_JWT.md) | Validación federada con Google OAuth, credenciales del personal, emisión de tokens HS256 y Middleware RBAC. |
| ⚡ [Auditoría y Resiliencia](AUDITORIA_Y_RESILIENCIA.md) | Auditoría de concurrencia, puntos fuertes, factores a monitorear y optimizaciones anti-colapso. |
| 🛠️ [Guía para Desarrolladores](GUIA_DESARROLLO.md) | Cómo crear controladores, registrar nuevas rutas modulares, testing con PHPUnit y smoke tests. |

---

## Resumen del Stack Tecnológico

* **Lenguaje:** PHP 8.4+ / PHP 8.5 (Strict types habilitado en todos los archivos).
* **Patrón Arquitectónico:** Clean Architecture / BFF (Backend for Frontend).
* **Contrato de API:** 46 endpoints RESTful unificados bajo `/v1` y `/api/v1`.
* **Middlewares:** Pipeline estilo PSR-15 (CORS, Error Handler, Logger de disco y JWT Auth / Role RBAC).
* **Inyección de Dependencias:** Contenedor propio con autowiring por Reflection.
* **Persistencia:** Base de datos MySQL `huakipromo` invocada mediante `CALL sp_*` y transacciones ACID.
* **Testing:** PHPUnit 13.4 con 100% de tests passing (31 pruebas, 225 aserciones).
