<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\CierreAjuste;
use App\Models\CierreDiario;
use App\Models\CompraGasto;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Services\CajaService;
use App\Services\PedidoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 2, tarea 5 del plan de implementación: cierre diario completo (M03).
 *
 * RF-M03-20/21/22/23/24/25/26/27/34 · RN-M03-08/15 · RN-M06-11/12 · HU-M03-07/08/09/10.
 */
class CierreDiarioCompletoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private CajaTurno $turno;
    private const HOY = '2026-10-13';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::HOY.' 21:00:00', 'America/Bogota'));

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->turno = app(CajaService::class)->abrir($this->admin, 100000);

        // Una venta del día: 2 hamburguesas (alimento) + 1 gaseosa (bebida) = $45.000, pagada mitad
        // en efectivo y mitad con tarjeta.
        $categoria = Categoria::create(['nombre' => 'Menú']);
        $hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $gaseosa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Gaseosa', 'precio_base' => 5000, 'tipo_venta' => 'bebida']);
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $mesa->id,
            'lineas' => [['producto_id' => $hamburguesa->id, 'cantidad' => 2], ['producto_id' => $gaseosa->id, 'cantidad' => 1]],
        ]);
        Pago::create(['pedido_id' => $pedido->id, 'caja_turno_id' => $this->turno->id, 'metodo' => 'efectivo', 'monto' => 25000, 'estado' => 'aprobado', 'registrado_por' => $this->mesero->id]);
        Pago::create(['pedido_id' => $pedido->id, 'caja_turno_id' => $this->turno->id, 'metodo' => 'tarjeta', 'monto' => 20000, 'estado' => 'aprobado', 'registrado_por' => $this->mesero->id]);
        $pedido->update(['estado' => 'finalizado', 'finalizado_at' => now()]);

        Sanctum::actingAs($this->admin);
        $this->registro(['tipo' => 'compra', 'categoria' => 'Alimentos', 'descripcion' => 'Carne', 'valor' => 12000, 'metodo_pago' => 'efectivo']);
        $this->registro(['tipo' => 'compra', 'categoria' => 'Bebidas', 'descripcion' => 'Gaseosas', 'valor' => 6000, 'metodo_pago' => 'transferencia']);
        $this->registro(['tipo' => 'gasto', 'categoria' => 'Transporte', 'descripcion' => 'Taxi', 'valor' => 4000, 'metodo_pago' => 'efectivo']);
    }

    private function registro(array $datos, array $extra = [])
    {
        return $this->postJson('/api/compras-gastos', $datos + ['fecha' => self::HOY] + $extra);
    }

    private function cerrarCaja(): void
    {
        $turno = $this->turno->fresh();
        app(CajaService::class)->cerrar($turno, $this->admin, (float) app(CajaService::class)->recalcularEfectivoEsperado($turno));
    }

    // ---- Consulta previa con desglose (RF-M03-21 a 26, RN-M06-12, HU-M03-07/10) ----

    public function test_RF_M03_26_la_consulta_previa_muestra_el_calculo_con_su_desglose(): void
    {
        $previa = $this->getJson('/api/cierres-diarios/previa?fecha='.self::HOY)->assertOk();

        $previa->assertJsonPath('ventas.total', 45000)
            ->assertJsonPath('compras.total', 18000)
            ->assertJsonPath('gastos.total', 4000)
            ->assertJsonPath('resultado_operativo', 23000)
            ->assertJsonPath('cerrado', false);
        $this->assertStringContainsString('No es la utilidad contable', $previa->json('nota'));

        // Ventas por tipo de venta y por forma de pago (RN-M06-12).
        $porTipo = collect($previa->json('ventas.por_tipo'))->pluck('monto', 'tipo_venta');
        $this->assertEquals(40000, $porTipo['alimento']);
        $this->assertEquals(5000, $porTipo['bebida']);
        $porMetodo = collect($previa->json('ventas.por_metodo'))->pluck('monto', 'metodo');
        $this->assertEquals(25000, $porMetodo['efectivo']);
        $this->assertEquals(20000, $porMetodo['tarjeta']);

        // Compras y gastos por categoría y por forma de pago (RF-M03-22/23/24, HU-M03-10).
        $this->assertSame(['Alimentos', 'Bebidas'], collect($previa->json('compras.por_categoria'))->pluck('categoria')->all());
        $this->assertSame('Transporte', $previa->json('gastos.por_categoria.0.categoria'));
        $formas = collect($previa->json('compras_gastos_por_forma_de_pago'))->keyBy('metodo');
        $this->assertEquals(16000, $formas['efectivo']['total']);
        $this->assertEquals(4000, $formas['efectivo']['gastos']);
        $this->assertEquals(6000, $formas['transferencia']['compras']);

        // RN-M06-11: el arqueo de la caja alimenta el cierre; con la caja abierta no se puede cerrar.
        $previa->assertJsonPath('caja.estado', 'abierta')->assertJsonPath('puede_cerrar', false);
    }

    // ---- Cierre formal e historial (RF-M03-25/27, HU-M03-08, RN-M06-11) ----

    public function test_RN_M06_11_no_se_cierra_el_dia_con_la_caja_abierta(): void
    {
        $this->postJson('/api/cierres-diarios', ['fecha' => self::HOY])
            ->assertStatus(422)->assertJsonPath('message', 'Primero cierra la caja del día: el arqueo alimenta el cierre (RN-M06-11).');
        $this->assertSame(0, CierreDiario::count());
    }

    public function test_RF_M03_25_el_cierre_formal_guarda_el_desglose_y_queda_en_el_historial(): void
    {
        $this->cerrarCaja();

        $this->postJson('/api/cierres-diarios', ['fecha' => self::HOY])
            ->assertCreated()
            ->assertJsonPath('cierre.total_ventas', '45000.00')
            ->assertJsonPath('cierre.resultado_operativo', '23000.00')
            ->assertJsonPath('cierre.cerrado_por_usuario.nombre', 'Admin');

        $cierre = CierreDiario::sole();
        $this->assertSame($this->turno->id, $cierre->caja_turno_id);
        $this->assertSame('cerrada', $cierre->detalle['caja']['estado']);
        $this->assertCount(2, $cierre->detalle['compras']['por_categoria']);
        $this->assertTrue(Auditoria::where('accion', 'cierre_diario')->exists());

        // Una sola vez por día; no se cierran días futuros.
        $this->postJson('/api/cierres-diarios', ['fecha' => self::HOY])->assertStatus(422);
        $this->postJson('/api/cierres-diarios', ['fecha' => '2026-10-14'])->assertStatus(422);

        $this->getJson('/api/cierres-diarios')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.ajustes_count', 0);
        $this->getJson('/api/cierres-diarios/previa?fecha='.self::HOY)->assertJsonPath('cerrado', true)->assertJsonPath('cierre_id', $cierre->id);
    }

    public function test_solo_el_administrador_consulta_y_ejecuta_el_cierre(): void
    {
        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/cierres-diarios/previa?fecha='.self::HOY)->assertForbidden();
        $this->getJson('/api/cierres-diarios')->assertForbidden();
        $this->postJson('/api/cierres-diarios', ['fecha' => self::HOY])->assertForbidden();
    }

    // ---- Correcciones después del cierre (RF-M03-20, RN-M03-08/15, HU-M03-09) ----

    public function test_RN_M03_08_registrar_en_un_dia_cerrado_exige_contrasena_y_motivo(): void
    {
        $this->cerrarCaja();
        $id = $this->postJson('/api/cierres-diarios', ['fecha' => self::HOY])->json('cierre.id');
        $datos = ['tipo' => 'gasto', 'categoria' => 'Limpieza', 'descripcion' => 'Jabón olvidado', 'valor' => 3000, 'metodo_pago' => 'transferencia'];

        $this->registro($datos)->assertStatus(422)->assertJsonPath('requiere_autorizacion', true)->assertJsonPath('fechas_cerradas', [self::HOY]);
        $this->registro($datos, ['password_confirmacion' => 'equivocada', 'motivo_correccion' => 'Se olvidó'])->assertStatus(422)->assertJsonPath('requiere_autorizacion', true);
        $this->registro($datos, ['password_confirmacion' => 'secreto123'])->assertStatus(422)->assertJsonPath('requiere_autorizacion', true);
        $this->assertSame(3, CompraGasto::count());

        $this->registro($datos, ['password_confirmacion' => 'secreto123', 'motivo_correccion' => 'Se olvidó registrarlo'])->assertCreated();

        // El cierre no se reescribe: queda un ajuste y los valores actualizados.
        $cierre = CierreDiario::findOrFail($id);
        $this->assertEquals(4000, $cierre->total_gastos);
        $ajuste = CierreAjuste::sole();
        $this->assertEquals(3000, $ajuste->delta_gastos);
        $this->assertSame('Se olvidó registrarlo', $ajuste->motivo);
        $this->assertSame($this->admin->id, $ajuste->autorizado_por);

        $this->getJson("/api/cierres-diarios/{$id}")
            ->assertOk()
            ->assertJsonPath('cierre.total_gastos', '4000.00')
            ->assertJsonPath('actualizado.total_gastos', 7000)
            ->assertJsonPath('actualizado.resultado_operativo', 20000)
            ->assertJsonPath('ajustes.0.autorizado_por_usuario.nombre', 'Admin')
            ->assertJsonPath('calculo_hoy.gastos.total', 7000);
        $this->assertTrue(Auditoria::where('accion', 'ajuste_cierre_diario')->exists());
    }

    public function test_RF_M03_20_corregir_y_anular_en_un_dia_cerrado_queda_como_ajuste(): void
    {
        $carne = CompraGasto::where('descripcion', 'Carne')->sole();
        $this->cerrarCaja();
        $id = $this->postJson('/api/cierres-diarios', ['fecha' => self::HOY])->json('cierre.id');

        $this->putJson("/api/compras-gastos/{$carne->id}", ['valor' => 15000])->assertStatus(422)->assertJsonPath('requiere_autorizacion', true);
        $this->putJson("/api/compras-gastos/{$carne->id}", ['valor' => 15000, 'password_confirmacion' => 'secreto123', 'motivo_correccion' => 'Error de digitación'])->assertOk();

        $this->postJson("/api/compras-gastos/{$carne->id}/anular", ['motivo' => 'Se devolvió'])->assertStatus(422)->assertJsonPath('requiere_autorizacion', true);
        $this->postJson("/api/compras-gastos/{$carne->id}/anular", ['motivo' => 'Se devolvió', 'password_confirmacion' => 'secreto123'])->assertOk();

        $ajustes = CierreAjuste::orderBy('id')->get();
        $this->assertSame(['editar', 'anular'], $ajustes->pluck('accion')->all());
        $this->assertEquals(3000, $ajustes[0]->delta_compras);
        $this->assertEquals(-15000, $ajustes[1]->delta_compras);

        // 18.000 de compras al cerrar + 3.000 − 15.000 = 6.000.
        $this->getJson("/api/cierres-diarios/{$id}")->assertJsonPath('actualizado.total_compras', 6000)->assertJsonPath('calculo_hoy.compras.total', 6000);
        $this->getJson('/api/cierres-diarios')->assertJsonPath('data.0.ajustes_count', 2);
    }

    public function test_mover_un_registro_a_un_dia_cerrado_tambien_exige_autorizacion(): void
    {
        $this->cerrarCaja();
        $this->postJson('/api/cierres-diarios', ['fecha' => self::HOY])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'America/Bogota'));

        $id = $this->postJson('/api/compras-gastos', ['tipo' => 'compra', 'categoria' => 'Insumos', 'descripcion' => 'Servilletas', 'valor' => 5000, 'metodo_pago' => 'efectivo', 'fecha' => '2026-10-14'])
            ->assertCreated()->json('id');

        $this->putJson("/api/compras-gastos/{$id}", ['fecha' => self::HOY])->assertStatus(422)->assertJsonPath('requiere_autorizacion', true);
        $this->putJson("/api/compras-gastos/{$id}", ['fecha' => self::HOY, 'password_confirmacion' => 'secreto123', 'motivo_correccion' => 'Era del día anterior'])->assertOk();
        $this->assertEquals(5000, CierreAjuste::sole()->delta_compras);
    }
}
