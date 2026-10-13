<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\CierreDiario;
use App\Models\CompraGasto;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\User;
use App\Services\CajaService;
use App\Services\CierreDiarioService;
use App\Services\PedidoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 5, tarea 2 (13/10/2026): cierre diario impreso y en PDF. RF-M03-28, 29, RNF-M03-09, 10,
 * HU-M03-11, 12; regla de reimpresión del 13/10/2026.
 */
class CierreDiarioPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private CajaTurno $turno;
    private const HOY = '2026-10-13';
    private array $dibujado = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::HOY.' 21:00:00', 'America/Bogota'));

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->turno = app(CajaService::class)->abrir($this->admin, 100000);

        $categoria = Categoria::create(['nombre' => 'Menú']);
        $hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $mesa->id,
            'lineas' => [['producto_id' => $hamburguesa->id, 'cantidad' => 2]],
        ]);
        Pago::create(['pedido_id' => $pedido->id, 'caja_turno_id' => $this->turno->id, 'metodo' => 'efectivo', 'monto' => 40000, 'estado' => 'aprobado', 'registrado_por' => $this->mesero->id]);
        $pedido->update(['estado' => 'finalizado', 'finalizado_at' => now()]);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/compras-gastos', ['tipo' => 'compra', 'categoria' => 'Alimentos', 'descripcion' => 'Carne', 'valor' => 12000, 'metodo_pago' => 'efectivo', 'fecha' => self::HOY])->assertCreated();
        $this->postJson('/api/compras-gastos', ['tipo' => 'gasto', 'categoria' => 'Transporte', 'descripcion' => 'Taxi', 'valor' => 4000, 'metodo_pago' => 'efectivo', 'fecha' => self::HOY])->assertCreated();

        $caja = app(CajaService::class);
        $caja->cerrar($this->turno->fresh(), $this->admin, (float) $caja->recalcularEfectivoEsperado($this->turno->fresh()));

        View::composer('cierres.pdf', function ($vista) {
            $this->dibujado = $vista->getData();
        });
    }

    private function cerrarDia(): CierreDiario
    {
        $this->postJson('/api/cierres-diarios', ['fecha' => self::HOY])->assertSuccessful();

        return CierreDiario::sole();
    }

    public function test_RF_M03_29_el_administrador_descarga_el_pdf_del_cierre(): void
    {
        $cierre = $this->cerrarDia();

        $respuesta = $this->get("/api/cierres-diarios/{$cierre->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
        $this->assertStringContainsString('cierre-diario-2026-10-13.pdf', $respuesta->headers->get('Content-Disposition'));

        $this->assertNull($this->dibujado['reimpresion'], 'la primera impresión es el original');
        $this->assertSame('Ana Admin', $this->dibujado['impresoPor']);
        $this->assertSame(1, $cierre->fresh()->impresiones);
        $registro = Auditoria::where('accion', 'imprimir_cierre_diario')->sole();
        $this->assertSame([self::HOY, 1, false], [$registro->detalles['fecha'], $registro->detalles['vez'], $registro->detalles['reimpresion']]);
        $this->assertSame($this->admin->id, $registro->user_id);
    }

    public function test_RNF_M03_10_el_pdf_lleva_el_dia_los_totales_el_detalle_y_los_responsables(): void
    {
        $cierre = $this->cerrarDia();
        $this->get("/api/cierres-diarios/{$cierre->id}/pdf")->assertOk();
        $html = view('cierres.pdf', $this->dibujado)->render();

        $this->assertStringContainsString('Martes 13 de octubre de 2026', $html);
        $this->assertStringContainsString('Cerrado por Ana Admin', $html);
        $this->assertStringContainsString('impreso por Ana Admin', $html);
        foreach (['$40.000', '$12.000', '$4.000', '$24.000'] as $valor) {
            $this->assertStringContainsString($valor, $html, "falta {$valor}");
        }
        $this->assertStringContainsString('Alimentos (1)', $html);
        $this->assertStringContainsString('Transporte (1)', $html);
        $this->assertStringContainsString('Efectivo esperado', $html);
        $this->assertStringContainsString('No es la utilidad contable', $html);
        $this->assertStringContainsString('Revisó', $html);
        $this->assertStringNotContainsString('REIMPRESIÓN', $html);
    }

    public function test_las_correcciones_posteriores_salen_con_los_valores_actualizados(): void
    {
        $cierre = $this->cerrarDia();
        $carne = CompraGasto::where('descripcion', 'Carne')->sole();
        $this->putJson("/api/compras-gastos/{$carne->id}", ['valor' => 15000, 'password_confirmacion' => 'secreto123', 'motivo_correccion' => 'Error de digitación'])->assertOk();

        $this->get("/api/cierres-diarios/{$cierre->id}/pdf")->assertOk();
        $html = view('cierres.pdf', $this->dibujado)->render();

        $this->assertStringContainsString('Correcciones después del cierre', $html);
        $this->assertStringContainsString('Error de digitación', $html);
        $this->assertStringContainsString('Corrigió "Carne"', $html);
        $this->assertStringContainsString('compras $15.000', $html);
        $this->assertStringContainsString('resultado operativo $21.000', $html);
        // El cierre original no cambia.
        $this->assertStringContainsString('$12.000', $html);
    }

    public function test_la_segunda_impresion_sale_como_reimpresion(): void
    {
        $cierre = $this->cerrarDia();
        $this->get("/api/cierres-diarios/{$cierre->id}/pdf")->assertOk();
        $this->get("/api/cierres-diarios/{$cierre->id}/pdf")->assertOk();

        $this->assertSame(2, $this->dibujado['reimpresion']['vez']);
        $html = view('cierres.pdf', $this->dibujado)->render();
        $this->assertStringContainsString('REIMPRESIÓN · copia 2', $html);
        $this->assertSame(2, $cierre->fresh()->impresiones);
        $this->assertSame(2, Auditoria::where('accion', 'imprimir_cierre_diario')->count());
        $this->getJson("/api/cierres-diarios/{$cierre->id}")->assertJsonPath('cierre.impresiones', 2);
    }

    public function test_solo_el_administrador_y_un_cierre_viejo_sin_desglose_tambien_sale(): void
    {
        $cierre = $this->cerrarDia();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->mesero);
        $this->get("/api/cierres-diarios/{$cierre->id}/pdf")->assertForbidden();

        $viejo = CierreDiario::create([
            'fecha' => '2026-09-30', 'total_ventas' => 100000, 'total_compras' => 30000, 'total_gastos' => 10000,
            'resultado_operativo' => 60000, 'cerrado_por' => $this->admin->id,
        ]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin);
        $this->get("/api/cierres-diarios/{$viejo->id}/pdf")->assertOk();
        $html = view('cierres.pdf', $this->dibujado)->render();
        $this->assertStringContainsString('solo tiene los totales', $html);
        $this->assertStringContainsString('$60.000', $html);
        $this->assertNotNull(app(CierreDiarioService::class));
    }
}
