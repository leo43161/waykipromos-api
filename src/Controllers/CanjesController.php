<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ConflictException;
use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\NotFoundException;
use App\Domain\Exceptions\UnauthorizedException;
use App\Domain\Exceptions\ValidationException;

class CanjesController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller()
    ) {}

    /**
     * POST /api/v1/canjes/validar
     * Pre-validación en mostrador del cupón (vía QR o código alfanumérico) sin modificar su estado.
     */
    public function validar(Request $request): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            throw new UnauthorizedException('Usuario no autenticado.');
        }

        $comercioId = (int) ($user['comercio_id'] ?? 0);
        $esAdmin = in_array((string) ($user['rol'] ?? ''), ['admin_ente', 'superadmin'], true);

        $body = $request->getParsedBody();
        $codigo = trim((string) ($body['codigo'] ?? ''));
        $qrHash = trim((string) ($body['qr_hash'] ?? ''));

        if ($codigo === '' && $qrHash === '') {
            throw new ValidationException('Debe ingresar un código o escanear el código QR.', [
                'codigo' => 'Requerido si no se proporciona qr_hash',
            ]);
        }

        // Buscar el cupón
        $cupon = $codigo !== ''
            ? $this->sp->callSingle('sp_cupones_obtener_por_codigo', [$codigo])
            : $this->sp->callSingle('sp_cupones_obtener_por_qr_hash', [$qrHash]);

        if ($cupon === null) {
            throw new NotFoundException('El cupón no existe en el sistema.', 'CUPON_INEXISTENTE');
        }

        // Validar correspondencia de comercio
        $cuponComercioId = (int) ($cupon['comercio_id'] ?? 0);
        if (!$esAdmin && $cuponComercioId !== $comercioId) {
            throw new ForbiddenException(
                'Este cupón pertenece a otro establecimiento adherido.',
                'CUPON_OTRO_COMERCIO'
            );
        }

        $estado = strtoupper((string) ($cupon['estado'] ?? ''));
        if ($estado === 'CANJEADO') {
            throw new ConflictException('Este cupón ya fue canjeado con anterioridad.', 'CUPON_YA_CANJEADO', [
                'canjeado_en' => $cupon['canjeado_en'] ?? null,
            ]);
        }

        if ($estado === 'VENCIDO' || strtotime((string) ($cupon['vence_en'] ?? 'now')) < time()) {
            throw new ConflictException('Este cupón ha caducado y no puede ser utilizado.', 'CUPON_VENCIDO', [
                'vence_en' => $cupon['vence_en'] ?? null,
            ]);
        }

        return Response::ok([
            'valido'       => true,
            'cupon_id'     => $cupon['cupon_id'],
            'codigo'       => $cupon['codigo'],
            'promocion'    => [
                'id'       => $cupon['promocion_id'],
                'titulo'   => $cupon['titulo'],
                'beneficio'=> $cupon['modalidad_nombre'] ?? 'Descuento',
            ],
            'turista'      => [
                'nombre'   => $cupon['turista_nombre'] ?? 'Turista',
                'email'    => $cupon['turista_email'] ?? '',
            ],
            'vence_en'     => $cupon['vence_en'],
        ]);
    }

    /**
     * POST /api/v1/canjes/confirmar
     * Canje atómico en mostrador. Registra la auditoría y marca el cupón como CANJEADO.
     */
    public function confirmar(Request $request): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            throw new UnauthorizedException('Usuario no autenticado.');
        }

        $usuarioId  = (int) ($user['usuario_id'] ?? 0);
        $comercioId = (int) ($user['comercio_id'] ?? 0);
        $esAdmin    = in_array((string) ($user['rol'] ?? ''), ['admin_ente', 'superadmin'], true);

        $body = $request->getParsedBody();
        $cuponId = (int) ($body['cupon_id'] ?? 0);
        $codigo  = trim((string) ($body['codigo'] ?? ''));
        $observaciones = trim((string) ($body['observaciones'] ?? ''));

        if ($cuponId <= 0 && $codigo === '') {
            throw new ValidationException('Debe proporcionar cupon_id o código.');
        }

        if ($cuponId <= 0 && $codigo !== '') {
            $c = $this->sp->callSingle('sp_cupones_obtener_por_codigo', [$codigo]);
            if ($c === null) {
                throw new NotFoundException('Cupón no encontrado.');
            }
            $cuponId = (int) $c['cupon_id'];
        }

        // Ejecutar canje atómico
        $canjeId = $this->sp->callWithOut('sp_canjes_crear', [
            $cuponId,
            $usuarioId,
            $observaciones !== '' ? $observaciones : null,
        ]);

        return Response::ok([
            'canje_id'    => $canjeId,
            'cupon_id'    => $cuponId,
            'canjeado_en' => date('Y-m-d H:i:s'),
            'mensaje'     => 'Cupón canjeado exitosamente.',
        ]);
    }
}
