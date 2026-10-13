<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\ReporteExportacion;
use App\Models\User;
use App\Services\PedidoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Fase 5, tarea 3 (13/10/2026): exportación real de reportes. RF-M08-011, 012, 014, RNF-M08-006,
 * HU-M08-009, 010, CA-M08-009, 010.
 */
class ExportacionReportesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Mesa $mesa;
    private Categoria $categoria;
    private array $dibujado = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-13 15:00:00', 'America/Bogota'));

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        Sanctum::actingAs($this->admin);

        View::composer('reportes.pdf', function ($vista) {
            $this->dibujado = $vista->getData();
        });
    }

    private function venta(string $nombre, int $cantidad, float $precio, string $tipo = 'alimento', string $metodo = 'efectivo'): Pedido
    {
        $producto = Producto::create(['categoria_id' => $this->categoria->id, 'nombre' => $nombre, 'precio_base' => $precio, 'tipo_venta' => $tipo]);
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => $cantidad]],
        ]);
        Pago::create(['pedido_id' => $pedido->id, 'metodo' => $metodo, 'monto' => $pedido->total, 'estado' => 'aprobado']);
        $pedido->forceFill(['estado' => 'finalizado', 'finalizado_at' => now()])->save();

        return $pedido;
    }

    private function exportar(array $datos)
    {
        return $this->post('/api/reportes/exportar', $datos + ['periodo' => 'semana']);
    }

    public function test_RF_M08_012_el_excel_es_un_xlsx_real_con_numeros_y_formato_de_pesos(): void
    {
        $this->venta('Clásica', 2, 20000);
        $this->venta('Gaseosa', 3, 4000, 'bebida', 'tarjeta');

        $respuesta = $this->exportar(['reporte' => 'ventas-por-tipo-y-metodo', 'formato' => 'excel'])->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('ventas-por-tipo-y-metodo_2026-10-12_a_2026-10-18.xlsx', $respuesta->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $respuesta->getContent(), 'un .xlsx es un zip');

        $archivo = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($archivo, $respuesta->getContent());
        $libro = IOFactory::load($archivo);
        unlink($archivo);

        $this->assertSame(['Resumen', 'Por tipo de venta', 'Por método de pago'], $libro->getSheetNames());
        $resumen = $libro->getSheetByName('Resumen');
        $this->assertSame('Ventas por tipo y método de pago', $resumen->getCell('A1')->getValue());
        $this->assertSame('Del 12 de octubre de 2026 al 18 de octubre de 2026', $resumen->getCell('A2')->getValue());
        $this->assertSame('Periodo en curso: las cifras son parciales.', $resumen->getCell('A4')->getValue());
        $this->assertEquals(52000, $resumen->getCell('B7')->getValue());
        $this->assertStringContainsString('"$"#,##0', $resumen->getStyle('B7')->getNumberFormat()->getFormatCode());

        $porTipo = $libro->getSheetByName('Por tipo de venta')->toArray(null, false, false);
        $this->assertSame(['Tipo de venta', 'Unidades', 'Monto vendido'], $porTipo[0]);
        $this->assertContains(['Alimentos', 2, 40000], array_map(fn ($f) => [$f[0], (int) $f[1], (int) $f[2]], array_slice($porTipo, 1)));
        $metodos = array_column(array_slice($libro->getSheetByName('Por método de pago')->toArray(), 1), 0);
        $this->assertEqualsCanonicalizing(['Efectivo', 'Tarjeta'], $metodos);
    }

    public function test_RF_M08_011_el_pdf_tiene_las_paginas_que_haga_falta_y_lo_mismo_que_la_pantalla(): void
    {
        foreach (range(1, 70) as $n) {
            $this->venta("Producto {$n}", 1, 1000 + $n);
        }

        $respuesta = $this->exportar(['reporte' => 'ventas-por-producto', 'formato' => 'pdf', 'limite' => 5])->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertGreaterThan(1, preg_match_all('#/Type\s*/Page\b#', $respuesta->getContent()), 'un reporte largo ya no se corta en una página');

        $doc = $this->dibujado['doc'];
        $this->assertTrue($doc['parcial']);
        $this->assertSame(['Los 5 más vendidos', 'Los 5 menos vendidos', 'Por categoría', 'Todos los productos'], array_column($doc['tablas'], 'titulo'));
        $this->assertCount(5, $doc['tablas'][0]['filas']);
        $this->assertCount(70, $doc['tablas'][3]['filas']);
        $html = view('reportes.pdf', $this->dibujado)->render();
        $this->assertStringContainsString('PERIODO EN CURSO', $html);
        $this->assertStringContainsString('Generado el 13/10/2026 3:00 pm por Ana Admin', $html);
    }

    public function test_RF_M08_010_si_la_pantalla_compara_el_archivo_tambien(): void
    {
        $this->venta('Clásica', 2, 20000);
        $this->travel(-7)->days();
        $this->venta('Clásica vieja', 1, 20000);
        $this->travel(7)->days();

        $this->exportar(['reporte' => 'ventas-totales', 'formato' => 'pdf', 'comparar' => true])->assertOk();

        $comparacion = $this->dibujado['doc']['comparacion'];
        $this->assertSame('Del 5 de octubre de 2026 al 11 de octubre de 2026', $comparacion['periodo']);
        $ventas = collect($comparacion['filas'])->firstWhere(0, 'Ventas');
        $this->assertEquals([40000, 20000, 20000, 100.0], array_slice($ventas, 1, 4));
        $html = view('reportes.pdf', $this->dibujado)->render();
        $this->assertStringContainsString('Comparación con el periodo anterior', $html);
        $this->assertStringContainsString('+100,0 %', $html);
    }

    public function test_RNF_M08_006_lo_exportado_se_vuelve_a_descargar_tal_cual_sin_regenerar(): void
    {
        $this->venta('Clásica', 2, 20000);
        $primera = $this->exportar(['reporte' => 'ventas-totales', 'formato' => 'excel'])->assertOk();
        $id = (int) $primera->headers->get('X-Exportacion-Id');

        $exportacion = ReporteExportacion::findOrFail($id);
        $this->assertSame(['ventas-totales', 'excel', '2026-10-12', '2026-10-18', true, 0], [
            $exportacion->reporte, $exportacion->formato, $exportacion->desde->toDateString(), $exportacion->hasta->toDateString(), $exportacion->parcial, $exportacion->descargas,
        ]);
        Storage::disk('local')->assertExists($exportacion->archivo);
        $registro = Auditoria::where('accion', 'exportar_reporte')->sole();
        $this->assertSame([$id, false], [$registro->detalles['exportacion_id'], $registro->detalles['comparar']]);

        // Hay ventas nuevas, pero la descarga es el mismo archivo: no se recalcula.
        $this->venta('Doble', 1, 30000);
        $lista = $this->getJson('/api/reportes/exportaciones')->assertOk()->json('data');
        $this->assertSame($id, $lista[0]['id']);
        $this->assertSame('Ana Admin', $lista[0]['generado_por_usuario']['nombre']);
        $this->assertArrayNotHasKey('archivo', $lista[0]);

        $otra = $this->get("/api/reportes/exportaciones/{$id}/descargar")->assertOk();
        $this->assertSame($primera->getContent(), $otra->getContent());
        $this->assertSame(1, $exportacion->fresh()->descargas);
        $descarga = Auditoria::where('accion', 'descargar_reporte')->sole();
        $this->assertSame([$id, 1], [$descarga->entidad_id, $descarga->detalles['descarga']]);
    }

    public function test_si_el_archivo_ya_no_esta_lo_dice(): void
    {
        $id = (int) $this->exportar(['reporte' => 'compras-y-gastos', 'formato' => 'pdf'])->headers->get('X-Exportacion-Id');
        Storage::disk('local')->delete(ReporteExportacion::find($id)->archivo);

        $this->getJson("/api/reportes/exportaciones/{$id}/descargar")->assertStatus(410)
            ->assertJsonPath('message', 'El archivo de esta exportación ya no está en el servidor. Vuelve a exportar el reporte.');
    }

    // ReporteService::resultadoOperativo() usa AT TIME ZONE (PostgreSQL, la base del servidor).
    public function test_resultado_operativo_se_exporta_con_su_nota(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Solo en PostgreSQL.');
        }
        $this->venta('Clásica', 2, 20000);

        $this->exportar(['reporte' => 'resultado-operativo', 'formato' => 'pdf'])->assertOk();

        $this->assertStringContainsString('No es la utilidad contable', $this->dibujado['doc']['nota']);
        $this->assertSame(['Ventas', 40000.0, 'moneda'], $this->dibujado['doc']['resumen'][0]);
    }

    public function test_solo_el_administrador(): void
    {
        $mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $id = (int) $this->exportar(['reporte' => 'uso-de-mesas', 'formato' => 'pdf'])->assertOk()->headers->get('X-Exportacion-Id');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($mesero);
        $this->postJson('/api/reportes/exportar', ['reporte' => 'uso-de-mesas', 'formato' => 'pdf'])->assertForbidden();
        $this->getJson('/api/reportes/exportaciones')->assertForbidden();
        $this->getJson("/api/reportes/exportaciones/{$id}/descargar")->assertForbidden();
    }
}
