<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\PedidoModificacion;
use App\Models\Producto;
use App\Models\User;
use App\Services\PedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1, tarea 1 del plan de implementación: editar un pedido PENDIENTE y modificar uno
 * CONFIRMADO solo en lo no preparado, con historial.
 *
 * RN-PED-07/08/09/10/11/12 · RF-M02-L-004/005/007/012/014/015/016/017 · HU-M02-002/004/005/020
 * RF-M07-009/021/023/024/028 · RF-M06-011.
 */
class EdicionPedidoTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private User $cliente;
    private Producto $hamburguesa;
    private Producto $gaseosa;
    private Extra $tocineta;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero Uno', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->cliente = User::create(['role' => 'cliente', 'nombre' => 'Cliente', 'email' => 'cliente@test.co', 'password' => 'secreto123', 'activo' => true]);

        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->gaseosa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Gaseosa', 'precio_base' => 4000, 'tipo_venta' => 'bebida']);
        $this->tocineta = Extra::create(['nombre' => 'Tocineta', 'precio' => 3000, 'alcance' => 'general', 'activo' => true]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
    }

    private function pedidoLocal(): Pedido
    {
        return app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 2]],
        ]);
    }

    private function confirmado(): Pedido
    {
        $pedido = $this->pedidoLocal();
        app(PedidoService::class)->confirmar($pedido, $this->mesero);

        return $pedido->fresh('detalles');
    }

    // ---- PENDIENTE: agregar, cambiar y retirar (HU-M02-002) ----

    public function test_RF_M02_L_003_agregar_una_linea_recalcula_el_total(): void
    {
        $pedido = $this->pedidoLocal();
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/pedidos/{$pedido->id}/detalles", [
            'producto_id' => $this->gaseosa->id, 'cantidad' => 3, 'observaciones' => 'Bien fría',
        ])->assertOk()->assertJsonCount(2, 'detalles');

        // 2 × 20.000 + 3 × 4.000
        $this->assertEquals(52000, $pedido->fresh()->total);
    }

    public function test_RF_M02_L_004_cambiar_cantidad_y_observaciones_con_extras_recalcula_el_total(): void
    {
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1, 'extras_ids' => [$this->tocineta->id]]],
        ]);
        $detalle = $pedido->detalles->first();
        Sanctum::actingAs($this->mesero);

        $this->patchJson("/api/pedidos/{$pedido->id}/detalles/{$detalle->id}", ['cantidad' => 3, 'observaciones' => 'Sin cebolla'])
            ->assertOk();

        $this->assertEquals(69000, $pedido->fresh()->total); // 3 × (20.000 + 3.000)
        $this->assertSame('Sin cebolla', $detalle->fresh()->observaciones);
    }

    public function test_RF_M02_L_005_retirar_una_linea_y_no_dejar_el_pedido_vacio(): void
    {
        $pedido = $this->pedidoLocal();
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/detalles", ['producto_id' => $this->gaseosa->id, 'cantidad' => 1])->assertOk();
        $gaseosa = $pedido->fresh()->detalles->firstWhere('producto_id', $this->gaseosa->id);
        $hamburguesa = $pedido->fresh()->detalles->firstWhere('producto_id', $this->hamburguesa->id);

        $this->deleteJson("/api/pedidos/{$pedido->id}/detalles/{$gaseosa->id}")->assertOk();
        $this->assertEquals(40000, $pedido->fresh()->total);

        $this->deleteJson("/api/pedidos/{$pedido->id}/detalles/{$hamburguesa->id}")
            ->assertStatus(422)->assertJsonFragment(['message' => 'Es la única línea del pedido. Si el cliente ya no quiere nada, cancela el pedido con un motivo.']);
    }

    // ---- CONFIRMADO: solo lo no preparado (HU-M02-004/005) ----

    public function test_RN_PED_08_confirmado_admite_agregar_y_cambiar_lo_no_preparado_y_marca_la_comanda(): void
    {
        $pedido = $this->confirmado();
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/pedidos/{$pedido->id}/detalles", ['producto_id' => $this->gaseosa->id, 'cantidad' => 1])->assertOk();
        $this->patchJson("/api/pedidos/{$pedido->id}/detalles/{$pedido->detalles->first()->id}", ['cantidad' => 1])->assertOk();

        $this->assertSame('confirmado', $pedido->fresh()->estado, 'RN-PED-09: se modifica el mismo pedido.');
        $this->assertEquals(24000, $pedido->fresh()->total);
        $this->assertSame(2, PedidoModificacion::where('pedido_id', $pedido->id)->where('afecta_comanda', true)->count());
    }

    public function test_RN_PED_10_una_linea_preparada_no_se_cambia_ni_se_retira(): void
    {
        $pedido = $this->confirmado();
        $detalle = $pedido->detalles->first();
        app(PedidoService::class)->marcarLineaPreparada($detalle);
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/detalles", ['producto_id' => $this->gaseosa->id, 'cantidad' => 1])->assertOk();

        $this->patchJson("/api/pedidos/{$pedido->id}/detalles/{$detalle->id}", ['cantidad' => 1])->assertStatus(422);
        $this->deleteJson("/api/pedidos/{$pedido->id}/detalles/{$detalle->id}")->assertStatus(422);

        $this->assertSame(2, $detalle->fresh()->cantidad);
    }

    public function test_RN_PED_11_preparando_finalizado_y_cancelado_no_se_modifican(): void
    {
        $servicio = app(PedidoService::class);
        $preparando = $this->confirmado();
        $servicio->marcarPreparando($preparando);
        $cancelado = $this->pedidoLocal();
        $servicio->cancelar($cancelado, $this->mesero, 'Se fue');
        $finalizado = $this->pedidoLocal();
        $finalizado->forceFill(['estado' => 'finalizado'])->save();

        Sanctum::actingAs($this->mesero);
        foreach ([$preparando, $cancelado, $finalizado] as $pedido) {
            $this->postJson("/api/pedidos/{$pedido->id}/detalles", ['producto_id' => $this->gaseosa->id, 'cantidad' => 1])
                ->assertStatus(422);
        }
        $this->assertSame(0, PedidoModificacion::count());
    }

    // ---- Disponibilidad, pagos y permisos ----

    public function test_no_se_agrega_ni_se_aumenta_un_producto_agotado(): void
    {
        $pedido = $this->pedidoLocal();
        $this->hamburguesa->update(['disponible' => false]);
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/pedidos/{$pedido->id}/detalles", ['producto_id' => $this->hamburguesa->id, 'cantidad' => 1])->assertStatus(422);
        $this->patchJson("/api/pedidos/{$pedido->id}/detalles/{$pedido->detalles->first()->id}", ['cantidad' => 3])->assertStatus(422);
        // Bajar la cantidad sí se puede: no vende nada nuevo.
        $this->patchJson("/api/pedidos/{$pedido->id}/detalles/{$pedido->detalles->first()->id}", ['cantidad' => 1])->assertOk();
    }

    public function test_RF_M06_011_el_total_no_puede_quedar_por_debajo_de_lo_pagado(): void
    {
        CajaTurno::create([
            'fecha' => now('America/Bogota')->toDateString(), 'base_inicial' => 0, 'efectivo_esperado' => 0,
            'abierto_por' => $this->admin->id, 'abierto_at' => now(), 'estado' => 'abierta',
        ]);
        $pedido = $this->confirmado();
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 40000])->assertOk();

        $this->patchJson("/api/pedidos/{$pedido->id}/detalles/{$pedido->detalles->first()->id}", ['cantidad' => 1])
            ->assertStatus(422);

        $this->assertEquals(40000, $pedido->fresh()->total);
        $this->assertSame(2, $pedido->detalles->first()->fresh()->cantidad, 'El cambio rechazado no deja nada a medias.');
    }

    public function test_el_cliente_no_edita_y_una_linea_de_otro_pedido_da_404(): void
    {
        $pedido = $this->pedidoLocal();
        $otro = $this->pedidoLocal();

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/pedidos/{$pedido->id}/detalles", ['producto_id' => $this->gaseosa->id, 'cantidad' => 1])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/pedidos/{$pedido->id}/detalles/{$otro->detalles->first()->id}")->assertNotFound();
    }

    // ---- Historial (RN-PED-12, HU-M02-020) ----

    public function test_RN_PED_12_el_historial_muestra_quien_que_cuando_y_no_se_puede_borrar(): void
    {
        $pedido = $this->pedidoLocal();
        Sanctum::actingAs($this->mesero);
        $this->travel(1)->minutes();
        $this->postJson("/api/pedidos/{$pedido->id}/detalles", ['producto_id' => $this->gaseosa->id, 'cantidad' => 2])->assertOk();
        $gaseosa = $pedido->fresh()->detalles->firstWhere('producto_id', $this->gaseosa->id);
        $this->travel(1)->minutes();
        $this->deleteJson("/api/pedidos/{$pedido->id}/detalles/{$gaseosa->id}")->assertOk();
        $this->travel(1)->minutes();
        $this->postJson("/api/pedidos/{$pedido->id}/confirmar")->assertOk();

        Sanctum::actingAs($this->admin);
        $eventos = $this->getJson("/api/pedidos/{$pedido->id}/historial")->assertOk()->json('data');

        $this->assertSame(
            // Fase 6, tarea 2: confirmar también manda la comanda a la cocina, y queda en la historia.
            ['Pedido creado (PENDIENTE)', 'Agregó 2 × Gaseosa', 'Retiró 2 × Gaseosa', 'Pedido confirmado', "Comanda {$pedido->id}-1 enviada a la cocina"],
            array_column($eventos, 'descripcion'),
        );
        $this->assertSame('Mesero Uno', $eventos[1]['quien']);
        $this->assertEquals(40000, $eventos[1]['total_anterior']);
        $this->assertEquals(48000, $eventos[1]['total_nuevo']);
        $this->assertSame(2, Auditoria::where('accion', 'modificar_pedido')->count());

        $this->expectException(\LogicException::class);
        PedidoModificacion::first()->delete();
    }

    public function test_el_cliente_no_ve_el_historial_interno(): void
    {
        $pedido = $this->pedidoLocal();
        Sanctum::actingAs($this->cliente);

        $this->getJson("/api/pedidos/{$pedido->id}/historial")->assertForbidden();
    }
}
