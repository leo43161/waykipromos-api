<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ValidationException;
use App\Support\Acceso;
use App\Support\Actividad;
use App\Support\ApiException;
use App\Support\Formato;

final class ImagenesController
{
    private const MAX_BYTES = 2097152; // 2 MB
    private const MAX_ANCHO = 1200;

    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function subirPromocion(Request $request, array $params): Response
    {
        $promo = $this->promocion($request, (int) ($params['id'] ?? 0));
        $archivo = $this->guardar($_FILES['imagen'] ?? null, 'promociones', (int) $promo['promocion_id']);
        $this->sp->call('sp_promociones_imagen', [(int) $promo['promocion_id'], $archivo]);
        $this->borrarArchivo($promo['imagen_url'] ?? null);

        $user = $request->getAttribute('user') ?? [];
        Actividad::registrar(
            $request,
            'PROMOCION_IMAGEN',
            sprintf('%s cambió la imagen de "%s"', $user['nombre'] ?? 'Usuario', $promo['titulo'] ?? ''),
            (int) $promo['comercio_id'],
            'promocion',
            (int) $promo['promocion_id']
        );

        return Response::ok(['imagen_url' => Formato::url($archivo)]);
    }

    public function borrarPromocion(Request $request, array $params): Response
    {
        $promo = $this->promocion($request, (int) ($params['id'] ?? 0));
        $this->sp->call('sp_promociones_imagen', [(int) $promo['promocion_id'], null]);
        $this->borrarArchivo($promo['imagen_url'] ?? null);
        return Response::ok(['imagen_url' => null]);
    }

    public function subirLogo(Request $request, array $params): Response
    {
        $comercio = $this->comercio($request, (int) ($params['id'] ?? 0));
        $archivo = $this->guardar($_FILES['imagen'] ?? null, 'comercios', (int) $comercio['comercio_id']);
        $this->sp->call('sp_comercios_logo', [(int) $comercio['comercio_id'], $archivo]);
        $this->borrarArchivo($comercio['logo_url'] ?? null);
        return Response::ok(['logo_url' => Formato::url($archivo)]);
    }

    public function borrarLogo(Request $request, array $params): Response
    {
        $comercio = $this->comercio($request, (int) ($params['id'] ?? 0));
        $this->sp->call('sp_comercios_logo', [(int) $comercio['comercio_id'], null]);
        $this->borrarArchivo($comercio['logo_url'] ?? null);
        return Response::ok(['logo_url' => null]);
    }

    private function promocion(Request $request, int $id): array
    {
        $user = $request->getAttribute('user') ?? [];
        $fila = $this->sp->callSingle('sp_promociones_gestion', [$id, 0, '', 0, '', 1, 0]);
        return Acceso::delComercio($user, $fila, 'No existe esa promoción.');
    }

    private function comercio(Request $request, int $id): array
    {
        $user = $request->getAttribute('user') ?? [];
        $fila = $this->sp->callSingle('sp_comercios_listar', [$id, '', '', 0, 0, 1, 0]);
        return Acceso::delComercio($user, $fila, 'No existe ese comercio.');
    }

    private function guardar(?array $archivo, string $carpeta, int $id): string
    {
        if ($archivo === null || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException('Elegí una imagen.', ['imagen' => 'Requerida']);
        }

        if (in_array($archivo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($archivo['size'] ?? 0) > self::MAX_BYTES) {
            throw new ApiException(413, 'La imagen pesa más de 2 MB. Probá con una más liviana.', 'ARCHIVO_MUY_GRANDE');
        }

        if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
            throw new ValidationException('No pudimos recibir la imagen. Probá de nuevo.', null, 'SUBIDA_FALLIDA');
        }

        $tipo = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        $imagen = match ($tipo) {
            'image/jpeg' => @imagecreatefromjpeg($archivo['tmp_name']),
            'image/png'  => @imagecreatefrompng($archivo['tmp_name']),
            'image/webp' => @imagecreatefromwebp($archivo['tmp_name']),
            default      => false,
        };

        if ($imagen === false) {
            throw new ApiException(415, 'Subí una imagen JPG, PNG o WebP.', 'FORMATO_NO_SOPORTADO');
        }

        if (imagesx($imagen) > self::MAX_ANCHO) {
            $imagen = imagescale($imagen, self::MAX_ANCHO);
        }

        $relativa = sprintf('%s/%d-%s.webp', $carpeta, $id, bin2hex(random_bytes(4)));
        $uploadsDir = (string) Env::get('UPLOADS_DIR', __DIR__ . '/../../public/uploads');
        $destino = rtrim($uploadsDir, '/') . '/' . $relativa;

        if (!is_dir(dirname($destino))) {
            mkdir(dirname($destino), 0755, true);
        }

        if (!imagewebp($imagen, $destino, 82)) {
            throw new ApiException(500, 'No pudimos guardar la imagen. Probá de nuevo.', 'ERROR_GUARDANDO_IMAGEN');
        }

        return $relativa;
    }

    private function borrarArchivo(?string $url): void
    {
        if (empty($url)) {
            return;
        }
        $nombre = basename($url);
        $uploadsDir = (string) Env::get('UPLOADS_DIR', __DIR__ . '/../../public/uploads');
        foreach (['promociones', 'comercios'] as $carpeta) {
            $ruta = rtrim($uploadsDir, '/') . '/' . $carpeta . '/' . $nombre;
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }
}
