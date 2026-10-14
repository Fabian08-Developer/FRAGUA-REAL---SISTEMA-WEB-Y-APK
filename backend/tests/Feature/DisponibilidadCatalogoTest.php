<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1, tarea 5 del plan de implementación: agotado y búsqueda desde la pantalla del Mesero.
 * RF-M04-016/017/018/019/020/025, RN-M04-07/08, HU-M04-009/010/013.
 */
class DisponibilidadCatalogoTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $cliente;
    private Categoria $hamburguesas;
    private Categoria $bebidas;
    private Producto $clasica;
    private Variante $doble;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->cliente = User::create(['role' => 'cliente', 'nombre' => 'Ana', 'email' => 'ana@test.co', 'telefono' => '3001234567', 'password' => 'secreto123', 'activo' => true]);
        $this->hamburguesas = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->bebidas = Categoria::create(['nombre' => 'Bebidas']);
        $this->clasica = Producto::create(['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        Producto::create(['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Hamburguesa BBQ', 'precio_base' => 25000, 'tipo_venta' => 'alimento']);
        Producto::create(['categoria_id' => $this->bebidas->id, 'nombre' => 'Limonada', 'precio_base' => 6000, 'tipo_venta' => 'bebida']);
        $this->doble = Variante::create(['producto_id' => $this->clasica->id, 'nombre' => 'Doble', 'precio' => 28000, 'activa' => true]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
    }

    private function pedirDoble()
    {
        return $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->clasica->id, 'variante_id' => $this->doble->id, 'cantidad' => 1]],
        ]);
    }

    public function test_RF_M04_016_el_mesero_marca_una_variante_agotada_y_no_se_puede_pedir(): void
    {
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/variantes/{$this->doble->id}/disponibilidad", ['disponible' => false])
            ->assertOk()->assertJsonPath('disponible', false);

        $this->app['auth']->forgetGuards();
        $this->pedirDoble()->assertStatus(422);

        $registro = Auditoria::where('accion', 'cambiar_disponibilidad')->sole();
        $this->assertSame($this->mesero->id, $registro->user_id);
        $this->assertFalse($registro->detalles['disponible']);
    }

    public function test_RF_M04_017_al_volver_a_disponible_se_puede_pedir_de_nuevo(): void
    {
        $this->doble->update(['disponible' => false]);
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/variantes/{$this->doble->id}/disponibilidad", ['disponible' => true])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->pedirDoble()->assertCreated();
    }

    public function test_el_cliente_no_ve_lo_agotado_y_el_mesero_si_para_poder_reactivarlo(): void
    {
        $this->doble->update(['disponible' => false]);
        Producto::where('nombre', 'Limonada')->update(['disponible' => false]);

        Sanctum::actingAs($this->cliente);
        $publico = collect($this->getJson('/api/productos')->assertOk()->json());
        $this->assertNull($publico->firstWhere('nombre', 'Limonada'));
        // Fase 7, tarea 1 (RN-M04-04): con su única presentación agotada, la Clásica ya no se
        // ofrece (antes salía sin presentaciones y se podía pedir al precio base).
        $this->assertNull($publico->firstWhere('nombre', 'Clásica'));

        Sanctum::actingAs($this->mesero);
        $personal = collect($this->getJson('/api/productos')->json());
        $this->assertNotNull($personal->firstWhere('nombre', 'Limonada'));
        $this->assertCount(1, $personal->firstWhere('nombre', 'Clásica')['variantes']);
    }

    public function test_RF_M04_025_buscar_por_nombre_y_filtrar_por_categoria(): void
    {
        $nombres = fn ($url) => collect($this->getJson($url)->assertOk()->json())->pluck('nombre')->all();

        $this->assertSame(['Hamburguesa BBQ'], $nombres('/api/productos?q=bbq'));
        $this->assertSame(['Clásica', 'Hamburguesa BBQ'], $nombres("/api/productos?categoria_id={$this->hamburguesas->id}"));
        $this->assertSame(['Limonada'], $nombres("/api/productos?categoria_id={$this->bebidas->id}&q=limon"));
    }

    public function test_RN_M04_08_un_pedido_ya_creado_no_cambia_si_luego_se_agota(): void
    {
        $id = $this->pedirDoble()->assertCreated()->json('id');
        $this->doble->update(['disponible' => false]);

        Sanctum::actingAs($this->mesero);
        $this->getJson("/api/pedidos/{$id}")->assertOk()->assertJsonPath('detalles.0.variante_id', $this->doble->id);
        $this->postJson("/api/pedidos/{$id}/confirmar")->assertOk();
    }

    public function test_el_cliente_no_cambia_la_disponibilidad(): void
    {
        Sanctum::actingAs($this->cliente);

        $this->postJson("/api/variantes/{$this->doble->id}/disponibilidad", ['disponible' => false])->assertForbidden();
        $this->postJson("/api/productos/{$this->clasica->id}/disponibilidad", ['disponible' => false])->assertForbidden();
    }

    public function test_una_categoria_desactivada_esconde_sus_productos_al_publico(): void
    {
        $this->bebidas->update(['activo' => false]);

        $nombres = collect($this->getJson('/api/productos')->json())->pluck('nombre')->all();

        $this->assertNotContains('Limonada', $nombres);
    }
}
