<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fase 7, tarea 3 (13/10/2026): la foto de cada producto (RF-M04-004, HU-M04-002, RNF-M04-002).
 *
 * Antes el Administrador solo podía pegar una dirección de imagen y el Menú no la mostraba. Ahora la
 * sube desde Catálogo:
 *  - Se guarda en el disco privado (`storage/app/private/productos`) y se sirve por
 *    GET /api/productos/{id}/foto, así funciona con el Nginx de producción sin publicar carpetas.
 *  - Para que el menú cargue rápido en el celular (RNF-M04-002), si el servidor tiene GD se reduce
 *    a 900 px por el lado más largo y se guarda en WebP (o JPEG si no hay WebP). Sin GD se guarda
 *    tal cual llegó.
 *  - `imagen_url` queda con la dirección y una versión (?v=…), para que el navegador pueda
 *    guardarla en caché y una foto nueva se vea de inmediato.
 *  - Cambiar o quitar la foto queda en Auditoría (RF-M04-022).
 */
class FotoProductoService
{
    public const DISCO = 'local';
    public const CARPETA = 'productos';
    public const LADO_MAXIMO = 900;

    public function __construct(private readonly CatalogoService $catalogoService)
    {
    }

    public function guardar(Producto $producto, UploadedFile $archivo, ?User $actor): Producto
    {
        [$contenido, $extension] = $this->preparar($archivo);
        $ruta = self::CARPETA.'/'.$producto->id.'-'.Str::lower(Str::random(12)).'.'.$extension;
        Storage::disk(self::DISCO)->put($ruta, $contenido);

        $anterior = $producto->imagen_path;
        $antes = $producto->only(['imagen_url', 'imagen_path']);
        try {
            DB::transaction(function () use ($producto, $ruta, $antes, $actor) {
                $producto->forceFill([
                    'imagen_path' => $ruta,
                    'imagen_url' => '/api/productos/'.$producto->id.'/foto?v='.substr(md5($ruta), 0, 10),
                ])->save();
                $this->catalogoService->auditar('cambiar_foto_producto', $producto, $antes, $actor);
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISCO)->delete($ruta);
            throw $e;
        }

        if ($anterior && $anterior !== $ruta) {
            Storage::disk(self::DISCO)->delete($anterior);
        }

        return $producto;
    }

    public function quitar(Producto $producto, ?User $actor): Producto
    {
        $anterior = $producto->imagen_path;
        $antes = $producto->only(['imagen_url', 'imagen_path']);
        $producto->forceFill(['imagen_path' => null, 'imagen_url' => null])->save();
        $this->catalogoService->auditar('cambiar_foto_producto', $producto, $antes, $actor);
        if ($anterior) {
            Storage::disk(self::DISCO)->delete($anterior);
        }

        return $producto;
    }

    public function existe(Producto $producto): bool
    {
        return $producto->imagen_path !== null && Storage::disk(self::DISCO)->exists($producto->imagen_path);
    }

    /** @return array{0: string, 1: string} contenido y extensión del archivo a guardar */
    private function preparar(UploadedFile $archivo): array
    {
        $original = (string) file_get_contents($archivo->getRealPath());
        $extensionOriginal = match ($archivo->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring')) {
            return [$original, $extensionOriginal];
        }

        $imagen = @imagecreatefromstring($original);
        if ($imagen === false) {
            throw new RuntimeException('No pudimos leer la imagen. Prueba con otra foto (JPG, PNG o WebP).');
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $escala = min(1, self::LADO_MAXIMO / max($ancho, $alto));
        $nuevoAncho = max(1, (int) round($ancho * $escala));
        $nuevoAlto = max(1, (int) round($alto * $escala));

        $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        // Fondo transparente para PNG/WebP con transparencia.
        imagealphablending($destino, false);
        imagesavealpha($destino, true);
        imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
        imagecopyresampled($destino, $imagen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($imagen);

        ob_start();
        if (function_exists('imagewebp')) {
            imagewebp($destino, null, 80);
            $extension = 'webp';
        } else {
            imagejpeg($destino, null, 82);
            $extension = 'jpg';
        }
        $contenido = (string) ob_get_clean();
        imagedestroy($destino);

        return [$contenido, $extension];
    }
}
