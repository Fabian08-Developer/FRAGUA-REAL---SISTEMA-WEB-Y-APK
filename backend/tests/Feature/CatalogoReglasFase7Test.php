<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 7, tarea 1 (13/10/2026): reglas del catálogo que la revisión de los 579 requisitos encontró a
 * medias.
 *  - RF-M04-009, RN-M04-04: un producto con presentaciones se pide con una de ellas y solo se ofrece
 *    si al menos una está activa y no agotada.
 *  - RF-M04-013: un combo reúne dos o más productos.
 */
class CatalogoReglasFase7Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Categoria $categoria;
    private Producto $clasica;
    private Producto $papas;
    private Variante $personal;
    private Variante $familiar;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        // Precio base menor que cualquier presentación: es justo el hueco que se cierra.
        $this->clasica = Producto::create(['categoria_id' => $this->categoria->id, 'nombre' => 'Clásica', 'precio_base' => 10000, 'tipo_venta' => 'alimento']);
        $this->personal = Variante::create(['producto_id' => $this->clasica->id, 'nombre' => 'Personal', 'precio' => 18000, 'activa' => true]);
        $this->familiar = Variante::create(['producto_id' => $this->clasica->id, 'nombre' => 'Familiar', 'precio' => 32000, 'activa' => true]);
        $this->papas = Producto::create(['categoria_id' => $this->categoria->id, 'nombre' => 'Papas', 'precio_base' => 6000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
    }

    private function pedidoQr(array $linea)
    {
        return $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id, 'lineas' => [$linea]]);
    }

    public function test_un_producto_con_presentaciones_se_pide_con_una_de_ellas(): void
    {
        $this->pedidoQr(['producto_id' => $this->clasica->id, 'cantidad' => 1])
            ->assertStatus(422)->assertJsonPath('message', 'Elige la presentación de "Clásica".');
        $this->postJson('/api/pedidos/cotizacion', ['tipo' => 'local', 'lineas' => [['producto_id' => $this->clasica->id, 'cantidad' => 1]]])
            ->assertStatus(422);
        $this->assertSame(0, Pedido::count());

        $this->pedidoQr(['producto_id' => $this->clasica->id, 'variante_id' => $this->personal->id, 'cantidad' => 1])
            ->assertCreated()->assertJsonPath('total', fn ($t) => (float) $t === 18000.0);

        // Un producto sin presentaciones sigue pidiéndose a su precio base.
        $this->pedidoQr(['producto_id' => $this->papas->id, 'cantidad' => 1])->assertCreated();
    }

    public function test_sin_presentaciones_vendibles_el_producto_no_se_ofrece_ni_su_combo(): void
    {
        $combo = Combo::create(['nombre' => 'Combo Clásico', 'precio' => 22000, 'tipo_venta' => 'alimento', 'activo' => true]);
        $combo->productos()->attach($this->clasica->id, ['cantidad' => 1]);
        $combo->productos()->attach($this->papas->id, ['cantidad' => 1]);

        // Una agotada y la otra apagada: no queda ninguna para vender.
        $this->personal->update(['disponible' => false]);
        $this->familiar->update(['activa' => false]);

        $nombres = collect($this->getJson('/api/productos')->assertOk()->json())->pluck('nombre')->all();
        $this->assertSame(['Papas'], $nombres);
        $this->assertSame([], $this->getJson('/api/combos')->assertOk()->json());
        $this->pedidoQr(['combo_id' => $combo->id, 'cantidad' => 1])->assertStatus(422);
        $this->pedidoQr(['producto_id' => $this->clasica->id, 'cantidad' => 1])->assertStatus(422);

        // El personal sigue viéndolo, para volver a habilitar la presentación.
        Sanctum::actingAs($this->admin);
        $this->assertContains('Clásica', collect($this->getJson('/api/productos')->json())->pluck('nombre')->all());

        // Con una presentación de nuevo disponible, vuelven el producto y el combo.
        $this->personal->update(['disponible' => true]);
        $this->app['auth']->forgetGuards();
        $this->assertContains('Clásica', collect($this->getJson('/api/productos')->json())->pluck('nombre')->all());
        $this->assertCount(1, $this->getJson('/api/combos')->json());
    }

    public function test_un_combo_lleva_dos_o_mas_productos(): void
    {
        Sanctum::actingAs($this->admin);
        $base = ['nombre' => 'Combo prueba', 'precio' => 15000, 'tipo_venta' => 'alimento'];

        $this->postJson('/api/combos', $base + ['productos' => [['producto_id' => $this->papas->id, 'cantidad' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors(['productos']);
        $this->postJson('/api/combos', $base + ['productos' => [
            ['producto_id' => $this->papas->id, 'cantidad' => 1], ['producto_id' => $this->papas->id, 'cantidad' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors(['productos.1.producto_id']);

        // Dos del mismo producto, o dos productos distintos: sí.
        $this->postJson('/api/combos', $base + ['productos' => [['producto_id' => $this->papas->id, 'cantidad' => 2]]])->assertCreated();
        $this->postJson('/api/combos', ['nombre' => 'Combo dúo'] + $base + ['productos' => [
            ['producto_id' => $this->papas->id, 'cantidad' => 1], ['producto_id' => $this->clasica->id, 'cantidad' => 1],
        ]])->assertCreated();
    }
}
