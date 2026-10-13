<?php

namespace Tests\Feature;

use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\PedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1, tarea 3 del plan de implementación: cuenta acumulada de la mesa y cobro por mesa.
 * RF-M02-L-018/019/020/021, RN-PED-13/14/30, HU-M02-006 · RF-M06-003/011 · M05 (visita de la mesa).
 */
class CuentaMesaTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private Producto $hamburguesa;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        CajaTurno::create([
            'fecha' => now('America/Bogota')->toDateString(), 'base_inicial' => 0, 'efectivo_esperado' => 0,
            'abierto_por' => $this->admin->id, 'abierto_at' => now(), 'estado' => 'abierta',
        ]);
    }

    private function pedido(int $cantidad, string $origen = 'mesero'): Pedido
    {
        return app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => $origen, 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => $cantidad]],
        ]);
    }

    private function preparando(Pedido $pedido): void
    {
        $servicio = app(PedidoService::class);
        $servicio->confirmar($pedido, $this->mesero);
        $servicio->marcarPreparando($pedido->fresh());
    }

    public function test_RF_M02_L_019_la_cuenta_suma_los_pedidos_de_la_visita_sin_cancelados(): void
    {
        $this->pedido(1);
        $this->pedido(2, 'qr');
        app(PedidoService::class)->cancelar($this->pedido(5), $this->mesero, 'Se equivocó');

        Sanctum::actingAs($this->mesero);
        $this->getJson("/api/mesas/{$this->mesa->id}/cuenta")
            ->assertOk()
            ->assertJsonPath('abierta', true)
            ->assertJsonCount(2, 'pedidos')
            ->assertJsonPath('total', 60000)
            ->assertJsonPath('saldo', 60000)
            ->assertJsonPath('pedidos_activos', 2);
    }

    public function test_la_cuenta_no_mezcla_pedidos_de_una_visita_anterior(): void
    {
        $anterior = $this->pedido(1);
        $this->preparando($anterior);
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$anterior->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 20000])->assertOk();
        $this->assertSame('libre', $this->mesa->fresh()->estado, 'Pagado y finalizado: la mesa queda libre.');

        $this->travel(1)->minutes();
        $this->pedido(3);

        $this->getJson("/api/mesas/{$this->mesa->id}/cuenta")
            ->assertJsonCount(1, 'pedidos')
            ->assertJsonPath('total', 60000);
    }

    public function test_RF_M06_011_cobrar_la_mesa_reparte_el_pago_y_finaliza_lo_que_ya_cumple(): void
    {
        $primero = $this->pedido(1);
        $segundo = $this->pedido(2);
        $this->preparando($primero);
        $this->preparando($segundo);

        Sanctum::actingAs($this->mesero);
        // Pago mixto: una parte en efectivo y el resto con tarjeta.
        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 30000])
            ->assertOk()->assertJsonPath('saldo', 30000);
        $this->assertSame('finalizado', $primero->fresh()->estado, 'El primer pedido quedó pagado completo.');
        $this->assertEquals(10000, $segundo->fresh()->totalPagado());

        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'tarjeta', 'monto' => 30000])
            ->assertOk()->assertJsonPath('saldo', 0)->assertJsonPath('abierta', false);

        $this->assertSame('finalizado', $segundo->fresh()->estado);
        $this->assertSame('libre', $this->mesa->fresh()->estado);
        $this->assertSame(3, Pago::count());
    }

    public function test_no_se_cobra_mas_de_lo_que_debe_la_mesa(): void
    {
        $this->pedido(1);
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 25000])->assertStatus(422);
        $this->assertSame(0, Pago::count());
    }

    public function test_un_pedido_pagado_antes_de_preparar_queda_confirmado_y_la_mesa_sigue_ocupada(): void
    {
        $pedido = $this->pedido(1);
        app(PedidoService::class)->confirmar($pedido, $this->mesero);
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 20000])
            ->assertOk()->assertJsonPath('saldo', 0)->assertJsonPath('abierta', true);

        $this->assertSame('confirmado', $pedido->fresh()->estado, 'RN-PED-32: pagar no salta la preparación.');
    }

    public function test_solo_el_mesero_cobra_la_mesa_y_sin_caja_abierta_no_se_cobra(): void
    {
        $this->pedido(1);

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/mesas/{$this->mesa->id}/cuenta")->assertOk();
        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 20000])->assertForbidden();

        CajaTurno::query()->update(['estado' => 'cerrada']);
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 20000])->assertStatus(422);
        $this->assertSame(0, Pago::count());
    }

    public function test_una_mesa_libre_no_tiene_cuenta(): void
    {
        Sanctum::actingAs($this->mesero);

        $this->getJson("/api/mesas/{$this->mesa->id}/cuenta")
            ->assertOk()->assertJsonPath('abierta', false)->assertJsonCount(0, 'pedidos')->assertJsonPath('total', 0);
        $this->postJson("/api/mesas/{$this->mesa->id}/cobrar", ['metodo' => 'efectivo', 'monto' => 1000])->assertStatus(422);
    }

    public function test_abrir_y_liberar_marcan_el_inicio_y_el_fin_de_la_visita(): void
    {
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/mesas/{$this->mesa->id}/abrir")->assertOk();
        $this->assertNotNull($this->mesa->fresh()->ocupada_desde);

        $this->postJson("/api/mesas/{$this->mesa->id}/liberar")->assertOk();
        $this->assertNull($this->mesa->fresh()->ocupada_desde);
    }

    public function test_RF_M02_L_020_los_pedidos_se_filtran_por_mesa(): void
    {
        $this->pedido(1);
        $otra = Mesa::create(['nombre' => 'Mesa 2', 'qr_code' => 'qr-2', 'estado' => 'libre']);
        app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $otra->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ]);
        Sanctum::actingAs($this->mesero);

        $this->getJson("/api/pedidos?mesa_id={$this->mesa->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_el_listado_de_mesas_muestra_total_y_saldo_de_cada_mesa_ocupada(): void
    {
        $this->pedido(2);
        Sanctum::actingAs($this->mesero);

        $mesa = collect($this->getJson('/api/mesas')->assertOk()->json())->firstWhere('id', $this->mesa->id);

        $this->assertEquals(40000, $mesa['cuenta_total']);
        $this->assertEquals(40000, $mesa['cuenta_saldo']);
        $this->assertSame(1, $mesa['cuenta_pedidos']);
    }
}
