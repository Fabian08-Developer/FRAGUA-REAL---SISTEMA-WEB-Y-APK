<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CierreDiario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\PedidoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 7, tarea 5 (13/10/2026): reportes de un mes completo a buena velocidad (RNF-M08-001).
 *
 * Medido en PostgreSQL con un año de historia de prueba (unos 110.000 pedidos): «Ventas por producto»
 * de 30 días bajó de más de 2 minutos a menos de medio segundo, con la comparación incluida. Estas
 * pruebas cuidan lo que lo hizo posible (los índices) y que las cifras no cambiaron.
 */
class ReportesRendimientoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Producto $clasica;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 15:00:00', 'America/Bogota'));
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->clasica = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
    }

    // Un pedido para recoger finalizado a la hora dada (hora de Colombia).
    private function pedidoFinalizado(string $finalizado, int $cantidad = 1): Pedido
    {
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'recogida', 'origen' => 'mesero',
            'cliente_invitado_nombre' => 'Luz', 'cliente_invitado_telefono' => '3001234567',
            'lineas' => [['producto_id' => $this->clasica->id, 'cantidad' => $cantidad]],
        ]);
        $hora = CarbonImmutable::parse($finalizado, 'America/Bogota')->utc();
        $pedido->forceFill(['estado' => 'finalizado', 'created_at' => $hora->subMinutes(30), 'finalizado_at' => $hora])->saveQuietly();

        return $pedido;
    }

    public function test_los_reportes_tienen_sus_indices(): void
    {
        $indices = [
            'pedidos' => ['pedidos_estado_finalizado_at_index', 'pedidos_created_at_index', 'pedidos_mesa_id_estado_index', 'pedidos_cliente_id_index'],
            'pedido_detalles' => ['pedido_detalles_pedido_id_index'],
            'pedido_detalle_extra' => ['pedido_detalle_extra_pedido_detalle_id_index'],
            'pagos' => ['pagos_pedido_id_index', 'pagos_caja_turno_id_index', 'pagos_created_at_index'],
            'reservas' => ['reservas_mesa_id_fecha_hora_index', 'reservas_fecha_hora_index'],
            'ajustes_caja' => ['ajustes_caja_caja_turno_id_index'],
        ];
        foreach ($indices as $tabla => $nombres) {
            foreach ($nombres as $nombre) {
                $this->assertTrue(Schema::hasIndex($tabla, $nombre), "Falta el índice {$nombre} en {$tabla}.");
            }
        }
    }

    public function test_el_resultado_operativo_de_los_dias_sin_cierre_cuenta_cada_pedido_en_su_dia(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Usa AT TIME ZONE: solo en PostgreSQL.');
        }

        // 11/10 tiene cierre; 12 y 13 todavía no.
        CierreDiario::create(['fecha' => '2026-10-11', 'total_ventas' => 50000, 'total_compras' => 0, 'total_gastos' => 0, 'resultado_operativo' => 50000, 'cerrado_por' => $this->admin->id]);
        $this->pedidoFinalizado('2026-10-10 21:00:00');     // antes del periodo: no cuenta
        $this->pedidoFinalizado('2026-10-11 23:30:00');     // ya está en el cierre del 11 (en UTC ya es el 12)
        $this->pedidoFinalizado('2026-10-12 23:30:00', 2);  // día sin cierre (en UTC ya es el 13)
        $this->pedidoFinalizado('2026-10-13 12:00:00');     // hoy

        Sanctum::actingAs($this->admin);
        $resumen = $this->getJson('/api/reportes/resultado-operativo?periodo=personalizado&desde=2026-10-11&hasta=2026-10-13')
            ->assertOk()->json('reporte.resumen');

        // 50.000 del cierre del 11 + 40.000 del 12 + 20.000 de hoy.
        $this->assertEquals(110000, (float) $resumen['total_ventas']);
        $this->assertEquals(110000, (float) $resumen['resultado_operativo']);
    }

    public function test_el_comando_mide_los_seis_reportes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('El resultado operativo de hoy usa AT TIME ZONE: solo en PostgreSQL.');
        }

        $this->pedidoFinalizado('2026-10-13 12:00:00');
        $this->artisan('reportes:medir', ['--dias' => 7])
            ->expectsOutputToContain('(pedidos finalizados: 1)')
            ->expectsOutputToContain('Todos los reportes responden en menos de 2 segundos.')
            ->assertExitCode(0);
    }
}
