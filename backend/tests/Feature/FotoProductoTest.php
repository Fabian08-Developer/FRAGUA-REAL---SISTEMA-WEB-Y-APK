<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 7, tarea 3 (13/10/2026): la foto de cada producto (RF-M04-004, HU-M04-002, RNF-M04-002).
 * El Administrador la sube desde Catálogo; el servidor la reduce y la sirve pública para el Menú,
 * el QR y el Inicio, con caché larga porque la dirección cambia con cada foto.
 */
class FotoProductoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Producto $clasica;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->clasica = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 18000, 'tipo_venta' => 'alimento']);
    }

    private function subir(UploadedFile $foto)
    {
        return $this->post("/api/productos/{$this->clasica->id}/foto", ['foto' => $foto], ['Accept' => 'application/json']);
    }

    public function test_el_administrador_sube_la_foto_y_el_menu_la_ve_reducida(): void
    {
        Sanctum::actingAs($this->admin);
        $url = $this->subir(UploadedFile::fake()->image('hamburguesa.jpg', 1800, 1200))
            ->assertOk()->json('imagen_url');

        $this->assertStringStartsWith("/api/productos/{$this->clasica->id}/foto?v=", $url);
        $ruta = $this->clasica->fresh()->imagen_path;
        Storage::disk('local')->assertExists($ruta);

        // Sin sesión, como el Menú del Cliente.
        $this->app['auth']->forgetGuards();
        $respuesta = $this->get($url)->assertOk();
        $this->assertSame('image/webp', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('public', $respuesta->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-store', $respuesta->headers->get('Cache-Control'));
        [$ancho, $alto] = getimagesizefromstring($respuesta->streamedContent());
        $this->assertSame([900, 600], [$ancho, $alto], 'Se reduce a 900 px por el lado más largo.');

        // El catálogo público trae la dirección, nunca la ruta interna del archivo.
        $producto = collect($this->getJson('/api/productos')->json())->firstWhere('id', $this->clasica->id);
        $this->assertSame($url, $producto['imagen_url']);
        $this->assertArrayNotHasKey('imagen_path', $producto);

        // Las demás respuestas de la API siguen sin caché.
        $this->assertStringContainsString('no-store', $this->getJson('/api/productos')->headers->get('Cache-Control'));
    }

    public function test_cambiar_y_quitar_la_foto_borra_la_anterior_y_queda_en_auditoria(): void
    {
        Sanctum::actingAs($this->admin);
        $primera = $this->subir(UploadedFile::fake()->image('a.png', 400, 400))->assertOk()->json('imagen_url');
        $rutaPrimera = $this->clasica->fresh()->imagen_path;

        $segunda = $this->subir(UploadedFile::fake()->image('b.jpg', 400, 400))->assertOk()->json('imagen_url');
        $this->assertNotSame($primera, $segunda, 'Otra dirección: el navegador no muestra la foto vieja.');
        Storage::disk('local')->assertMissing($rutaPrimera);

        // Editar el producto no pisa la foto con una dirección escrita a mano.
        $this->putJson("/api/productos/{$this->clasica->id}", [
            'categoria_id' => $this->clasica->categoria_id, 'nombre' => 'Clásica', 'precio_base' => 18000,
            'tipo_venta' => 'alimento', 'imagen_url' => 'https://otro-sitio.example/foto.jpg',
        ])->assertOk()->assertJsonPath('imagen_url', $segunda);

        $rutaSegunda = $this->clasica->fresh()->imagen_path;
        $this->deleteJson("/api/productos/{$this->clasica->id}/foto")->assertOk()->assertJsonPath('imagen_url', null);
        Storage::disk('local')->assertMissing($rutaSegunda);
        $this->getJson("/api/productos/{$this->clasica->id}/foto")->assertNotFound();

        $this->assertSame(3, Auditoria::where('accion', 'cambiar_foto_producto')->count());
    }

    public function test_solo_fotos_validas_y_solo_el_administrador(): void
    {
        Sanctum::actingAs($this->admin);
        $this->subir(UploadedFile::fake()->create('menu.pdf', 20, 'application/pdf'))
            ->assertStatus(422)->assertJsonPath('errors.foto.0', 'La foto debe ser JPG, PNG o WebP.');
        $this->subir(UploadedFile::fake()->image('mini.jpg', 120, 120))
            ->assertStatus(422)->assertJsonPath('errors.foto.0', 'La foto debe medir al menos 200 × 200 píxeles.');

        $mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario', 'email' => 'mario@test.co', 'password' => 'secreto123', 'activo' => true]);
        Sanctum::actingAs($mesero);
        $this->subir(UploadedFile::fake()->image('a.jpg', 400, 400))->assertForbidden();
        $this->deleteJson("/api/productos/{$this->clasica->id}/foto")->assertForbidden();

        $this->assertNull($this->clasica->fresh()->imagen_url);
    }
}
