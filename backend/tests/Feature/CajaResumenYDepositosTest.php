<?php

namespace Tests\Feature;

use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\User;
use App\Services\CajaService;
use App\Services\PagoService;
use App\Services\PedidoService;
use App\Services\ReporteService;
use App\Services\ReservaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mejora (16/10/2026):
 *  - Resumen del turno de caja: ingresos por método (también en línea), de dónde sale el efectivo
 *    esperado, depósitos de reserva del día (también en línea) y anulados aparte.
 *  - Cierre diario: anticipos de reservas recibidos en el día y depósitos retenidos por no-show
 *    (ingreso del día en que se marca, RN-M05-18), que también suman al resultado operativo.
 */
class CajaResumenYDepositosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private Producto $producto;
    private Mesa $mesa;
    private CajaTurno $turno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        $this->turno = app(CajaService::class)->abrir($this->admin, 100000);
    }

    private function hoy(): string
    {
        return now('America/Bogota')->toDateString();
    }

    private function pedido(): Pedido
    {
        return app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ]);
    }

    private function cobrar(string $metodo): Pago
    {
        return app(PagoService::class)->registrarCobro($this->pedido(), $this->mesero, ['metodo' => $metodo, 'monto' => 20000]);
    }

    private function reservaConDeposito(string $metodo, int $horasDesdeAhora = 5): Reserva
    {
        $mesa = Mesa::create(['nombre' => 'Mesa R'.uniqid(), 'qr_code' => 'qr-'.uniqid()]);
        $reserva = Reserva::create([
            'mesa_id' => $mesa->id, 'cliente_nombre' => 'Laura Pérez', 'cliente_telefono' => '3105551234',
            'cliente_correo' => 'laura@correo.co', 'fecha_hora' => now()->addHours($horasDesdeAhora),
            'estado' => 'pendiente_pago', 'deposito_valor' => 20000, 'creado_por' => $metodo === 'en_linea' ? 'cliente_web' : 'mesero',
        ]);
        $servicio = app(ReservaService::class);
        if ($metodo === 'en_linea') {
            $servicio->registrarDepositoEnLinea($reserva, 'nequi');
            $servicio->confirmarDepositoEnLinea($reserva->fresh(), $this->admin);
        } else {
            $servicio->registrarDepositoManual($reserva, $metodo, $this->mesero);
        }

        return $reserva->fresh();
    }

    private function marcarNoShow(Reserva $reserva, ?\DateTimeInterface $cuando = null): void
    {
        $reserva->forceFill(['estado' => 'no_show', 'no_show_at' => $cuando ?? now()])->save();
    }

    public function test_el_resumen_del_turno_separa_los_ingresos_por_metodo(): void
    {
        $this->cobrar('efectivo');
        $this->cobrar('tarjeta');
        $this->cobrar('transferencia');
        // Pago en línea del día (no lleva turno, ADR-004).
        Pago::create(['pedido_id' => $this->pedido()->id, 'tipo' => 'cobro', 'metodo' => 'en_linea', 'pasarela' => 'nequi', 'monto' => 20000, 'estado' => 'aprobado']);
        // Un cobro anulado no cuenta como ingreso: va aparte.
        app(PagoService::class)->anular($this->cobrar('efectivo'), $this->admin, 'Se cobró dos veces');
        // Un gasto en efectivo y dos depósitos de reserva (uno en el local, otro en línea).
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/compras-gastos', [
            'tipo' => 'gasto', 'categoria' => 'Servicios', 'descripcion' => 'Gas', 'valor' => 15000, 'metodo_pago' => 'efectivo', 'fecha' => $this->hoy(),
        ])->assertCreated();
        $this->reservaConDeposito('efectivo');
        $enLinea = $this->reservaConDeposito('en_linea');

        Sanctum::actingAs($this->admin);
        $resumen = $this->getJson('/api/caja/turno-actual')->assertOk()->json('resumen');

        $porMetodo = collect($resumen['ingresos']['por_metodo'])->keyBy('metodo');
        $this->assertEquals(20000, $porMetodo['efectivo']['monto']);
        $this->assertSame(1, $porMetodo['efectivo']['cantidad']);
        $this->assertEquals(20000, $porMetodo['tarjeta']['monto']);
        $this->assertEquals(20000, $porMetodo['transferencia']['monto']);
        $this->assertEquals(20000, $porMetodo['en_linea']['monto']);
        $this->assertEquals(80000, $resumen['ingresos']['total']);
        $this->assertSame(4, $resumen['ingresos']['cantidad']);

        // Base 100.000 + cobros 20.000 + depósito en efectivo 20.000 − gasto 15.000.
        $this->assertEquals(['base_inicial' => 100000, 'cobros' => 20000, 'depositos' => 20000, 'gastos' => 15000, 'devoluciones' => 0, 'esperado' => 125000], $resumen['efectivo']);
        $this->assertEquals(125000, $this->turno->fresh()->efectivo_esperado, 'El desglose coincide con el esperado guardado.');

        $depositos = collect($resumen['depositos']['por_metodo'])->keyBy('metodo');
        $this->assertEquals(20000, $depositos['efectivo']['monto']);
        $this->assertEquals(20000, $depositos['en_linea']['monto']);
        $this->assertEquals(40000, $resumen['depositos']['total']);

        $this->assertSame(1, $resumen['anulados']['cantidad']);
        $this->assertEquals(20000, $resumen['anulados']['total']);

        // El detalle del turno trae el mismo resumen y los depósitos en línea del día.
        $detalle = $this->getJson("/api/caja/turnos/{$this->turno->id}")->assertOk();
        $this->assertEquals(80000, $detalle->json('resumen.ingresos.total'));
        $this->assertSame([$enLinea->id], collect($detalle->json('depositos_en_linea'))->pluck('id')->all());
    }

    public function test_el_mesero_no_ve_el_resumen_de_la_caja(): void
    {
        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/caja/turno-actual')->assertForbidden();
    }

    public function test_el_cierre_muestra_los_anticipos_y_cuenta_el_no_show_como_ingreso(): void
    {
        $this->reservaConDeposito('efectivo');
        $noShow = $this->reservaConDeposito('en_linea');
        $this->marcarNoShow($noShow);
        // No cuentan: un no-show de ayer y uno cuyo depósito se devolvió.
        $deAyer = $this->reservaConDeposito('tarjeta');
        $this->marcarNoShow($deAyer, now()->subDay());
        $devuelto = $this->reservaConDeposito('transferencia');
        $this->marcarNoShow($devuelto);
        $devuelto->forceFill(['deposito_reembolsado' => true])->save();

        Sanctum::actingAs($this->admin);
        $previa = $this->getJson('/api/cierres-diarios/previa?fecha='.$this->hoy())->assertOk();

        // Anticipos recibidos hoy: los cuatro depósitos (todavía no son venta).
        $this->assertEquals(80000, $previa->json('anticipos_reserva.total'));
        $this->assertSame(4, $previa->json('anticipos_reserva.cantidad'));
        // Retenido por no-show hoy: solo el de la reserva en línea.
        $this->assertEquals(20000, $previa->json('depositos_retenidos.total'));
        $this->assertSame([$noShow->id], collect($previa->json('depositos_retenidos.reservas'))->pluck('reserva_id')->all());
        // Sin ventas ni gastos: el resultado operativo es lo retenido.
        $this->assertEquals(20000, $previa->json('resultado_operativo'));

        // El reporte de resultado operativo lo muestra como otros ingresos (usa AT TIME ZONE para los
        // días sin cierre: solo en PostgreSQL).
        if (DB::getDriverName() === 'pgsql') {
            $reporte = app(ReporteService::class)->resultadoOperativo($this->hoy(), $this->hoy())['resumen'];
            $this->assertEquals(20000, $reporte['otros_ingresos']);
            $this->assertEquals(20000, $reporte['resultado_operativo']);
        }

        // Al cerrar el día, el cierre guarda el resultado con lo retenido.
        app(CajaService::class)->cerrar($this->turno->fresh(), $this->admin, (float) $this->turno->fresh()->efectivo_esperado);
        $this->postJson('/api/cierres-diarios', ['fecha' => $this->hoy()])->assertCreated();
        $cierre = \App\Models\CierreDiario::firstOrFail();
        $this->assertEquals(20000, $cierre->resultado_operativo);
        $this->assertEquals(20000, $cierre->detalle['depositos_retenidos']['total']);
        $this->assertEquals(80000, $cierre->detalle['anticipos_reserva']['total']);
    }

    public function test_el_reporte_suma_los_no_show_del_periodo(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('El resultado operativo de días sin cierre usa AT TIME ZONE: solo en PostgreSQL.');
        }
        $this->marcarNoShow($this->reservaConDeposito('efectivo'));
        $this->marcarNoShow($this->reservaConDeposito('en_linea'), CarbonImmutable::now('America/Bogota')->subDays(2)->setTime(12, 0));

        $desde = CarbonImmutable::now('America/Bogota')->subDays(6)->toDateString();
        $reporte = app(ReporteService::class)->resultadoOperativo($desde, $this->hoy())['resumen'];

        $this->assertEquals(40000, $reporte['otros_ingresos']);
    }
}
