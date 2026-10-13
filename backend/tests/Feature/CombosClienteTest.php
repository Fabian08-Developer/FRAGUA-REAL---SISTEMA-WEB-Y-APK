<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revisión por rol (13/10/2026): el Menú web y el QR de mesa no ofrecían combos (solo el Mesero
 * podía venderlos). RF-M04-020 pide el catálogo con combos "para M02 y para el Cliente" y
 * RF-M04-031 los nombra en los canales Local, QR y Domicilio. El frontend ya los muestra; esta
 * prueba fija lo que el Cliente necesita del servidor: la lista pública con lo que trae cada
 * combo, la cotización y el pedido (invitado y QR) con la nota para cocina.
 */
class CombosClienteTest extends TestCase
{
    use RefreshDatabase;

    private Producto $clasica;
    private Producto $papas;
    private Combo $combo;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->clasica = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->papas = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Papas', 'precio_base' => 6000, 'tipo_venta' => 'alimento']);
        $this->combo = Combo::create(['nombre' => 'Combo Clásico', 'precio' => 24000, 'tipo_venta' => 'alimento', 'activo' => true]);
        $this->combo->productos()->attach($this->clasica->id, ['cantidad' => 1]);
        $this->combo->productos()->attach($this->papas->id, ['cantidad' => 2]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 4', 'qr_code' => 'qr-mesa-4']);
    }

    private function lineaCombo(int $cantidad = 1, ?string $nota = null): array
    {
        return array_filter(['combo_id' => $this->combo->id, 'cantidad' => $cantidad, 'observaciones' => $nota], fn ($v) => $v !== null);
    }

    public function test_la_lista_publica_trae_lo_que_incluye_cada_combo(): void
    {
        $combos = $this->getJson('/api/combos')->assertOk()->json();

        $this->assertCount(1, $combos);
        $this->assertSame('Combo Clásico', $combos[0]['nombre']);
        $componentes = collect($combos[0]['productos'])->mapWithKeys(fn ($p) => [$p['nombre'] => $p['pivot']['cantidad']]);
        $this->assertEquals(['Clásica' => 1, 'Papas' => 2], $componentes->all());
    }

    public function test_un_combo_con_un_componente_agotado_no_se_ofrece_al_cliente(): void
    {
        $this->papas->update(['disponible' => false]);

        $this->assertSame([], $this->getJson('/api/combos')->assertOk()->json());
        $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Laura', 'cliente_invitado_telefono' => '3001234567',
            'lineas' => [$this->lineaCombo()],
        ])->assertStatus(422);
        $this->assertSame(0, Pedido::count());
    }

    public function test_el_invitado_pide_un_combo_a_domicilio_con_su_nota(): void
    {
        $lineas = [$this->lineaCombo(2, 'Sin cebolla'), ['producto_id' => $this->papas->id, 'cantidad' => 1]];

        $cotizacion = $this->postJson('/api/pedidos/cotizacion', ['tipo' => 'domicilio', 'lineas' => $lineas])->assertOk();
        $this->assertEquals(54000, $cotizacion->json('subtotal'));
        $this->assertEquals(56000, $cotizacion->json('total'));

        $pedido = $this->postJson('/api/pedidos', [
            'tipo' => 'domicilio', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Laura', 'cliente_invitado_telefono' => '3001234567',
            'direccion_entrega' => 'Calle 5 # 3-20', 'barrio' => 'Centro', 'referencia' => 'Portón negro',
            'lineas' => $lineas,
        ])->assertCreated();

        $this->assertEquals($cotizacion->json('total'), $pedido->json('total'));
        $detalle = Pedido::findOrFail($pedido->json('id'))->detalles()->whereNotNull('combo_id')->firstOrFail();
        $this->assertSame(2, (int) $detalle->cantidad);
        $this->assertEquals(24000, $detalle->precio_unitario_congelado);
        $this->assertSame('Sin cebolla', $detalle->observaciones);

        // El seguimiento del invitado muestra el combo por su nombre.
        $seguimiento = $this->getJson('/api/pedidos/seguimiento/'.$pedido->json('token_publico'))->assertOk();
        $this->assertContains('Combo Clásico', collect($seguimiento->json('detalles'))->pluck('combo.nombre')->filter()->all());
    }

    public function test_desde_el_qr_de_la_mesa_tambien_se_pide_un_combo(): void
    {
        $pedido = $this->postJson('/api/pedidos', [
            'tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id,
            'lineas' => [$this->lineaCombo(1, 'Papas bien doradas')],
        ])->assertCreated();

        $this->assertEquals(24000, $pedido->json('total'));
        $this->assertSame('ocupada', $this->mesa->fresh()->estado);
        $this->assertSame('Papas bien doradas', Pedido::findOrFail($pedido->json('id'))->detalles()->firstOrFail()->observaciones);
    }
}
