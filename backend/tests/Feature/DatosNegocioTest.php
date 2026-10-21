<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\User;
use App\Services\NegocioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mejora (19/10/2026): el Administrador cambia la dirección, el teléfono y el logo del negocio desde
 * Configuración; el sitio los lee en GET /configuracion y los PDF los toman de NegocioService.
 */
class DatosNegocioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
    }

    public function test_sin_cambios_se_ven_los_datos_de_siempre(): void
    {
        $this->getJson('/api/configuracion')->assertOk()
            ->assertJsonPath('negocio.direccion', config('fragua_real.negocio.direccion'))
            ->assertJsonPath('negocio.telefono', config('fragua_real.negocio.telefono'))
            ->assertJsonPath('negocio.whatsapp_url', 'https://wa.me/57'.preg_replace('/\D+/', '', config('fragua_real.negocio.telefono')))
            ->assertJsonPath('negocio.logo_url', null);
    }

    public function test_el_administrador_cambia_direccion_y_telefono_y_queda_en_auditoria(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/negocio', ['direccion' => 'Calle 10 # 5-20, Centro', 'telefono' => '310 555 1234'])
            ->assertOk()
            ->assertJsonPath('direccion', 'Calle 10 # 5-20, Centro')
            ->assertJsonPath('whatsapp_url', 'https://wa.me/573105551234');

        $this->getJson('/api/configuracion')->assertJsonPath('negocio.telefono', '310 555 1234');
        $this->assertSame('Calle 10 # 5-20, Centro', app(NegocioService::class)->datos()['direccion']);
        $this->assertTrue(Auditoria::where('accion', 'editar_datos_negocio')->exists());

        $this->putJson('/api/negocio', ['direccion' => '', 'telefono' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors(['direccion', 'telefono']);
    }

    public function test_el_logo_se_sube_se_sirve_y_se_quita(): void
    {
        Sanctum::actingAs($this->admin);
        $url = $this->post('/api/negocio/logo', ['logo' => UploadedFile::fake()->image('logo.png', 800, 800)], ['Accept' => 'application/json'])
            ->assertOk()->json('logo_url');
        $this->assertStringStartsWith('/api/negocio/logo?v=', $url);

        $ruta = app(NegocioService::class)->rutaLogo();
        Storage::disk('local')->assertExists($ruta);
        $this->assertSame('image/png', $this->get('/api/negocio/logo')->assertOk()->headers->get('Content-Type'));
        // Los PDF lo reciben listo para incrustar.
        $this->assertStringStartsWith('data:image/png;base64,', app(NegocioService::class)->datos()['logo']);
        if (function_exists('imagecreatefromstring')) {
            [$ancho] = getimagesizefromstring(Storage::disk('local')->get($ruta));
            $this->assertSame(NegocioService::LADO_MAXIMO, $ancho);
        }

        $this->deleteJson('/api/negocio/logo')->assertOk()->assertJsonPath('logo_url', null);
        Storage::disk('local')->assertMissing($ruta);
        $this->get('/api/negocio/logo')->assertNotFound();
        $this->assertEqualsCanonicalizing(['cambiar_logo_negocio', 'quitar_logo_negocio'],
            Auditoria::whereIn('accion', ['cambiar_logo_negocio', 'quitar_logo_negocio'])->pluck('accion')->all());
    }

    public function test_solo_el_administrador_cambia_los_datos_del_negocio(): void
    {
        $this->putJson('/api/negocio', ['direccion' => 'X', 'telefono' => '3105551234'])->assertUnauthorized();

        Sanctum::actingAs(User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]));
        $this->putJson('/api/negocio', ['direccion' => 'X', 'telefono' => '3105551234'])->assertForbidden();
        $this->post('/api/negocio/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson('/api/negocio/logo')->assertForbidden();
    }

    public function test_el_logo_rechaza_archivos_que_no_son_imagen(): void
    {
        Sanctum::actingAs($this->admin);
        $this->post('/api/negocio/logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['logo']);
    }
}
