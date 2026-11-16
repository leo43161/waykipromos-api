<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ConflictException;
use App\Domain\Exceptions\NotFoundException;
use App\Domain\Exceptions\UnauthorizedException;
use App\Domain\Exceptions\ValidationException;

class CuponesController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller()
    ) {}

    /**
     * POST /api/v1/cupones
     * Emisión digital de cupón con código y QR único para el turista autenticado.
     */
    public function emitir(Request $request): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            throw new UnauthorizedException('Debe iniciar sesión para obtener un cupón.', 'AUTENTICACION_REQUERIDA');
        }

        $turistaId = (int) ($user['turistas_id'] ?? 0);
        if ($turistaId <= 0) {
            throw new UnauthorizedException('Perfil de turista inválido en la sesión.', 'TURISTA_INVALIDO');
        }

        $body = $request->getParsedBody();
        $promocionId = (int) ($body['promocion_id'] ?? 0);

        if ($promocionId <= 0) {
            throw new ValidationException('El parámetro promocion_id es obligatorio', ['promocion_id' => 'Requerido']);
        }

        // 1. Obtener datos de la promoción y validar disponibilidad
        $promo = $this->sp->callSingle('sp_promociones_obtener', [$promocionId]);
        if ($promo === null) {
            throw new NotFoundException('La promoción solicitada no existe.', 'PROMOCION_NO_ENCONTRADA');
        }

        if (strtoupper((string) ($promo['estado'] ?? '')) !== 'PUBLICADA' || (int) ($promo['activo'] ?? 0) !== 1) {
            throw new ConflictException('La promoción no se encuentra activa para emisión.', 'PROMOCION_NO_DISPONIBLE');
        }

        $stockDisponible = (int) ($promo['stock_disponible'] ?? 0);
        if ($stockDisponible <= 0) {
            throw new ConflictException('El cupo disponible para esta promoción se ha agotado.', 'CUPO_AGOTADO');
        }

        // 2. Verificar si el turista ya posee un cupón activo para esta promoción
        $cuponExistente = $this->sp->callSingle('sp_cupones_verificar_turista_promo', [$turistaId, $promocionId]);
        if ($cuponExistente !== null) {
            return Response::ok([
                'cupon'   => $cuponExistente,
                'mensaje' => 'Ya posees un cupón activo para esta promoción.',
            ]);
        }

        // 3. Generar código y hash QR únicos
        $codigo = 'WP-2026-' . strtoupper(bin2hex(random_bytes(3)));
        $qrHash = hash('sha256', $codigo . microtime(true) . bin2hex(random_bytes(8)));
        $venceEn = (string) ($promo['fecha_fin'] ?? date('Y-m-d', strtotime('+30 days'))) . ' 23:59:59';

        // 4. Crear cupón atómicamente
        $nuevoId = $this->sp->callWithOut('sp_cupones_crear', [
            $codigo,
            $qrHash,
            $promocionId,
            $turistaId,
            $venceEn,
        ]);

        $cuponId = (int) $nuevoId;

        return Response::ok([
            'cupon_id'     => $cuponId,
            'codigo'       => $codigo,
            'qr_hash'      => $qrHash,
            'promocion_id' => $promocionId,
            'titulo'       => $promo['titulo'] ?? '',
            'vence_en'     => $venceEn,
            'estado'       => 'EMITIDO',
        ], null, 201);
    }

    /**
     * GET /api/v1/mis-cupones
     * Historial de cupones solicitados por el turista autenticado.
     */
    public function misCupones(Request $request): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            throw new UnauthorizedException('Debe iniciar sesión para ver sus cupones.');
        }

        $turistaId = (int) ($user['turistas_id'] ?? 0);
        $rows = $this->sp->call('sp_cupones_listar_por_turista', [$turistaId]);

        return Response::ok($rows);
    }

    /**
     * GET /api/v1/cupones/:codigo
     * Consulta de un cupón puntual para renderizado móvil y QR.
     */
    public function show(Request $request, array $params = []): Response
    {
        $codigo = trim((string) ($params['codigo'] ?? $request->getAttribute('codigo') ?? ''));
        if ($codigo === '') {
            throw new NotFoundException('Código de cupón requerido');
        }

        $cupon = $this->sp->callSingle('sp_cupones_obtener_por_codigo', [$codigo]);
        if ($cupon === null) {
            throw new NotFoundException('Cupón no encontrado', 'CUPON_NO_ENCONTRADO');
        }

        return Response::ok($cupon);
    }
}
