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

class CuponesController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller()
    ) {}

    /**
     * POST /api/v1/cupones (alias: POST /api/v1/promociones/:id/canjear)
     * Emisión digital y guardado del cupón en el perfil del turista autenticado.
     */
    public function emitir(Request $request, array $params = []): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            throw new UnauthorizedException('Debe iniciar sesión para obtener un cupón.', 'AUTENTICACION_REQUERIDA');
        }

        $rol = (string) ($user['rol'] ?? '');
        if (!in_array($rol, ['turista', 'superadmin'], true)) {
            throw new ForbiddenException('Solo los usuarios con rol turista pueden guardar cupones en su perfil.', 'SOLO_TURISTAS');
        }

        $turistaId = (int) ($user['turistas_id'] ?? 0);
        if ($turistaId <= 0) {
            throw new UnauthorizedException('Perfil de turista inválido en la sesión.', 'TURISTA_INVALIDO');
        }

        $body = $request->getParsedBody();
        $promocionId = (int) ($body['promocion_id'] ?? $params['id'] ?? $request->getAttribute('id') ?? 0);

        if ($promocionId <= 0) {
            throw new ValidationException('El parámetro promocion_id es obligatorio', ['promocion_id' => 'Requerido']);
        }

        // Límite máximo de cupones activos en perfil (Directiva EATT: máx 5 cupones simultáneos)
        $cuponesTurista = $this->sp->call('sp_cupones_listar_por_turista', [$turistaId]);
        $ahoraTimestamp = time();
        $activos = array_filter($cuponesTurista, function (array $c) use ($ahoraTimestamp): bool {
            $estado = strtoupper((string) ($c['estado'] ?? ''));
            $vence = strtotime((string) ($c['vence_en'] ?? ''));
            return $estado === 'EMITIDO' && ($vence === false || $vence > $ahoraTimestamp);
        });

        if (count($activos) >= 5) {
            throw new ConflictException(
                'Has alcanzado el límite máximo de 5 cupones activos guardados en tu perfil. Utiliza o espera el vencimiento de tus cupones antes de solicitar uno nuevo.',
                'LIMITE_CUPONES_ALCANZADO'
            );
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

        // 3. Generar código alfanumérico y hash QR únicos
        $circuitoPrefix = 'CAL';
        if (!empty($promo['circuito_nombre'])) {
            $limpio = preg_replace('/[^A-Za-z]/', '', (string) $promo['circuito_nombre']);
            if (strlen($limpio) >= 3) {
                $circuitoPrefix = strtoupper(substr($limpio, 0, 3));
            }
        }
        $codigo = 'WYK-' . $circuitoPrefix . '-' . rand(1000, 9999);
        $qrHash = hash('sha256', $codigo . microtime(true) . bin2hex(random_bytes(8)));

        // 4. Vigencia oficial EATT: 14 días (o fecha_fin de la promoción si vence antes)
        $limite14dias = date('Y-m-d H:i:s', strtotime('+14 days'));
        $venceEn = $limite14dias;
        if (!empty($promo['fecha_fin'])) {
            $fechaFinPromo = (string) $promo['fecha_fin'] . ' 23:59:59';
            if (strtotime($fechaFinPromo) < strtotime($limite14dias)) {
                $venceEn = $fechaFinPromo;
            }
        }

        // 5. Crear cupón atómicamente
        $nuevoId = $this->sp->callWithOut('sp_cupones_crear', [
            $codigo,
            $qrHash,
            $promocionId,
            $turistaId,
            $venceEn,
        ]);

        $cuponId = (int) $nuevoId;
        $comercioNombre = (string) ($promo['comercio_nombre'] ?? $promo['nombre_fantasia'] ?? $promo['comercio'] ?? 'Comercio Adherido');
        $circuitoNombre = (string) ($promo['circuito_nombre'] ?? $promo['circuito'] ?? 'Tucumán');
        $tituloOferta = (string) ($promo['titulo'] ?? '');

        return Response::ok([
            'cupon_id'      => $cuponId,
            'codigo_cupon'  => $codigo,
            'codigo'        => $codigo, // retrocompatibilidad
            'qr_hash'       => $qrHash,
            'promocion_id'  => $promocionId,
            'titulo_oferta' => $tituloOferta,
            'titulo'        => $tituloOferta, // retrocompatibilidad
            'comercio'      => $comercioNombre,
            'circuito'      => $circuitoNombre,
            'turistas_id'   => $turistaId,
            'estado'        => 'EMITIDO',
            'emitido_en'    => gmdate('Y-m-d\TH:i:s\Z'),
            'vence_en'      => gmdate('Y-m-d\TH:i:s\Z', strtotime($venceEn)),
            'mensaje'       => '¡Cupón guardado con éxito en tu perfil! Preséntalo en el mostrador del prestador para obtener el beneficio.',
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
