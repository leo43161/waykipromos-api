<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ValidationException;
use App\Support\Formato;

final class TuristaEncuestaController
{
    private const GRUPOS = ['rangos_edad', 'duraciones', 'motivos', 'companias', 'medios', 'provincias', 'paises'];

    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function opciones(): Response
    {
        $opciones = array_fill_keys(self::GRUPOS, []);
        try {
            $filas = $this->sp->call('sp_encuesta_opciones');
            foreach ($filas as $fila) {
                $grupo = (string) ($fila['grupo'] ?? '');
                if (isset($opciones[$grupo])) {
                    $opciones[$grupo][] = [
                        'id'     => (int) $fila['id'],
                        'nombre' => $fila['nombre'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('[TuristaEncuestaController] Error al obtener opciones: ' . $e->getMessage());
        }

        return Response::ok($opciones, null, 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    public function ver(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $turistaId = (int) ($user['turistas_id'] ?? 0);
        $fila = $this->sp->callSingle('sp_encuestas_turisticas_del_turista', [$turistaId]);

        return Response::json([
            'ok'   => true,
            'data' => $fila === null ? null : self::respuestas($fila),
        ]);
    }

    public function guardar(Request $request): Response
    {
        $b = $request->getParsedBody();
        $ids = [];
        $faltan = [];

        foreach (['rango_edad_id', 'duracion_id', 'motivo_id', 'compania_id', 'medio_id'] as $campo) {
            $ids[$campo] = (int) ($b[$campo] ?? 0);
            if ($ids[$campo] <= 0) {
                $faltan[$campo] = 'Requerido';
            }
        }

        $provincia = (int) ($b['provincia_id'] ?? 0);
        $pais = (int) ($b['pais_id'] ?? 0);

        if (($provincia > 0) === ($pais > 0)) {
            $faltan['provincia_id'] = 'Mandá provincia_id (vive en Argentina) o pais_id (vive en otro país), uno solo.';
        }

        if (!empty($faltan)) {
            throw new ValidationException('Te falta responder alguna pregunta.', $faltan, 'ENCUESTA_INCOMPLETA');
        }

        $turistaId = (int) ($request->getAttribute('user')['turistas_id'] ?? 0);

        $this->sp->call('sp_encuestas_turisticas_guardar', [
            $turistaId,
            $ids['rango_edad_id'],
            $ids['duracion_id'],
            $ids['motivo_id'],
            $ids['compania_id'],
            $ids['medio_id'],
            $provincia ?: null,
            $pais ?: null,
        ]);

        $fila = $this->sp->callSingle('sp_encuestas_turisticas_del_turista', [$turistaId]);

        return Response::ok($fila !== null ? self::respuestas($fila) : []);
    }

    public static function respuestas(array $fila): array
    {
        return [
            'rango_edad'    => ['id' => (int) $fila['rango_edad_id'], 'nombre' => $fila['rango_edad']],
            'duracion'      => ['id' => (int) $fila['duracion_id'], 'nombre' => $fila['duracion']],
            'motivo'        => ['id' => (int) $fila['motivo_id'], 'nombre' => $fila['motivo']],
            'compania'      => ['id' => (int) $fila['compania_id'], 'nombre' => $fila['compania']],
            'medio'         => ['id' => (int) $fila['medio_id'], 'nombre' => $fila['medio']],
            'provincia'     => empty($fila['provincia_id']) ? null : ['id' => (int) $fila['provincia_id'], 'nombre' => $fila['provincia']],
            'pais'          => empty($fila['pais_id']) ? null : ['id' => (int) $fila['pais_id'], 'nombre' => $fila['pais']],
            'respondida_el' => Formato::fecha($fila['respondida_en'] ?? null),
        ];
    }
}
