<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Endurecimiento de seguridad (13/10/2026, antes de la prueba del día completo): cabeceras de la API,
 * orígenes permitidos (CORS) y la revisión antes de producción. La frescura de los eventos de Wompi
 * se prueba en PasarelaWompiTest.
 */
class EndurecimientoTest extends TestCase
{
    use RefreshDatabase;

    private function produccionBien(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.url' => 'https://fraguareal.co',
            'fragua_real.frontend_url' => 'https://fraguareal.co',
            'mail.default' => 'smtp',
            'queue.default' => 'database',
            'logging.default' => 'single',
            'logging.channels.single.level' => 'warning',
            'cors.allowed_origins' => ['https://fraguareal.co'],
        ]);
    }

    public function test_la_api_responde_con_cabeceras_de_seguridad(): void
    {
        $respuesta = $this->getJson('/api/categorias')->assertOk();

        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $respuesta->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $respuesta->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("default-src 'none'", $respuesta->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $respuesta->headers->get('Cache-Control'));
        $this->assertFalse($respuesta->headers->has('X-Powered-By'));

        // También en los errores (una ruta protegida sin sesión).
        $this->getJson('/api/pedidos')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_cors_solo_deja_el_sitio_propio(): void
    {
        $propio = rtrim((string) config('fragua_real.frontend_url'), '/');

        $this->call('OPTIONS', '/api/categorias', [], [], [], [
            'HTTP_ORIGIN' => $propio, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', $propio);

        $ajeno = $this->call('OPTIONS', '/api/categorias', [], [], [], [
            'HTTP_ORIGIN' => 'https://sitio-ajeno.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNotSame('https://sitio-ajeno.example', $ajeno->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $ajeno->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_la_revision_marca_lo_grave_y_queda_lista_cuando_todo_esta_bien(): void
    {
        $admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@fraguareal.com', 'password' => bcrypt('password'), 'activo' => true]);
        Sanctum::actingAs($admin);

        // Como viene en desarrollo: depuración prendida y la cuenta sembrada con "password".
        config(['app.debug' => true, 'mail.default' => 'log']);
        $items = collect($this->getJson('/api/sistema/revision-produccion')->assertOk()->assertJsonPath('listo', false)->json('items'))->keyBy('clave');
        $this->assertSame('error', $items['debug']['nivel']);
        $this->assertSame('error', $items['correo']['nivel']);
        $this->assertSame('error', $items['cuentas_prueba']['nivel']);
        $this->assertStringContainsString('admin@fraguareal.com', $items['cuentas_prueba']['detalle']);
        $this->artisan('fragua:revisar-produccion')->assertExitCode(1);

        // Corregido: contraseña propia y configuración de producción.
        $admin->forceFill(['password' => bcrypt('Una-Clave-Larga-2026')])->save();
        $this->produccionBien();
        $respuesta = $this->getJson('/api/sistema/revision-produccion')->assertOk()->assertJsonPath('listo', true);
        $this->assertSame('aviso', collect($respuesta->json('items'))->firstWhere('clave', 'pasarela')['nivel'], 'modo manual: aviso, no error');
        $this->assertStringNotContainsString('prv_', json_encode($respuesta->json()), 'nunca muestra llaves');
        $this->artisan('fragua:revisar-produccion')->assertExitCode(0);
    }

    // CORREGIDO (16/10/2026): con LOG_CHANNEL=stack y LOG_STACK=daily, la revisión leía el nivel del
    // «stack» (que no tiene) y avisaba «LOG_LEVEL=debug» aunque el .env dijera warning.
    public function test_el_nivel_de_log_se_lee_de_los_canales_del_stack(): void
    {
        $admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'jefe@local.co', 'password' => bcrypt('Una-Clave-Larga-2026'), 'activo' => true]);
        Sanctum::actingAs($admin);
        $this->produccionBien();
        $log = fn () => collect($this->getJson('/api/sistema/revision-produccion')->json('items'))->firstWhere('clave', 'log');

        config([
            'logging.default' => 'stack',
            'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['daily']],
            'logging.channels.daily.level' => 'warning',
        ]);
        $this->assertSame('ok', $log()['nivel']);
        $this->assertSame('LOG_LEVEL=warning (canal daily).', $log()['detalle']);

        config(['logging.channels.daily.level' => 'debug']);
        $this->assertSame('aviso', $log()['nivel']);
        $this->assertStringContainsString('canal daily', $log()['detalle']);
    }

    public function test_wompi_en_pruebas_es_un_aviso_y_sin_llaves_un_error(): void
    {
        $admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'jefe@local.co', 'password' => bcrypt('Una-Clave-Larga-2026'), 'activo' => true]);
        Sanctum::actingAs($admin);
        $this->produccionBien();

        config(['fragua_real.pasarela.proveedor' => 'wompi', 'services.wompi' => ['ambiente' => 'sandbox', 'llave_publica' => null]]);
        $this->assertSame('error', collect($this->getJson('/api/sistema/revision-produccion')->json('items'))->firstWhere('clave', 'pasarela')['nivel']);

        config(['services.wompi' => [
            'ambiente' => 'sandbox', 'llave_publica' => 'pub_test_x', 'llave_privada' => 'prv_test_x',
            'secreto_eventos' => 'e', 'secreto_integridad' => 'i', 'url_checkout' => 'https://checkout.wompi.co/p/',
        ]]);
        $item = collect($this->getJson('/api/sistema/revision-produccion')->json('items'))->firstWhere('clave', 'pasarela');
        $this->assertSame('aviso', $item['nivel']);
        $this->assertStringContainsString('ningún pago es real', $item['detalle']);
    }

    public function test_solo_el_administrador_ve_la_revision(): void
    {
        $mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario', 'email' => 'm@local.co', 'password' => 'Una-Clave-Larga-2026', 'activo' => true]);
        Sanctum::actingAs($mesero);

        $this->getJson('/api/sistema/revision-produccion')->assertForbidden();
    }
}
