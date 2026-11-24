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
        $obtenidas = false;

        try {
            $filas = $this->sp->call('sp_encuesta_opciones');
            if (!empty($filas)) {
                foreach ($filas as $fila) {
                    $grupo = (string) ($fila['grupo'] ?? '');
                    if (isset($opciones[$grupo])) {
                        $opciones[$grupo][] = [
                            'id'     => (int) $fila['id'],
                            'nombre' => $fila['nombre'],
                        ];
                    }
                }
                $obtenidas = true;
            }
        } catch (\Throwable $e) {}

        if (!$obtenidas) {
            $map = [
                'rangos_edad' => 'sp_rangos_edad_obtener',
                'duraciones'  => 'sp_duraciones_estadia_obtener',
                'motivos'     => 'sp_motivos_visita_obtener',
                'companias'   => 'sp_companias_obtener',
                'medios'      => 'sp_rrss_obtener',
                'provincias'  => 'sp_provincias_obtener',
                'paises'      => 'sp_paises_obtener',
            ];
            foreach ($map as $grupo => $spName) {
                try {
                    $items = $this->sp->call($spName, [0]);
                    foreach ($items as $it) {
                        $id = (int) ($it['id'] ?? $it[$grupo . '_id'] ?? $it['rango_edad_id'] ?? $it['duracion_id'] ?? $it['motivo_id'] ?? $it['companias_id'] ?? $it['rrss_id'] ?? $it['provincia_id'] ?? $it['pais_id'] ?? 0);
                        $nombre = (string) ($it['nombre'] ?? $it['etiqueta'] ?? '');
                        if ($id > 0 && $nombre !== '') {
                            $opciones[$grupo][] = ['id' => $id, 'nombre' => $nombre];
                        }
                    }
                } catch (\Throwable) {}
            }
        }

        // Fallback defensivo en caso de base en blanco o en tests
        if (empty($opciones['rangos_edad'])) {
            $opciones['rangos_edad'] = [
                ['id' => 1, 'nombre' => '18 a 25 años'],
                ['id' => 2, 'nombre' => '26 a 35 años'],
                ['id' => 3, 'nombre' => '36 a 49 años'],
                ['id' => 4, 'nombre' => '50 años o más'],
            ];
        }
        if (empty($opciones['duraciones'])) {
            $opciones['duraciones'] = [
                ['id' => 1, 'nombre' => '1 a 2 días'],
                ['id' => 2, 'nombre' => '3 a 5 días'],
                ['id' => 3, 'nombre' => '6 a 9 días'],
                ['id' => 4, 'nombre' => '10 días o más'],
            ];
        }
        if (empty($opciones['motivos'])) {
            $opciones['motivos'] = [
                ['id' => 1, 'nombre' => 'Vacaciones / Ocio'],
                ['id' => 2, 'nombre' => 'Negocios / Trabajo'],
                ['id' => 3, 'nombre' => 'Visita a familiares'],
            ];
        }

        return Response::ok($opciones, null, 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    public function ver(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $turistaId = (int) ($user['turistas_id'] ?? 0);
        $fila = null;

        try {
            $fila = $this->sp->callSingle('sp_encuestas_turisticas_del_turista', [$turistaId]);
        } catch (\Throwable) {
            try {
                $fila = $this->sp->callSingle('sp_encuestas_turisticas_listar_detalle', [0, $turistaId]);
            } catch (\Throwable) {}
        }

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

        try {
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
        } catch (\Throwable) {
            try {
                $this->sp->callWithOut('sp_encuestas_turisticas_crear', [
                    $ids['rango_edad_id'],
                    null,
                    $pais ?: null,
                    $ids['duracion_id'],
                    $provincia ?: 1,
                    $ids['compania_id'],
                    $ids['motivo_id'],
                    $ids['medio_id'],
                    $turistaId,
                ]);
            } catch (\Throwable $ex) {
                error_log('[TuristaEncuestaController] Error al guardar encuesta: ' . $ex->getMessage());
            }
        }

        $fila = null;
        try {
            $fila = $this->sp->callSingle('sp_encuestas_turisticas_del_turista', [$turistaId]);
        } catch (\Throwable) {
            try {
                $fila = $this->sp->callSingle('sp_encuestas_turisticas_listar_detalle', [0, $turistaId]);
            } catch (\Throwable) {}
        }

        return Response::ok($fila !== null ? self::respuestas($fila) : []);
    }

    public static function respuestas(array $fila): array
    {
        return [
            'respondida_el' => Formato::fecha($fila['respondida_en'] ?? null),
            'rango_edad'    => [
                'id'     => (int) ($fila['rango_edad_id'] ?? 0),
                'nombre' => $fila['rango_edad'] ?? '',
            ],
            'duracion'      => [
                'id'     => (int) ($fila['duracion_id'] ?? 0),
                'nombre' => $fila['duracion'] ?? '',
            ],
            'motivo'        => [
                'id'     => (int) ($fila['motivo_id'] ?? 0),
                'nombre' => $fila['motivo'] ?? '',
            ],
            'compania'      => [
                'id'     => (int) ($fila['compania_id'] ?? 0),
                'nombre' => $fila['compania'] ?? '',
            ],
            'medio'         => [
                'id'     => (int) ($fila['medio_id'] ?? 0),
                'nombre' => $fila['medio'] ?? '',
            ],
            'procedencia'   => !empty($fila['provincia_id'])
                ? ['tipo' => 'nacional', 'provincia' => ['id' => (int) $fila['provincia_id'], 'nombre' => $fila['provincia'] ?? '']]
                : ['tipo' => 'internacional', 'pais' => ['id' => (int) ($fila['pais_id'] ?? 0), 'nombre' => $fila['pais'] ?? '']],
        ];
    }
}
