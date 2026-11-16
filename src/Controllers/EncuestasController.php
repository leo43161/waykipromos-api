<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ValidationException;

class EncuestasController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller()
    ) {}

    /**
     * POST /api/v1/encuestas
     * Registra respuestas del perfil turístico del turista para el Observatorio Turístico.
     */
    public function guardar(Request $request): Response
    {
        $user = $request->getAttribute('user');
        $turistaId = (int) ($user['turistas_id'] ?? $request->getParsedBody()['turistas_id'] ?? 0);

        if ($turistaId <= 0) {
            throw new ValidationException('Identificador de turista requerido para la encuesta.');
        }

        $body = $request->getParsedBody();

        $rangoEdadId  = (int) ($body['rango_edad_id'] ?? 1);
        $localidadId  = isset($body['localidad_procedencia_id']) ? (int) $body['localidad_procedencia_id'] : null;
        $paisId       = isset($body['pais_procedencia_id']) ? (int) $body['pais_procedencia_id'] : null;
        $duracionId   = (int) ($body['duracion_id'] ?? 1);
        $provinciaId  = (int) ($body['provincias_provincia_id'] ?? $body['provincia_id'] ?? 1);
        $companiaId   = (int) ($body['companias_companias_id'] ?? $body['compania_id'] ?? 1);
        $motivoId     = (int) ($body['motivos_visita_motivo_id'] ?? $body['motivo_id'] ?? 1);
        $rrssId       = (int) ($body['rrss_rrss_id'] ?? $body['rrss_id'] ?? 1);

        $nuevoId = $this->sp->callWithOut('sp_encuestas_turisticas_crear', [
            $rangoEdadId,
            $localidadId,
            $paisId,
            $duracionId,
            $provinciaId,
            $companiaId,
            $motivoId,
            $rrssId,
            $turistaId,
        ]);

        return Response::ok([
            'encuesta_id' => (int) $nuevoId,
            'mensaje'     => 'Encuesta turística guardada exitosamente. ¡Gracias por participar!',
        ], null, 201);
    }
}
