<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\PedidoModificacion;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use App\Services\PedidoService;
use App\Services\ReporteService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 2, tarea 1 del plan de implementación: promociones aplicadas al pedido y descuento manual.
 *
 * RF-M04-035/036/037/038/039/041 · RN-M04-18/19/20/21/22/23 · HU-M04-014/015 · CA-M04-014/015.
 */
class PromocionesYDescuentosTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private User $cliente;
    private Categoria $hamburguesas;
    private Categoria $bebidas;
    private Producto $clasica;
    private Producto $doble;
    private Producto $gaseosa;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        // Martes 13/10/2026 a la 1:00 p. m. en Colombia (6:00 p. m. UTC).
        $this->travelTo(CarbonImmutable::parse('2026-10-13 13:00:00', 'America/Bogota'));

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero Uno', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->cliente = User::create(['role' => 'cliente', 'nombre' => 'Cliente', 'email' => 'cliente@test.co', 'password' => 'secreto123', 'telefono' => '3001234567', 'activo' => true]);

        $this->hamburguesas = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->bebidas = Categoria::create(['nombre' => 'Bebidas']);
        $this->clasica = Producto::create(['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->doble = Producto::create(['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Doble', 'precio_base' => 30000, 'tipo_venta' => 'alimento']);
        $this->gaseosa = Producto::create(['categoria_id' => $this->bebidas->id, 'nombre' => 'Gaseosa', 'precio_base' => 5000, 'tipo_venta' => 'bebida']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
    }

    private function promocion(array $datos, array $productos = [], array $categorias = []): Promocion
    {
        $promo = Promocion::create($datos + ['tipo' => 'automatica', 'tipo_valor' => 'porcentaje', 'activo' => true]);
        $promo->productos()->sync($productos);
        $promo->categorias()->sync($categorias);

        return $promo;
    }

    private function pedidoLocal(array $lineas): Pedido
    {
        return app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id, 'lineas' => $lineas,
        ]);
    }

    // ---- Promociones automáticas (RF-M04-035, RN-M04-19) ----

    public function test_RF_M04_035_la_promocion_de_producto_vigente_se_aplica_sola(): void
    {
        $this->promocion(['nombre' => 'Clásica 20 %', 'alcance' => 'producto', 'valor' => 20], [$this->clasica->id]);

        $pedido = $this->pedidoLocal([
            ['producto_id' => $this->clasica->id, 'cantidad' => 2],
            ['producto_id' => $this->gaseosa->id, 'cantidad' => 1],
        ]);

        $this->assertEquals(45000, $pedido->subtotal);
        $this->assertEquals(8000, $pedido->descuento);       // 20 % de 40.000
        $this->assertEquals(37000, $pedido->total);
        $this->assertSame('Clásica 20 %', $pedido->promociones_aplicadas[0]['nombre']);
        $this->assertEquals(8000, $pedido->detalles->firstWhere('producto_id', $this->clasica->id)->descuento);
        $this->assertEquals(0, $pedido->detalles->firstWhere('producto_id', $this->gaseosa->id)->descuento);
    }

    public function test_la_franja_horaria_se_evalua_en_la_hora_de_colombia(): void
    {
        $this->promocion(['nombre' => 'Almuerzo', 'alcance' => 'categoria', 'valor' => 10, 'franja_inicio' => '12:00:00', 'franja_fin' => '15:00:00'], [], [$this->hamburguesas->id]);

        // 8:00 a. m. en Colombia = 1:00 p. m. UTC: con la hora del servidor se habría aplicado.
        $this->travelTo(CarbonImmutable::parse('2026-10-13 08:00:00', 'America/Bogota'));
        $temprano = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 1]]);
        $this->assertEquals(0, $temprano->descuento);

        $this->travelTo(CarbonImmutable::parse('2026-10-13 13:30:00', 'America/Bogota'));
        $almuerzo = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 1]]);
        $this->assertEquals(2000, $almuerzo->descuento);
    }

    public function test_la_promocion_de_un_dia_de_la_semana_solo_aplica_ese_dia(): void
    {
        $this->promocion(['nombre' => 'Jueves de doble', 'alcance' => 'producto', 'tipo_valor' => 'fijo', 'valor' => 5000, 'dias_semana' => [4]], [$this->doble->id]);

        $martes = $this->pedidoLocal([['producto_id' => $this->doble->id, 'cantidad' => 1]]);
        $this->assertEquals(0, $martes->descuento);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00:00', 'America/Bogota')); // jueves
        $jueves = $this->pedidoLocal([['producto_id' => $this->doble->id, 'cantidad' => 2]]);
        $this->assertEquals(10000, $jueves->descuento); // valor fijo por unidad
    }

    public function test_una_promocion_vencida_o_inactiva_no_se_aplica(): void
    {
        $this->promocion(['nombre' => 'Vencida', 'alcance' => 'total', 'valor' => 10, 'vigencia_inicio' => '2026-10-01', 'vigencia_fin' => '2026-10-12']);
        $this->promocion(['nombre' => 'Inactiva', 'alcance' => 'total', 'valor' => 10, 'activo' => false]);
        $this->promocion(['nombre' => 'Solo a mano', 'alcance' => 'total', 'valor' => 10, 'tipo' => 'manual']);

        $pedido = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 1]]);

        $this->assertEquals(0, $pedido->descuento);
        $this->assertNull($pedido->promociones_aplicadas);
    }

    // ---- Varias promociones se combinan (RF-M04-038, RN-M04-20, CA-M04-014) ----

    public function test_RF_M04_038_varias_promociones_vigentes_se_combinan(): void
    {
        $this->promocion(['nombre' => 'Clásica 10 %', 'alcance' => 'producto', 'valor' => 10], [$this->clasica->id]);
        $this->promocion(['nombre' => 'Bebidas $1.000', 'alcance' => 'categoria', 'tipo_valor' => 'fijo', 'valor' => 1000], [], [$this->bebidas->id]);
        $this->promocion(['nombre' => 'Total 5 %', 'alcance' => 'total', 'valor' => 5]);

        $pedido = $this->pedidoLocal([
            ['producto_id' => $this->clasica->id, 'cantidad' => 2], // 40.000 − 4.000 = 36.000
            ['producto_id' => $this->gaseosa->id, 'cantidad' => 2], // 10.000 − 2.000 = 8.000
        ]);

        // Al total: 5 % de 44.000 = 2.200. Descuento total: 4.000 + 2.000 + 2.200.
        $this->assertEquals(8200, $pedido->descuento);
        $this->assertEquals(41800, $pedido->total);
        $this->assertCount(3, $pedido->promociones_aplicadas);
        // Lo repartido entre las líneas suma lo mismo que el descuento del pedido.
        $this->assertEquals(8200, $pedido->detalles->sum(fn ($d) => (float) $d->descuento));
    }

    public function test_el_descuento_nunca_pasa_del_valor_del_producto(): void
    {
        $this->promocion(['nombre' => 'Gaseosa $3.000', 'alcance' => 'producto', 'tipo_valor' => 'fijo', 'valor' => 3000], [$this->gaseosa->id]);
        $this->promocion(['nombre' => 'Bebidas 50 %', 'alcance' => 'categoria', 'valor' => 50], [], [$this->bebidas->id]);

        $pedido = $this->pedidoLocal([['producto_id' => $this->gaseosa->id, 'cantidad' => 1]]);

        $this->assertEquals(5000, $pedido->descuento);
        $this->assertEquals(0, $pedido->total);
    }

    public function test_un_combo_solo_recibe_promociones_al_total_y_los_extras_no_se_rebajan_con_la_del_producto(): void
    {
        $combo = Combo::create(['nombre' => 'Combo Clásico', 'precio' => 22000, 'tipo_venta' => 'alimento', 'activo' => true]);
        $combo->productos()->attach($this->clasica->id, ['cantidad' => 1]);
        $tocineta = Extra::create(['nombre' => 'Tocineta', 'precio' => 3000, 'alcance' => 'general', 'activo' => true]);
        $this->promocion(['nombre' => 'Clásica 50 %', 'alcance' => 'producto', 'valor' => 50], [$this->clasica->id]);

        $pedido = $this->pedidoLocal([
            ['combo_id' => $combo->id, 'cantidad' => 1],
            ['producto_id' => $this->clasica->id, 'cantidad' => 1, 'extras_ids' => [$tocineta->id]],
        ]);

        // Solo la Clásica suelta: 50 % de 20.000; la tocineta (3.000) y el combo se cobran completos.
        $this->assertEquals(10000, $pedido->descuento);
        $this->assertEquals(35000, $pedido->total);
    }

    public function test_el_valor_del_domicilio_no_se_rebaja(): void
    {
        $this->promocion(['nombre' => 'Todo 100 %', 'alcance' => 'total', 'valor' => 100]);
        Sanctum::actingAs($this->cliente);

        $respuesta = $this->postJson('/api/pedidos', [
            'tipo' => 'domicilio', 'origen' => 'cliente_web',
            'direccion_entrega' => 'Calle 1 # 2-3', 'barrio' => 'Centro', 'referencia' => 'Casa azul',
            'lineas' => [['producto_id' => $this->clasica->id, 'cantidad' => 1]],
        ])->assertCreated();

        $this->assertEquals(20000, $respuesta->json('descuento'));
        $this->assertEquals(2000, $respuesta->json('total')); // solo el domicilio
    }

    public function test_las_promociones_quedan_guardadas_con_el_pedido_al_agregar_lineas(): void
    {
        $promo = $this->promocion(['nombre' => 'Hamburguesas 10 %', 'alcance' => 'categoria', 'valor' => 10], [], [$this->hamburguesas->id]);
        $pedido = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 1]]);

        // El Administrador la apaga; la línea nueva de ESTE pedido conserva la promoción con la que nació.
        $promo->update(['activo' => false]);
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/detalles", ['producto_id' => $this->doble->id, 'cantidad' => 1])->assertOk();

        $this->assertEquals(5000, $pedido->fresh()->descuento); // 10 % de 50.000
        // Un pedido nuevo ya no la tiene.
        $this->assertEquals(0, $this->pedidoLocal([['producto_id' => $this->doble->id, 'cantidad' => 1]])->descuento);
    }

    public function test_retirar_una_linea_recalcula_el_descuento(): void
    {
        $this->promocion(['nombre' => 'Total 10 %', 'alcance' => 'total', 'valor' => 10]);
        $pedido = $this->pedidoLocal([
            ['producto_id' => $this->clasica->id, 'cantidad' => 1],
            ['producto_id' => $this->doble->id, 'cantidad' => 1],
        ]);
        $this->assertEquals(5000, $pedido->descuento);

        Sanctum::actingAs($this->mesero);
        $doble = $pedido->detalles->firstWhere('producto_id', $this->doble->id);
        $this->deleteJson("/api/pedidos/{$pedido->id}/detalles/{$doble->id}")->assertOk();

        $this->assertEquals(2000, $pedido->fresh()->descuento);
        $this->assertEquals(18000, $pedido->fresh()->total);
    }

    // ---- Cotización antes de confirmar ----

    public function test_la_cotizacion_muestra_el_mismo_total_que_tendra_el_pedido(): void
    {
        $this->promocion(['nombre' => 'Clásica 20 %', 'alcance' => 'producto', 'valor' => 20], [$this->clasica->id]);
        $lineas = [['producto_id' => $this->clasica->id, 'cantidad' => 2], ['producto_id' => $this->gaseosa->id, 'cantidad' => 1]];

        $cotizacion = $this->postJson('/api/pedidos/cotizacion', ['tipo' => 'domicilio', 'lineas' => $lineas])->assertOk();

        $this->assertEquals(45000, $cotizacion->json('subtotal'));
        $this->assertEquals(8000, $cotizacion->json('descuento'));
        $this->assertEquals(2000, $cotizacion->json('valor_domicilio'));
        $this->assertEquals(39000, $cotizacion->json('total'));
        $this->assertSame('Clásica 20 %', $cotizacion->json('promociones.0.nombre'));
        $this->assertSame(0, Pedido::count()); // no guarda nada

        Sanctum::actingAs($this->cliente);
        $pedido = $this->postJson('/api/pedidos', [
            'tipo' => 'domicilio', 'origen' => 'cliente_web',
            'direccion_entrega' => 'Calle 1 # 2-3', 'barrio' => 'Centro', 'referencia' => 'Casa azul', 'lineas' => $lineas,
        ])->assertCreated();
        $this->assertEquals($cotizacion->json('total'), $pedido->json('total'));
    }

    public function test_la_cotizacion_rechaza_un_producto_agotado(): void
    {
        $this->clasica->update(['disponible' => false]);

        $this->postJson('/api/pedidos/cotizacion', ['tipo' => 'recogida', 'lineas' => [['producto_id' => $this->clasica->id, 'cantidad' => 1]]])
            ->assertStatus(422);
    }

    public function test_el_menu_muestra_las_promociones_vigentes_de_cada_producto(): void
    {
        $this->promocion(['nombre' => 'Clásica 20 %', 'alcance' => 'producto', 'valor' => 20], [$this->clasica->id]);
        $this->promocion(['nombre' => 'Bebidas 10 %', 'alcance' => 'categoria', 'valor' => 10], [], [$this->bebidas->id]);

        $productos = collect($this->getJson('/api/productos')->assertOk()->json());

        $this->assertSame('Clásica 20 %', $productos->firstWhere('id', $this->clasica->id)['promociones_vigentes'][0]['nombre']);
        $this->assertSame('Bebidas 10 %', $productos->firstWhere('id', $this->gaseosa->id)['promociones_vigentes'][0]['nombre']);
        $this->assertSame([], $productos->firstWhere('id', $this->doble->id)['promociones_vigentes']);
    }

    // ---- Descuento manual (RF-M04-036/037/039/041, RN-M04-21/22/23, HU-M04-015) ----

    public function test_RF_M04_036_el_mesero_aplica_un_descuento_manual_con_motivo(): void
    {
        $pedido = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 2]]);
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'porcentaje', 'valor' => 15, 'motivo' => 'Demora en la cocina'])
            ->assertOk()
            ->assertJsonPath('descuento_manual_motivo', 'Demora en la cocina')
            ->assertJsonPath('descuento_manual_por.nombre', 'Mesero Uno');

        $pedido->refresh();
        $this->assertEquals(6000, $pedido->descuento);
        $this->assertEquals(34000, $pedido->total);

        // RF-M04-039: auditoría con usuario, motivo y monto; y queda en el historial del pedido.
        $auditoria = Auditoria::where('accion', 'aplicar_descuento_manual')->first();
        $this->assertSame($this->mesero->id, $auditoria->user_id);
        $this->assertSame('Demora en la cocina', $auditoria->detalles['motivo']);
        $this->assertEquals(6000, $auditoria->detalles['descuento_nuevo']);
        $this->assertTrue(PedidoModificacion::where('pedido_id', $pedido->id)->where('accion', 'aplicar_descuento')->exists());
    }

    public function test_RF_M04_037_el_descuento_manual_reemplaza_las_promociones_automaticas(): void
    {
        $this->promocion(['nombre' => 'Clásica 20 %', 'alcance' => 'producto', 'valor' => 20], [$this->clasica->id]);
        $this->promocion(['nombre' => 'Total 5 %', 'alcance' => 'total', 'valor' => 5]);
        $pedido = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 1]]);
        $this->assertEquals(4800, $pedido->descuento); // 4.000 + 5 % de 16.000

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'fijo', 'valor' => 3000, 'motivo' => 'Cliente frecuente'])->assertOk();

        $pedido->refresh();
        $this->assertEquals(3000, $pedido->descuento); // no se acumulan
        $this->assertCount(1, $pedido->promociones_aplicadas);
        $this->assertSame('Descuento manual', $pedido->promociones_aplicadas[0]['nombre']);

        // Al quitar el descuento manual vuelven las automáticas del pedido.
        $this->deleteJson("/api/pedidos/{$pedido->id}/descuento")->assertOk()->assertJsonPath('descuento_manual', null);
        $this->assertEquals(4800, $pedido->fresh()->descuento);
        $this->assertTrue(Auditoria::where('accion', 'quitar_descuento_manual')->exists());
    }

    public function test_RN_M04_22_el_descuento_manual_exige_motivo(): void
    {
        $pedido = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 1]]);
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'porcentaje', 'valor' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->assertEquals(0, $pedido->fresh()->descuento);
    }

    public function test_RN_M04_23_el_valor_es_mayor_a_cero_y_el_porcentaje_no_pasa_de_100(): void
    {
        $pedido = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 1]]);
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'porcentaje', 'valor' => 120, 'motivo' => 'Prueba'])
            ->assertStatus(422)->assertJsonValidationErrors('valor');
        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'fijo', 'valor' => 0, 'motivo' => 'Prueba'])
            ->assertStatus(422)->assertJsonValidationErrors('valor');
        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'fijo', 'valor' => -500, 'motivo' => 'Prueba'])
            ->assertStatus(422)->assertJsonValidationErrors('valor');
    }

    public function test_el_cliente_no_puede_aplicar_descuentos(): void
    {
        Sanctum::actingAs($this->cliente);
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'cliente_id' => $this->cliente->id,
            'lineas' => [['producto_id' => $this->clasica->id, 'cantidad' => 1]],
        ]);

        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'porcentaje', 'valor' => 50, 'motivo' => 'Porque sí'])
            ->assertForbidden();
    }

    public function test_el_descuento_no_deja_el_total_por_debajo_de_lo_pagado(): void
    {
        $pedido = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 1]]);
        Pago::create(['pedido_id' => $pedido->id, 'metodo' => 'efectivo', 'monto' => 15000, 'estado' => 'aprobado', 'registrado_por' => $this->mesero->id]);
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'fijo', 'valor' => 8000, 'motivo' => 'Error en la cuenta'])
            ->assertStatus(422);
        $this->assertEquals(20000, $pedido->fresh()->total);
    }

    public function test_un_pedido_finalizado_no_recibe_descuento(): void
    {
        $pedido = $this->pedidoLocal([['producto_id' => $this->clasica->id, 'cantidad' => 1]]);
        $pedido->update(['estado' => 'finalizado', 'finalizado_at' => now()]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['tipo_valor' => 'fijo', 'valor' => 1000, 'motivo' => 'Tarde'])
            ->assertStatus(422);
    }

    public function test_se_puede_aplicar_a_mano_una_promocion_de_tipo_manual(): void
    {
        $cortesia = $this->promocion(['nombre' => 'Cortesía bebidas', 'tipo' => 'manual', 'alcance' => 'categoria', 'valor' => 100], [], [$this->bebidas->id]);
        $automatica = $this->promocion(['nombre' => 'Total 5 %', 'alcance' => 'total', 'valor' => 5]);
        $pedido = $this->pedidoLocal([
            ['producto_id' => $this->clasica->id, 'cantidad' => 1],
            ['producto_id' => $this->gaseosa->id, 'cantidad' => 1],
        ]);
        Sanctum::actingAs($this->mesero);

        // El Mesero ve las promociones manuales disponibles.
        $this->getJson('/api/promociones/manuales')->assertOk()->assertJsonCount(1)->assertJsonPath('0.nombre', 'Cortesía bebidas');

        // Una automática no se puede aplicar a mano.
        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['promocion_id' => $automatica->id, 'motivo' => 'Prueba'])
            ->assertStatus(422)->assertJsonValidationErrors('promocion_id');

        $this->postJson("/api/pedidos/{$pedido->id}/descuento", ['promocion_id' => $cortesia->id, 'motivo' => 'Cumpleaños'])->assertOk();
        $pedido->refresh();
        $this->assertEquals(5000, $pedido->descuento); // solo la gaseosa
        $this->assertEquals(20000, $pedido->total);
    }

    // ---- Reportes y auditoría de promociones ----

    public function test_las_ventas_por_producto_restan_el_descuento_y_cuadran_con_lo_cobrado(): void
    {
        $this->promocion(['nombre' => 'Total 10 %', 'alcance' => 'total', 'valor' => 10]);
        $pedido = $this->pedidoLocal([
            ['producto_id' => $this->clasica->id, 'cantidad' => 1],
            ['producto_id' => $this->gaseosa->id, 'cantidad' => 2],
        ]);
        $pedido->update(['estado' => 'finalizado', 'finalizado_at' => now()]);

        $reporte = app(ReporteService::class)->ventasPorProductoYCategoria(now()->subDay()->toImmutable(), now()->addDay()->toImmutable());
        $this->assertEquals((float) $pedido->fresh()->total, $reporte['resumen']['monto_total']);
        $this->assertEquals(18000, collect($reporte['detalle']['por_producto'])->firstWhere('nombre', 'Clásica')['monto_vendido']);
        $this->assertEquals(27000, (float) $pedido->fresh()->total);
    }

    public function test_RF_M04_039_crear_y_editar_una_promocion_queda_en_la_auditoria(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/promociones', [
            'nombre' => 'Amor y Amistad', 'tipo' => 'automatica', 'alcance' => 'total', 'tipo_valor' => 'porcentaje', 'valor' => 15,
        ])->assertCreated()->json('id');
        $this->putJson("/api/promociones/{$id}", [
            'nombre' => 'Amor y Amistad', 'tipo' => 'automatica', 'alcance' => 'total', 'tipo_valor' => 'porcentaje', 'valor' => 15, 'activo' => false,
        ])->assertOk();

        $this->assertTrue(Auditoria::where('accion', 'crear_promocion')->where('entidad_id', $id)->exists());
        $edicion = Auditoria::where('accion', 'editar_promocion')->where('entidad_id', $id)->first();
        $this->assertTrue($edicion->detalles['antes']['activo']);
        $this->assertFalse($edicion->detalles['despues']['activo']);
    }
}
