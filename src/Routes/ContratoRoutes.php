<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\AuthController;
use App\Controllers\CanjesController;
use App\Controllers\CatalogoController;
use App\Controllers\ComerciosController;
use App\Controllers\ImagenesController;
use App\Controllers\LoginController;
use App\Controllers\MaestrosController;
use App\Controllers\PromocionesPanelController;
use App\Controllers\ReportesController;
use App\Controllers\TuristaController;
use App\Controllers\TuristaCuponesController;
use App\Controllers\TuristaEncuestaController;
use App\Controllers\UsuariosPanelController;
use App\Controllers\VisitantesController;
use App\Core\Router\Router;
use App\Middleware\JwtAuthMiddleware;
use App\Middleware\RoleMiddleware;

final class ContratoRoutes
{
    public static function register(Router $r): void
    {
        $solo = static fn (string ...$roles): array => [JwtAuthMiddleware::class, new RoleMiddleware($roles)];
        $turista   = $solo('turista');
        $personal  = $solo('empleado', 'encargado', 'admin_ente');
        $mostrador = $solo('empleado', 'encargado');
        $gestion   = $solo('encargado', 'admin_ente');
        $ente      = $solo('admin_ente');

        // ---- Públicos (sin token) ----
        $r->get('/categorias', [MaestrosController::class, 'categorias']);
        $r->get('/modalidades', [MaestrosController::class, 'modalidades']);
        $r->get('/circuitos', [MaestrosController::class, 'circuitos']);
        $r->get('/localidades', [MaestrosController::class, 'localidades']);
        $r->get('/promociones', [CatalogoController::class, 'listar']);
        $r->get('/promociones/:id', [CatalogoController::class, 'ficha']);
        $r->get('/encuesta/opciones', [TuristaEncuestaController::class, 'opciones']);
        $r->post('/auth/google', [AuthController::class, 'googleLogin']);
        $r->post('/auth/login', [LoginController::class, 'ingresar']);

        // ---- App del turista ----
        $r->get('/turista/perfil', [TuristaController::class, 'perfil'], $turista);
        $r->delete('/turista/cuenta', [TuristaController::class, 'borrarCuenta'], $turista);
        $r->get('/turista/encuesta', [TuristaEncuestaController::class, 'ver'], $turista);
        $r->post('/turista/encuesta', [TuristaEncuestaController::class, 'guardar'], $turista);
        $r->get('/turista/cupones', [TuristaCuponesController::class, 'listar'], $turista);
        $r->post('/turista/cupones', [TuristaCuponesController::class, 'emitir'], $turista);
        $r->get('/turista/cupones/:codigo', [TuristaCuponesController::class, 'ver'], $turista);

        // ---- Panel: sesión y resumen ----
        $r->get('/panel/sesion', [LoginController::class, 'sesion'], $personal);
        $r->put('/panel/password', [LoginController::class, 'cambiarPassword'], $personal);
        $r->get('/panel/resumen', [ReportesController::class, 'resumen'], $personal);

        // ---- Panel: canje en el mostrador ----
        $r->post('/panel/canjes/validar', [CanjesController::class, 'validar'], $mostrador);
        $r->post('/panel/canjes', [CanjesController::class, 'confirmar'], $mostrador);
        $r->get('/panel/canjes', [CanjesController::class, 'listar'], $personal);

        // ---- Panel: promociones ----
        $r->get('/panel/promociones', [PromocionesPanelController::class, 'listar'], $gestion);
        $r->get('/panel/promociones/:id', [PromocionesPanelController::class, 'ver'], $gestion);
        $r->post('/panel/promociones', [PromocionesPanelController::class, 'crear'], $gestion);
        $r->put('/panel/promociones/:id', [PromocionesPanelController::class, 'editar'], $gestion);
        $r->patch('/panel/promociones/:id/estado', [PromocionesPanelController::class, 'cambiarEstado'], $gestion);
        $r->post('/panel/promociones/:id/imagen', [ImagenesController::class, 'subirPromocion'], $gestion);
        $r->delete('/panel/promociones/:id/imagen', [ImagenesController::class, 'borrarPromocion'], $gestion);

        // ---- Panel: comercios ----
        $r->get('/panel/comercios', [ComerciosController::class, 'listar'], $ente);
        $r->get('/panel/comercios/:id', [ComerciosController::class, 'ver'], $gestion);
        $r->post('/panel/comercios', [ComerciosController::class, 'crear'], $ente);
        $r->put('/panel/comercios/:id', [ComerciosController::class, 'editar'], $gestion);
        $r->patch('/panel/comercios/:id/estado', [ComerciosController::class, 'cambiarEstado'], $ente);
        $r->post('/panel/comercios/:id/logo', [ImagenesController::class, 'subirLogo'], $gestion);
        $r->delete('/panel/comercios/:id/logo', [ImagenesController::class, 'borrarLogo'], $gestion);

        // ---- Panel: usuarios (equipo) ----
        $r->get('/panel/usuarios', [UsuariosPanelController::class, 'listar'], $gestion);
        $r->post('/panel/usuarios', [UsuariosPanelController::class, 'crear'], $gestion);
        $r->put('/panel/usuarios/:id', [UsuariosPanelController::class, 'editar'], $gestion);
        $r->patch('/panel/usuarios/:id/estado', [UsuariosPanelController::class, 'cambiarEstado'], $gestion);
        $r->post('/panel/usuarios/:id/password', [UsuariosPanelController::class, 'nuevaPassword'], $gestion);

        // ---- Panel: Ente ----
        $r->get('/panel/visitantes', [VisitantesController::class, 'listar'], $ente);
        $r->get('/panel/visitantes/:id', [VisitantesController::class, 'ver'], $ente);
        $r->get('/panel/reportes', [ReportesController::class, 'reportes'], $ente);
        $r->get('/panel/actividad', [ReportesController::class, 'actividad'], $gestion);
        $r->get('/panel/exportar/:recurso', [ReportesController::class, 'exportar'], $personal);
    }
}
