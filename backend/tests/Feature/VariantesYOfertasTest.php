<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use App\Models\Variante;
use App\Services\PromocionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mejora (16/10/2026):
 *  - Cada presentación (RF-M04-008) tiene descripción y foto propias: el cliente ve qué trae la
 *    "Familiar" antes de pedirla.
 *  - Atajo «Poner en oferta»: precio de oferta o porcentaje, fecha límite y, si se quiere, una sola
 *    presentación. Por dentro es una promoción automática de producto (RF-M04-033).
 */
class VariantesYOfertasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private Producto $clasica;
    private Producto $gaseosa;
    private Variante $personal;
    private Variante $familiar;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $hamburguesas = Categoria::create(['nombre' => 'Hamburguesas']);
        $bebidas = Categoria::create(['nombre' => 'Bebidas']);
        $this->clasica = Producto::create(['categoria_id' => $hamburguesas->id, 'nombre' => 'Clásica', 'precio_base' => 18000, 'tipo_venta' => 'alimento']);
        $this->gaseosa = Producto::create(['categoria_id' => $bebidas->id, 'nombre' => 'Gaseosa', 'precio_base' => 5000, 'tipo_venta' => 'bebida']);
        $this->personal = Variante::create(['producto_id' => $this->clasica->id, 'nombre' => 'Personal', 'precio' => 18000]);
        $this->familiar = Variante::create(['producto_id' => $this->clasica->id, 'nombre' => 'Familiar', 'precio' => 32000]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
    }

    private function productoPublico(string $nombre): array
    {
        $this->app['auth']->forgetGuards();

        return collect($this->getJson('/api/productos')->assertOk()->json())->firstWhere('nombre', $nombre);
    }

    private function pedidoQr(array $linea)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id, 'lineas' => [$linea]]);
    }

    private function oferta(Producto $producto, array $datos)
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/productos/{$producto->id}/oferta", $datos);
    }

    // ---- Descripción y foto de cada presentación ----

    public function test_la_presentacion_guarda_su_descripcion_y_el_cliente_la_ve(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/variantes/{$this->familiar->id}", [
            'producto_id' => $this->clasica->id, 'nombre' => 'Familiar', 'precio' => 32000, 'descripcion' => 'Doble carne, para 3 o 4 personas',
        ])->assertOk()->assertJsonPath('descripcion', 'Doble carne, para 3 o 4 personas');

        $familiar = collect($this->productoPublico('Clásica')['variantes'])->firstWhere('nombre', 'Familiar');
        $this->assertSame('Doble carne, para 3 o 4 personas', $familiar['descripcion']);
        $this->assertArrayNotHasKey('imagen_path', $familiar);

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/variantes/{$this->familiar->id}", [
            'producto_id' => $this->clasica->id, 'nombre' => 'Familiar', 'precio' => 32000, 'descripcion' => str_repeat('x', 161),
        ])->assertStatus(422)->assertJsonValidationErrors('descripcion');
    }

    public function test_la_presentacion_tiene_su_propia_foto_publica(): void
    {
        Sanctum::actingAs($this->admin);
        $url = $this->post("/api/variantes/{$this->familiar->id}/foto", ['foto' => UploadedFile::fake()->image('familiar.jpg', 1200, 900)], ['Accept' => 'application/json'])
            ->assertOk()->json('imagen_url');

        $this->assertStringStartsWith("/api/variantes/{$this->familiar->id}/foto?v=", $url);
        Storage::disk('local')->assertExists($this->familiar->fresh()->imagen_path);
        $this->assertNull($this->clasica->fresh()->imagen_url, 'La foto del producto no cambia.');
        $this->assertTrue(Auditoria::where('accion', 'cambiar_foto_variante')->where('entidad_id', $this->familiar->id)->exists());

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk();
        $this->assertSame($url, collect($this->productoPublico('Clásica')['variantes'])->firstWhere('nombre', 'Familiar')['imagen_url']);

        // Quitarla: la dirección deja de servir y se borra el archivo.
        $ruta = $this->familiar->fresh()->imagen_path;
        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/variantes/{$this->familiar->id}/foto")->assertOk()->assertJsonPath('imagen_url', null);
        Storage::disk('local')->assertMissing($ruta);
        $this->app['auth']->forgetGuards();
        $this->get("/api/variantes/{$this->familiar->id}/foto")->assertNotFound();
    }

    public function test_solo_el_administrador_cambia_la_foto_de_una_presentacion(): void
    {
        Sanctum::actingAs($this->mesero);
        $this->post("/api/variantes/{$this->familiar->id}/foto", ['foto' => UploadedFile::fake()->image('f.jpg', 400, 400)], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    // ---- «Poner en oferta» ----

    public function test_precio_de_oferta_solo_para_la_familiar(): void
    {
        $this->oferta($this->clasica, ['modo' => 'precio', 'precio_oferta' => 28000, 'variante_id' => $this->familiar->id])
            ->assertCreated()
            ->assertJsonPath('tipo_valor', 'fijo')
            ->assertJsonPath('valor', '4000.00')
            ->assertJsonPath('variante_id', $this->familiar->id)
            ->assertJsonPath('es_oferta', true);

        $promos = $this->productoPublico('Clásica')['promociones_vigentes'];
        $this->assertSame($this->familiar->id, $promos[0]['variante_id']);

        // La Familiar sale a $28.000; la Personal sigue a $18.000.
        $this->pedidoQr(['producto_id' => $this->clasica->id, 'variante_id' => $this->familiar->id, 'cantidad' => 1])
            ->assertCreated()->assertJsonPath('total', '28000.00');
        $this->pedidoQr(['producto_id' => $this->clasica->id, 'variante_id' => $this->personal->id, 'cantidad' => 1])
            ->assertCreated()->assertJsonPath('total', '18000.00');

        $this->assertTrue(Auditoria::where('accion', 'poner_oferta')->exists());
    }

    public function test_porcentaje_para_todas_las_presentaciones_con_fecha_limite(): void
    {
        $hoy = PromocionService::enHoraDelNegocio()->toDateString();
        $this->oferta($this->clasica, ['modo' => 'porcentaje', 'porcentaje' => 10, 'hasta' => $hoy])->assertCreated();

        $this->pedidoQr(['producto_id' => $this->clasica->id, 'variante_id' => $this->personal->id, 'cantidad' => 1])
            ->assertCreated()->assertJsonPath('total', '16200.00');
        $this->pedidoQr(['producto_id' => $this->clasica->id, 'variante_id' => $this->familiar->id, 'cantidad' => 1])
            ->assertCreated()->assertJsonPath('total', '28800.00');

        // Pasada la fecha límite ya no se aplica.
        $this->travelTo(PromocionService::enHoraDelNegocio()->addDay()->setTime(12, 0));
        $this->pedidoQr(['producto_id' => $this->clasica->id, 'variante_id' => $this->personal->id, 'cantidad' => 1])
            ->assertCreated()->assertJsonPath('total', '18000.00');
    }

    public function test_precio_de_oferta_en_un_producto_sin_presentaciones(): void
    {
        $this->oferta($this->gaseosa, ['modo' => 'precio', 'precio_oferta' => 4000])->assertCreated();

        $this->pedidoQr(['producto_id' => $this->gaseosa->id, 'cantidad' => 2])
            ->assertCreated()->assertJsonPath('total', '8000.00');
    }

    public function test_validaciones_de_la_oferta(): void
    {
        // Con presentaciones, un precio de oferta necesita saber a cuál aplica.
        $this->oferta($this->clasica, ['modo' => 'precio', 'precio_oferta' => 15000])
            ->assertStatus(422)->assertJsonValidationErrors('variante_id');
        // No puede ser igual o mayor al precio normal.
        $this->oferta($this->clasica, ['modo' => 'precio', 'precio_oferta' => 32000, 'variante_id' => $this->familiar->id])
            ->assertStatus(422)->assertJsonValidationErrors('precio_oferta');
        // La presentación tiene que ser de ese producto.
        $otra = Variante::create(['producto_id' => $this->gaseosa->id, 'nombre' => 'Litro', 'precio' => 8000]);
        $this->oferta($this->clasica, ['modo' => 'porcentaje', 'porcentaje' => 10, 'variante_id' => $otra->id])
            ->assertStatus(422)->assertJsonValidationErrors('variante_id');
        $this->oferta($this->clasica, ['modo' => 'porcentaje', 'porcentaje' => 100])
            ->assertStatus(422)->assertJsonValidationErrors('porcentaje');
        $this->oferta($this->clasica, ['modo' => 'porcentaje', 'porcentaje' => 10, 'hasta' => '2020-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('hasta');

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/productos/{$this->clasica->id}/oferta", ['modo' => 'porcentaje', 'porcentaje' => 10])->assertForbidden();
        $this->assertSame(0, Promocion::count());
    }

    public function test_una_oferta_nueva_reemplaza_la_anterior_y_se_puede_quitar(): void
    {
        $primera = $this->oferta($this->clasica, ['modo' => 'porcentaje', 'porcentaje' => 10])->json('id');
        $segunda = $this->oferta($this->clasica, ['modo' => 'precio', 'precio_oferta' => 30000, 'variante_id' => $this->familiar->id])->json('id');

        $this->assertFalse(Promocion::find($primera)->activo);
        $this->assertTrue(Promocion::find($segunda)->activo);

        // El Catálogo (personal) ve la oferta del producto; el público no recibe ese dato.
        Sanctum::actingAs($this->admin);
        $catalogo = collect($this->getJson('/api/productos')->json())->firstWhere('nombre', 'Clásica');
        $this->assertSame($segunda, $catalogo['oferta']['promocion_id']);
        $this->assertSame('Familiar', $catalogo['oferta']['variante']);
        $this->assertTrue($catalogo['oferta']['vigente']);
        $this->assertArrayNotHasKey('oferta', $this->productoPublico('Clásica'));

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/productos/{$this->clasica->id}/oferta")->assertOk()->assertJsonPath('quitadas', 1);
        $this->assertFalse(Promocion::find($segunda)->activo);
        $this->assertSame([], $this->productoPublico('Clásica')['promociones_vigentes']);
        $this->assertTrue(Auditoria::where('accion', 'quitar_oferta')->where('entidad_id', $this->clasica->id)->exists());
    }

    public function test_cambiar_el_producto_de_una_oferta_en_promociones_la_vuelve_de_todo_el_producto(): void
    {
        $id = $this->oferta($this->clasica, ['modo' => 'porcentaje', 'porcentaje' => 10, 'variante_id' => $this->familiar->id])->json('id');

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/promociones/{$id}", [
            'nombre' => 'Oferta movida', 'tipo' => 'automatica', 'alcance' => 'producto', 'tipo_valor' => 'porcentaje', 'valor' => 10,
            'productos' => [$this->gaseosa->id], 'activo' => true,
        ])->assertOk()->assertJsonPath('variante_id', null);
    }
}
