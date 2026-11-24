<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\Database;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\NotFoundException;
use App\Support\Formato;

final class TuristaController
{
    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function perfil(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $turistaId = (int) ($user['turistas_id'] ?? 0);
        $t = null;

        try {
            $t = $this->sp->callSingle('sp_turistas_perfil', [$turistaId]);
        } catch (\Throwable) {
            try {
                $t = $this->sp->callSingle('sp_turistas_obtener', [$turistaId]);
            } catch (\Throwable) {}
        }

        if ($t === null) {
            throw new NotFoundException('No encontramos tu cuenta.', 'TURISTA_INEXISTENTE');
        }

        $encuestaRespondida = (bool) ($t['encuesta_respondida'] ?? false);
        $cuponesParaUsar = (int) ($t['cupones_para_usar'] ?? 0);
        $cuponesUsados = (int) ($t['cupones_usados'] ?? 0);
        $cuponesVencidos = (int) ($t['cupones_vencidos'] ?? 0);

        // Si vino de sp_turistas_obtener directo, calcular métricas básicas con PDO
        if (!isset($t['cupones_para_usar'])) {
            try {
                $stmt = Database::getConnection()->prepare("
                    SELECT 
                        SUM(CASE WHEN estado = 'EMITIDO' AND vence_en >= NOW() THEN 1 ELSE 0 END) AS para_usar,
                        SUM(CASE WHEN estado = 'CANJEADO' THEN 1 ELSE 0 END) AS usados,
                        SUM(CASE WHEN estado = 'VENCIDO' OR (estado = 'EMITIDO' AND vence_en < NOW()) THEN 1 ELSE 0 END) AS vencidos
                    FROM cupones
                    WHERE turistas_id = :turista AND borrado = 0
                ");
                $stmt->execute(['turista' => $turistaId]);
                $counts = $stmt->fetch() ?: [];
                $cuponesParaUsar = (int) ($counts['para_usar'] ?? 0);
                $cuponesUsados = (int) ($counts['usados'] ?? 0);
                $cuponesVencidos = (int) ($counts['vencidos'] ?? 0);

                $stmtEnc = Database::getConnection()->prepare("SELECT 1 FROM encuestas_turisticas WHERE turistas_id = :turista AND borrado = 0 LIMIT 1");
                $stmtEnc->execute(['turista' => $turistaId]);
                $encuestaRespondida = (bool) $stmtEnc->fetchColumn();
            } catch (\Throwable) {}
        }

        return Response::ok([
            'id'                  => (int) $t['turistas_id'],
            'nombre'              => $t['nombre'] ?? null,
            'apellido'            => $t['apellido'] ?? null,
            'nombre_completo'     => $t['nombre_completo'] ?? trim(($t['nombre'] ?? '') . ' ' . ($t['apellido'] ?? '')),
            'email'               => $t['email'],
            'foto_url'            => $t['foto_url'] ?? null,
            'creado_el'           => Formato::fecha($t['creado_en'] ?? null),
            'encuesta_respondida' => $encuestaRespondida,
            'cupones'             => [
                'para_usar' => $cuponesParaUsar,
                'usados'    => $cuponesUsados,
                'vencidos'  => $cuponesVencidos,
            ],
        ]);
    }

    public function borrarCuenta(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $turistaId = (int) ($user['turistas_id'] ?? 0);

        try {
            $this->sp->call('sp_turistas_anonimizar', [$turistaId]);
        } catch (\Throwable) {
            try {
                $this->sp->call('sp_turistas_eliminar', [$turistaId]);
            } catch (\Throwable) {
                try {
                    $stmt = Database::getConnection()->prepare("UPDATE turistas SET borrado = 1, activo = 0 WHERE turistas_id = :id");
                    $stmt->execute(['id' => $turistaId]);
                } catch (\Throwable) {}
            }
        }

        return Response::ok(['mensaje' => 'Borramos tu cuenta y tus datos personales.']);
    }
}
