<?php

namespace Tests\Feature;

use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\PagoService;
use App\Services\PedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 0, tarea 1 del plan de implementación: un pedido solo pasa a FINALIZADO desde
 * PREPARANDO, con el pago completo y, en domicilio y recogida, con la entrega o la
 * recogida confirmada por el Mesero.
 *
 * RN-PED-32, RF-M02-T-016, RN-PED-23 (M02) · RF-M07-025/026, RN-M07-06,
 * RF-M07-R-013/R-014, RN-M07-R-04 (M07) · RF-M06-004, RN-M06-06, RN-M06-05 (M06).
 */
class FinalizacionPedidoTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private User $cliente;
    private Producto $producto;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->cliente = User::create(['role' => 'cliente', 'nombre' => 'Cliente', 'email' => 'cliente@test.co', 'telefono' => '3001234567', 'password' => 'secreto123', 'activo' => true]);

        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => 'Clásica',
            'precio_base' => 20000,
            'tipo_venta' => 'alimento',
        ]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-mesa-1']);

        CajaTurno::create([
            'fecha' => now('America/Bogota')->toDateString(),
            'base_inicial' => 50000,
            'efectivo_esperado' => 50000,
            'abierto_por' => $this->admin->id,
            'abierto_at' => now(),
            'estado' => 'abierta',
        ]);
    }

    // ---- Ayudas ----

    private function crearPedido(string $tipo, array $extra = []): Pedido
    {
        $base = ['tipo' => $tipo, 'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]]];
        $base += match ($tipo) {
            'local' => ['origen' => 'mesero', 'mesa_id' => $this->mesa->id],
            'domicilio' => ['origen' => 'cliente_web', 'cliente_id' => $this->cliente->id, 'direccion_entrega' => 'Calle 1 # 2-3', 'barrio' => 'Centro', 'referencia' => 'Frente al parque', 'valor_domicilio' => 2000],
            'recogida' => ['origen' => 'cliente_web', 'cliente_id' => $this->cliente->id],
        };

        return app(PedidoService::class)->crear(array_merge($base, $extra));
    }

    private function llevarAPreparando(Pedido $pedido): Pedido
    {
        $servicio = app(PedidoService::class);
        $servicio->confirmar($pedido, $this->mesero);
        $servicio->marcarPreparando($pedido->fresh());

        return $pedido->fresh();
    }

    private function cobrarTodo(Pedido $pedido)
    {
        Sanctum::actingAs($this->mesero);

        return $this->postJson("/api/pedidos/{$pedido->id}/cobrar", [
            'metodo' => 'efectivo',
            'monto' => (float) $pedido->fresh()->total,
        ]);
    }

    // ---- El error que motivó la tarea (B1 del plan) ----

    public function test_RN_M06_05_pago_en_linea_aprobado_no_finaliza_un_pedido_pendiente(): void
    {
        $pedido = $this->crearPedido('recogida');
        $pagos = app(PagoService::class);
        $pago = $pagos->registrarPagoEnLinea($pedido, ['pasarela' => 'nequi', 'monto' => (float) $pedido->total]);

        $pagos->confirmarPagoEnLinea($pago);

        $this->assertSame('pendiente', $pedido->fresh()->estado, 'El pago aprobado no reemplaza la confirmación del Mesero.');
    }

    // ---- Local ----

    public function test_RN_PED_32_cobro_completo_de_un_local_confirmado_no_lo_finaliza(): void
    {
        $pedido = $this->crearPedido('local');
        app(PedidoService::class)->confirmar($pedido, $this->mesero);

        $this->cobrarTodo($pedido)->assertOk();

        $this->assertSame('confirmado', $pedido->fresh()->estado);
    }

    public function test_RN_PED_23_local_en_preparando_finaliza_al_completar_el_pago_y_libera_la_mesa(): void
    {
        $pedido = $this->llevarAPreparando($this->crearPedido('local'));

        $this->cobrarTodo($pedido)->assertOk()->assertJsonPath('estado', 'finalizado');

        $this->assertSame('finalizado', $pedido->fresh()->estado);
        $this->assertSame($this->mesero->id, $pedido->fresh()->finalizado_por);
        $this->assertSame('libre', $this->mesa->fresh()->estado);
    }

    public function test_RF_M02_T_016_el_mesero_finaliza_a_mano_un_local_pagado_antes_de_preparar(): void
    {
        $pedido = $this->crearPedido('local');
        app(PedidoService::class)->confirmar($pedido, $this->mesero);
        $this->cobrarTodo($pedido)->assertOk();
        app(PedidoService::class)->marcarPreparando($pedido->fresh());

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/finalizar")->assertOk()->assertJsonPath('estado', 'finalizado');
    }

    public function test_RF_M02_T_016_finalizar_a_mano_sin_pago_completo_se_rechaza(): void
    {
        $pedido = $this->llevarAPreparando($this->crearPedido('local'));

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/finalizar")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'El pedido no puede finalizarse: falta completar el pago.']);

        $this->assertSame('preparando', $pedido->fresh()->estado);
    }

    // ---- Domicilio ----

    public function test_RF_M06_004_el_cobro_contra_entrega_exige_la_entrega_confirmada(): void
    {
        $pedido = $this->llevarAPreparando($this->crearPedido('domicilio'));

        $this->cobrarTodo($pedido)->assertStatus(422);
        $this->assertSame(0.0, $pedido->fresh()->totalPagado());
    }

    public function test_RN_M07_06_domicilio_contra_entrega_finaliza_con_entrega_y_cobro(): void
    {
        $pedido = $this->llevarAPreparando($this->crearPedido('domicilio'));

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/entregar")
            ->assertOk()
            ->assertJsonPath('estado', 'preparando');
        $this->assertNotNull($pedido->fresh()->entregado_at);
        $this->assertSame($this->mesero->id, $pedido->fresh()->entregado_por);

        $this->cobrarTodo($pedido)->assertOk()->assertJsonPath('estado', 'finalizado');
    }

    public function test_RN_M07_06_domicilio_pagado_en_linea_finaliza_al_confirmar_la_entrega(): void
    {
        $pedido = $this->crearPedido('domicilio');
        $pagos = app(PagoService::class);
        $pagos->confirmarPagoEnLinea($pagos->registrarPagoEnLinea($pedido, ['pasarela' => 'bancolombia', 'monto' => (float) $pedido->total]));
        $pedido = $this->llevarAPreparando($pedido->fresh());
        $this->assertSame('preparando', $pedido->estado);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/entregar")->assertOk()->assertJsonPath('estado', 'finalizado');
    }

    public function test_RF_M07_025_no_se_confirma_la_entrega_de_un_pedido_que_no_esta_preparando(): void
    {
        $pedido = $this->crearPedido('domicilio');

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/entregar")->assertStatus(422);
        $this->assertNull($pedido->fresh()->entregado_at);
    }

    // ---- Recogida ----

    public function test_RN_M07_R_04_recogida_finaliza_cuando_hay_pago_y_recogida_en_cualquier_orden(): void
    {
        $pedido = $this->llevarAPreparando($this->crearPedido('recogida'));

        $this->cobrarTodo($pedido)->assertOk()->assertJsonPath('estado', 'preparando');

        $this->postJson("/api/pedidos/{$pedido->id}/recoger")->assertOk()->assertJsonPath('estado', 'finalizado');
        $this->assertSame($this->mesero->id, $pedido->fresh()->recogido_por);
    }

    public function test_RF_M07_R_013_confirmar_recogida_no_aplica_a_un_domicilio(): void
    {
        $pedido = $this->llevarAPreparando($this->crearPedido('domicilio'));

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/recoger")->assertStatus(422);
    }

    // ---- Quién puede hacerlo ----

    public function test_RF_M07_025_solo_el_mesero_confirma_entregas(): void
    {
        $pedido = $this->llevarAPreparando($this->crearPedido('domicilio'));

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pedidos/{$pedido->id}/entregar")->assertForbidden();

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/pedidos/{$pedido->id}/entregar")->assertForbidden();
    }

    public function test_un_pedido_finalizado_ya_no_acepta_otra_finalizacion(): void
    {
        $pedido = $this->llevarAPreparando($this->crearPedido('local'));
        $this->cobrarTodo($pedido)->assertOk();

        $this->postJson("/api/pedidos/{$pedido->id}/finalizar")->assertStatus(422);
        $this->assertSame(1, Pago::where('pedido_id', $pedido->id)->count());
    }
}
