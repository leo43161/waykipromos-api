<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
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
        $t = $this->sp->callSingle('sp_turistas_perfil', [$turistaId])
            ?? throw new NotFoundException('No encontramos tu cuenta.', 'TURISTA_INEXISTENTE');

        return Response::ok([
            'id'                  => (int) $t['turistas_id'],
            'nombre'              => $t['nombre'] ?? null,
            'apellido'            => $t['apellido'] ?? null,
            'nombre_completo'     => $t['nombre_completo'] ?? null,
            'email'               => $t['email'],
            'foto_url'            => $t['foto_url'] ?? null,
            'creado_el'           => Formato::fecha($t['creado_en'] ?? null),
            'encuesta_respondida' => (bool) ($t['encuesta_respondida'] ?? false),
            'cupones'             => [
                'para_usar' => (int) ($t['cupones_para_usar'] ?? 0),
                'usados'    => (int) ($t['cupones_usados'] ?? 0),
                'vencidos'  => (int) ($t['cupones_vencidos'] ?? 0),
            ],
        ]);
    }

    public function borrarCuenta(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $turistaId = (int) ($user['turistas_id'] ?? 0);
        $this->sp->call('sp_turistas_anonimizar', [$turistaId]);
        return Response::ok(['mensaje' => 'Borramos tu cuenta y tus datos personales.']);
    }
}
