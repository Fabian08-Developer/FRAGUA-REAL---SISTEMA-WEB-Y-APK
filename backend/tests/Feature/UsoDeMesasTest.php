<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\User;
use App\Services\PedidoService;
use App\Services\ReporteExportService;
use App\Support\Reportes\PeriodoReporte;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 5, tarea 4 (13/10/2026): el uso de mesas cuenta también los pedidos locales, agrupados en
 * visitas, y no solo las reservas completadas. RF-M08-009, HU-M08-007, CA-M08-007.
 */
class UsoDeMesasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Producto $hamburguesa;
    private Mesa $mesa1;
    private Mesa $mesa2;
    private Mesa $mesa3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 22:00:00', 'America/Bogota'));

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa1 = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        $this->mesa2 = Mesa::create(['nombre' => 'Mesa 2', 'qr_code' => 'qr-2']);
        $this->mesa3 = Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-3']);
    }

    private function pedido(Mesa $mesa, string $creado, ?string $cerrado, string $estado = 'finalizado'): Pedido
    {
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]],
        ]);
        $hora = fn (?string $h) => $h ? CarbonImmutable::parse("2026-10-13 {$h}", 'America/Bogota')->utc() : null;
        $pedido->forceFill([
            'created_at' => $hora($creado),
            'estado' => $estado,
            'finalizado_at' => $estado === 'finalizado' ? $hora($cerrado) : null,
            'cancelado_at' => $estado === 'cancelado' ? $hora($cerrado) : null,
        ])->saveQuietly();

        return $pedido;
    }

    private function datosDelDia(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/reportes/uso-de-mesas?periodo=personalizado&desde=2026-10-13&hasta=2026-10-13')->assertOk()->json('reporte');
    }

    public function test_RF_M08_009_los_pedidos_locales_cuentan_como_visitas_a_la_mesa(): void
    {
        // Mesa 1: una visita de mediodía con dos pedidos (el segundo llega con la mesa ocupada)...
        $this->pedido($this->mesa1, '12:00', '13:00');
        $this->pedido($this->mesa1, '12:20', '12:50');
        // ...y otra visita en la noche, ya con la mesa libre.
        $this->pedido($this->mesa1, '19:00', '19:45');
        // Un pedido cancelado no es uso de la mesa.
        $this->pedido($this->mesa1, '20:30', '20:35', 'cancelado');
        // Mesa 2: solo una reserva completada (sin pedidos en el periodo).
        Reserva::create([
            'mesa_id' => $this->mesa2->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234', 'cliente_correo' => 'l@c.co',
            'fecha_hora' => CarbonImmutable::parse('2026-10-13 20:00', 'America/Bogota')->utc(), 'estado' => 'completada',
            'deposito_valor' => 20000, 'creado_por' => 'cliente_web',
        ]);

        $reporte = $this->datosDelDia();

        $this->assertSame(2, $reporte['resumen']['visitas']);
        $this->assertSame(3, $reporte['resumen']['pedidos_locales']);
        $this->assertSame(2, $reporte['resumen']['mesas_con_uso']);
        $this->assertSame(1, $reporte['resumen']['reservas_creadas']);

        $porMesa = collect($reporte['detalle']['ocupacion_por_mesa'])->keyBy('nombre');
        $this->assertSame(['Mesa 1', 'Mesa 2', 'Mesa 3'], $porMesa->keys()->all(), 'ordenadas por uso y con las que no se usaron');
        $this->assertSame([2, 3, 60000, 53, 0], [
            $porMesa['Mesa 1']['visitas'], $porMesa['Mesa 1']['pedidos_locales'], (int) $porMesa['Mesa 1']['ventas'],
            $porMesa['Mesa 1']['minutos_promedio'], $porMesa['Mesa 1']['reservas_completadas'],
        ], 'visitas de 60 y 45 minutos: promedio 53');
        $this->assertSame([0, 1], [$porMesa['Mesa 2']['visitas'], $porMesa['Mesa 2']['reservas_completadas']]);
        $this->assertSame([0, 0, null], [$porMesa['Mesa 3']['visitas'], $porMesa['Mesa 3']['reservas_completadas'], $porMesa['Mesa 3']['minutos_promedio']]);
    }

    public function test_una_visita_abierta_cuenta_pero_no_entra_al_promedio_de_minutos(): void
    {
        $this->pedido($this->mesa1, '18:00', '18:30');
        $this->pedido($this->mesa1, '21:00', null, 'preparando');
        $this->pedido($this->mesa1, '21:30', null, 'confirmado');

        $mesa = collect($this->datosDelDia()['detalle']['ocupacion_por_mesa'])->firstWhere('nombre', 'Mesa 1');

        $this->assertSame(2, $mesa['visitas'], 'los dos pedidos de la noche son de la misma visita, que sigue abierta');
        $this->assertSame(30, $mesa['minutos_promedio']);
        $this->assertSame(20000, (int) $mesa['ventas'], 'solo lo finalizado suma ventas');
    }

    public function test_la_exportacion_trae_la_ocupacion_completa(): void
    {
        $this->pedido($this->mesa1, '12:00', '13:00');
        $periodo = PeriodoReporte::resolver('personalizado', '2026-10-13', '2026-10-13');

        $doc = app(ReporteExportService::class)->documento('uso-de-mesas', $periodo, false, 10, $this->admin);

        $tabla = collect($doc['tablas'])->firstWhere('titulo', 'Ocupación por mesa');
        $this->assertSame(['Mesa', 'Visitas', 'Pedidos', 'Ventas', 'Minutos promedio por visita', 'Reservas completadas'], array_column($tabla['columnas'], 'texto'));
        $this->assertSame(['Mesa 1', 1, 1, 20000.0, 60, 0], $tabla['filas'][0]);
        $this->assertSame('—', $tabla['filas'][1][4]);
        $this->assertContains(['Visitas a las mesas', 1, 'numero'], $doc['resumen']);
    }
}
