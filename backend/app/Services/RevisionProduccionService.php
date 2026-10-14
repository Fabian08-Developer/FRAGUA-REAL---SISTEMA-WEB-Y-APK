<?php

namespace App\Services;

use App\Models\User;
use App\Support\Pasarela\PasarelaPago;
use Illuminate\Support\Facades\Hash;

/**
 * Endurecimiento (13/10/2026): lista de revisión antes de abrir el sistema al público. La corre
 * `php artisan fragua:revisar-produccion` en el VPS (sale con error si algo grave falla) y la ve el
 * Administrador en Configuración. Solo lee configuración y cuentas; no cambia nada ni muestra
 * secretos (dice si una llave está puesta, nunca su valor).
 *
 * Niveles: error (no abrir así), aviso (revisar), ok.
 */
class RevisionProduccionService
{
    // Contraseñas que traen las cuentas sembradas o que suelen usarse de prueba.
    private const CONTRASENAS_DE_PRUEBA = ['password', 'secreto123', '12345678', 'fragua123'];

    /** @return list<array{clave: string, nivel: string, titulo: string, detalle: string}> */
    public function revisar(): array
    {
        $r = [];
        $produccion = app()->environment('production');

        $r[] = $this->item('entorno', $produccion ? 'ok' : 'error', 'Entorno de producción',
            $produccion ? 'APP_ENV=production.' : 'APP_ENV es "'.app()->environment().'": en el servidor debe ser production.');

        $debug = (bool) config('app.debug');
        $r[] = $this->item('debug', $debug ? 'error' : 'ok', 'Modo de depuración apagado',
            $debug ? 'APP_DEBUG está prendido: un error mostraría código y datos internos. Ponlo en false.' : 'APP_DEBUG=false.');

        $r[] = $this->item('llave', config('app.key') ? 'ok' : 'error', 'Llave de la aplicación',
            config('app.key') ? 'APP_KEY está puesta.' : 'Falta APP_KEY: corre php artisan key:generate.');

        foreach (['app.url' => 'APP_URL', 'fragua_real.frontend_url' => 'FRONTEND_URL'] as $config => $nombre) {
            $url = (string) config($config);
            $https = str_starts_with($url, 'https://');
            $r[] = $this->item(strtolower($nombre), $https ? 'ok' : 'error', "{$nombre} con HTTPS",
                $https ? "{$nombre} = {$url}." : "{$nombre} = \"{$url}\": debe empezar por https:// (los enlaces de los correos y el retorno de Wompi usan esta dirección).");
        }

        $r[] = $this->cuentasDePrueba();

        $correo = (string) config('mail.default');
        $r[] = $this->item('correo', in_array($correo, ['log', 'array'], true) ? 'error' : 'ok', 'Correo que sale de verdad',
            in_array($correo, ['log', 'array'], true) ? "MAIL_MAILER={$correo}: los correos no salen. Configura el SMTP." : "MAIL_MAILER={$correo}.");

        $cola = (string) config('queue.default');
        $r[] = $this->item('cola', $cola === 'sync' ? 'aviso' : 'ok', 'Cola de trabajos',
            $cola === 'sync' ? 'QUEUE_CONNECTION=sync: los correos se envían durante la petición y la vuelven lenta. Usa database con el trabajador de Supervisor.' : "QUEUE_CONNECTION={$cola}.");

        $nivelLog = (string) config('logging.channels.'.config('logging.default').'.level', 'debug');
        $r[] = $this->item('log', $nivelLog === 'debug' ? 'aviso' : 'ok', 'Registro de errores sin detalles de más',
            $nivelLog === 'debug' ? 'LOG_LEVEL=debug guarda demasiado detalle. En producción usa warning o error.' : "LOG_LEVEL={$nivelLog}.");

        $r[] = $this->pasarela();

        // Fase 7, tarea 3 (RNF-M04-002): sin GD las fotos de productos se guardan sin reducir y el
        // menú carga más lento en el celular. No impide abrir: es un aviso.
        $gd = extension_loaded('gd') && function_exists('imagecreatefromstring');
        $r[] = $this->item('fotos', $gd ? 'ok' : 'aviso', 'Fotos de productos livianas',
            $gd ? 'La extensión GD de PHP está instalada: las fotos se reducen al subirlas.' : 'Falta la extensión GD de PHP (php-gd): las fotos se guardan tal como se suben. Instálala con apt install php-gd.');

        $origenes = (array) config('cors.allowed_origins', []);
        $abierto = in_array('*', $origenes, true);
        $r[] = $this->item('cors', $abierto ? 'error' : 'ok', 'Orígenes permitidos para la API',
            $abierto ? 'CORS permite cualquier origen ("*"). Deja solo FRONTEND_URL y lo que haga falta en CORS_ORIGENES.' : 'Solo: '.implode(', ', $origenes).'.');

        return $r;
    }

    public function hayErrores(array $items): bool
    {
        return collect($items)->contains('nivel', 'error');
    }

    private function cuentasDePrueba(): array
    {
        // El personal (pocas cuentas) y las cuentas de demostración del sembrado; las contraseñas de
        // los clientes son de ellos y no se revisan (además, comprobar cada una tomaría mucho).
        $conPruebas = User::where('activo', true)
            ->where(fn ($q) => $q->whereIn('role', ['administrador', 'mesero'])->orWhere('email', 'like', '%@fraguareal.com'))
            ->limit(30)
            ->get(['id', 'email', 'password'])
            ->filter(fn (User $u) => $this->usaContrasenaDePrueba((string) $u->password))
            ->pluck('email');

        return $this->item('cuentas_prueba', $conPruebas->isEmpty() ? 'ok' : 'error', 'Sin cuentas activas con contraseña de prueba',
            $conPruebas->isEmpty()
                ? 'Ninguna cuenta activa usa una contraseña de prueba.'
                : 'Cambia la contraseña o desactiva: '.$conPruebas->implode(', ').'.');
    }

    private function usaContrasenaDePrueba(string $hash): bool
    {
        // Una contraseña guardada sin cifrar (no debería existir) cuenta como de prueba.
        if (Hash::info($hash)['algoName'] === 'unknown') {
            return true;
        }

        return collect(self::CONTRASENAS_DE_PRUEBA)->contains(fn ($p) => Hash::check($p, $hash));
    }

    private function pasarela(): array
    {
        $proveedor = (string) config('fragua_real.pasarela.proveedor');
        if ($proveedor !== 'wompi') {
            return $this->item('pasarela', 'aviso', 'Pasarela de pago',
                'Modo manual: los pagos en línea los aprueba el personal. Para la confirmación automática, configura Wompi (README-ENTREGA).');
        }
        if (! app(PasarelaPago::class)->configurada()) {
            return $this->item('pasarela', 'error', 'Pasarela de pago', 'PASARELA_PROVEEDOR=wompi pero faltan llaves WOMPI_*: los pagos quedan en revisión manual.');
        }
        $sandbox = config('services.wompi.ambiente') !== 'produccion';
        $llavePrueba = str_starts_with((string) config('services.wompi.llave_publica'), 'pub_test_');

        return $this->item('pasarela', $sandbox || $llavePrueba ? 'aviso' : 'ok', 'Pasarela de pago',
            $sandbox || $llavePrueba
                ? 'Wompi está en pruebas (sandbox): ningún pago es real. Cambia a las llaves de producción y WOMPI_AMBIENTE=produccion.'
                : 'Wompi en producción.');
    }

    private function item(string $clave, string $nivel, string $titulo, string $detalle): array
    {
        return compact('clave', 'nivel', 'titulo', 'detalle');
    }
}
