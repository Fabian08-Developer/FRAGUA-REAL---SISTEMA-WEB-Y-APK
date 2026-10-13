<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\CierreDiarioService;
use App\Services\PedidoService;
use App\Services\ReporteService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 0, tarea 5 del plan de implementación: las cifras de M08 y del cierre de M03 cuadran
 * con lo vendido. RN-M04-26, RF-M06-024, RF-M08-003, RF-M08-005, RNF-M08-002, RF-M03-33.
 */
class CifrasReportesTest extends TestCase
{
    use RefreshDatabase;

    private Mesa $mesa;
    private Producto $hamburguesa;
    private Extra $tocineta;
    private Combo $combo;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $gaseosa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Gaseosa', 'precio_base' => 4000, 'tipo_venta' => 'bebida']);
        $this->tocineta = Extra::create(['nombre' => 'Tocineta', 'precio' => 3000, 'alcance' => 'general', 'activo' => true]);
        $this->combo = Combo::create(['nombre' => 'Combo pareja', 'precio' => 45000, 'tipo_venta' => 'otro', 'activo' => true]);
        $this->combo->productos()->attach([$this->hamburguesa->id => ['cantidad' => 2], $gaseosa->id => ['cantidad' => 2]]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
    }

    // Crea un pedido local con una hamburguesa con tocineta (x2) y un combo, y lo deja
    // finalizado en el instante indicado.
    private function pedidoFinalizadoEn(CarbonImmutable $momento): Pedido
    {
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [
                ['producto_id' => $this->hamburguesa->id, 'cantidad' => 2, 'extras_ids' => [$this->tocineta->id]],
                ['combo_id' => $this->combo->id, 'cantidad' => 1],
            ],
        ]);
        $pedido->forceFill(['estado' => 'finalizado', 'finalizado_at' => $momento->utc()])->save();

        return $pedido;
    }

    private function diaCompleto(): array
    {
        $hoy = CarbonImmutable::now('America/Bogota')->startOfDay();

        return [$hoy->utc(), $hoy->endOfDay()->utc()];
    }

    public function test_RN_M04_26_las_ventas_por_producto_incluyen_combos_y_extras_y_cuadran_con_el_pedido(): void
    {
        $pedido = $this->pedidoFinalizadoEn(CarbonImmutable::now('America/Bogota')->startOfDay()->addHours(12));
        // 2 × (20.000 + 3.000) + 45.000 = 91.000
        $this->assertEquals(91000, $pedido->total);

        [$desde, $hasta] = $this->diaCompleto();
        $reporte = app(ReporteService::class)->ventasPorProductoYCategoria($desde, $hasta);

        $this->assertEquals(91000, $reporte['resumen']['monto_total'], 'Ventas por producto = total vendido (RNF-M08-002).');
        $combo = collect($reporte['detalle']['por_producto'])->firstWhere('es_combo', true);
        $this->assertSame('Combo pareja', $combo['nombre']);
        $this->assertEquals(45000, $combo['monto_vendido']);
        $this->assertNotNull(collect($reporte['detalle']['por_categoria'])->firstWhere('nombre', 'Combos'));
    }

    public function test_RF_M08_005_las_ventas_por_tipo_usan_el_tipo_del_combo_y_suman_los_extras(): void
    {
        $this->pedidoFinalizadoEn(CarbonImmutable::now('America/Bogota')->startOfDay()->addHours(12));

        [$desde, $hasta] = $this->diaCompleto();
        $porTipo = collect(app(ReporteService::class)->ventasPorTipoYMetodo($desde, $hasta)['detalle']['por_tipo'])
            ->mapWithKeys(fn ($fila) => [$fila->tipo_venta => (float) $fila->monto_vendido]);

        $this->assertEquals(46000, $porTipo['alimento'], 'La tocineta cuenta como alimento, igual que su hamburguesa.');
        $this->assertEquals(45000, $porTipo['otro'], 'El combo usa su propio tipo de venta.');
    }

    public function test_RF_M03_33_el_cierre_cuenta_las_ventas_de_la_noche_en_el_dia_de_bogota(): void
    {
        // 8:30 p. m. en Bogotá ya es el día siguiente en UTC.
        $noche = CarbonImmutable::now('America/Bogota')->startOfDay()->setTime(20, 30);
        $this->pedidoFinalizadoEn($noche);

        $cierre = app(CierreDiarioService::class)->generar($noche->toDateTime(), $this->admin);

        $this->assertEquals(91000, $cierre->total_ventas);
    }

    public function test_la_exportacion_de_ventas_por_producto_sigue_funcionando(): void
    {
        $this->pedidoFinalizadoEn(CarbonImmutable::now('America/Bogota')->startOfDay()->addHours(12));
        // Fase 5, tarea 3: la exportación arma su documento con el mismo cálculo de la pantalla.
        $hoy = CarbonImmutable::now('America/Bogota')->toDateString();
        $periodo = \App\Support\Reportes\PeriodoReporte::resolver('personalizado', $hoy, $hoy);
        $doc = app(\App\Services\ReporteExportService::class)->documento('ventas-por-producto', $periodo, false, 10, $this->admin);

        $todos = collect($doc['tablas'])->firstWhere('titulo', 'Todos los productos');
        $this->assertContains('Combo: Combo pareja', array_column($todos['filas'], 0));
    }
}
