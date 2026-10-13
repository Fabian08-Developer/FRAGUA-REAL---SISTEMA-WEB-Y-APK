<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Configuracion;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\User;
use App\Notifications\RecordatorioReservaNotification;
use App\Services\CajaService;
use App\Services\PedidoService;
use App\Services\ReservaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 4, tarea 3 (13/10/2026): no-show automático, mesa Reservada a tiempo y recordatorio.
 * RF-M05-014, 016, 024, RN-M05-07, 08, 13, 18; tiempos D6 (15 min, 2 h, 1 h).
 */
class ReservasAutomaticasTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private Mesa $mesa;
    private CarbonImmutable $hora;

    protected function setUp(): void
    {
        parent::setUp();

        // La reserva es el martes 13/10/2026 a las 8:00 p. m.; se registra el lunes al mediodía.
        $this->hora = CarbonImmutable::parse('2026-10-13 20:00:00', 'America/Bogota');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'America/Bogota'));

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
    }

    // El depósito manual y su devolución pasan por la caja del día (Fase 2, tarea 2).
    private function asegurarCaja(): void
    {
        $caja = app(CajaService::class);
        $caja->turnoAbierto() ?? $caja->abrir($this->admin, 0);
    }

    private function reservaConfirmada(?CarbonImmutable $hora = null): Reserva
    {
        $reserva = Reserva::create([
            'mesa_id' => $this->mesa->id, 'cliente_nombre' => 'Laura Pérez', 'cliente_telefono' => '3105551234',
            'cliente_correo' => 'laura@correo.co', 'fecha_hora' => ($hora ?? $this->hora)->utc(),
            'estado' => 'pendiente_pago', 'deposito_valor' => 20000, 'creado_por' => 'mesero',
        ]);
        $this->asegurarCaja();
        app(ReservaService::class)->registrarDepositoManual($reserva, 'efectivo', $this->mesero);

        return $reserva->fresh();
    }

    private function irA(CarbonImmutable $momento): void
    {
        $this->travelTo($momento);
        $this->artisan('reservas:procesar')->assertSuccessful();
    }

    public function test_RF_M05_014_la_mesa_se_ve_reservada_solo_desde_1_hora_antes(): void
    {
        $this->reservaConfirmada();
        $this->assertSame('libre', $this->mesa->fresh()->estado, 'confirmar el depósito un día antes ya no aparta la mesa');

        $this->irA($this->hora->subMinutes(61));
        $this->assertSame('libre', $this->mesa->fresh()->estado);

        $this->irA($this->hora->subMinutes(59));
        $this->assertSame('reservada', $this->mesa->fresh()->estado);
    }

    public function test_RF_M05_016_al_vencer_la_tolerancia_se_libera_sola_y_el_deposito_se_retiene(): void
    {
        $reserva = $this->reservaConfirmada();

        $this->irA($this->hora->addMinutes(14));
        $this->assertSame('confirmada', $reserva->fresh()->estado, 'sigue dentro de la tolerancia de 15 min');
        $this->assertSame('reservada', $this->mesa->fresh()->estado);

        $this->irA($this->hora->addMinutes(15));
        $reserva->refresh();
        $this->assertSame('no_show', $reserva->estado);
        $this->assertTrue($reserva->no_show_automatico);
        $this->assertNotNull($reserva->no_show_at);
        $this->assertFalse($reserva->deposito_reembolsado, 'RN-M05-18: el depósito se retiene');
        $this->assertSame('libre', $this->mesa->fresh()->estado);

        $registro = Auditoria::where('accion', 'no_show_automatico')->sole();
        $this->assertNull($registro->user_id);
        $this->assertEquals(20000, $registro->detalles['deposito_retenido']);
    }

    public function test_RN_M05_08_la_tolerancia_configurada_cambia_cuando_se_libera(): void
    {
        Configuracion::fijar('reserva_tolerancia_minutos', '30');
        $reserva = $this->reservaConfirmada();

        $this->irA($this->hora->addMinutes(20));
        $this->assertSame('confirmada', $reserva->fresh()->estado);

        $this->irA($this->hora->addMinutes(30));
        $this->assertSame('no_show', $reserva->fresh()->estado);
    }

    public function test_el_no_show_manual_solo_despues_de_la_tolerancia(): void
    {
        $reserva = $this->reservaConfirmada();
        Sanctum::actingAs($this->mesero);

        $this->travelTo($this->hora->addMinutes(5));
        $this->postJson("/api/reservas/{$reserva->id}/no-show")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Todavía está dentro de la tolerancia: se puede marcar no-show desde las 8:15 pm.');

        $this->travelTo($this->hora->addMinutes(16));
        $this->postJson("/api/reservas/{$reserva->id}/no-show")->assertOk()->assertJsonPath('estado', 'no_show')
            ->assertJsonPath('no_show_automatico', false);
        $this->assertSame($this->mesero->id, Auditoria::where('accion', 'marcar_no_show')->sole()->user_id);
    }

    public function test_una_mesa_ocupada_no_se_marca_reservada_hasta_que_se_libera(): void
    {
        $this->reservaConfirmada();
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 10000, 'tipo_venta' => 'alimento']);

        // A las 6:30 p. m. llegan otros clientes sin reserva y piden.
        $this->travelTo($this->hora->subMinutes(90));
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);
        $this->assertSame('ocupada', $this->mesa->fresh()->estado);
        $this->assertSame('confirmada', Reserva::first()->estado, 'no eran los de la reserva');

        $this->irA($this->hora->subMinutes(45));
        $this->assertSame('ocupada', $this->mesa->fresh()->estado, 'a quien está sentado no se le cambia la mesa');

        // Se van: la mesa queda libre y el siguiente proceso la marca Reservada.
        $pedido->forceFill(['estado' => 'finalizado'])->save();
        $this->mesa->fresh()->liberar();
        $this->irA($this->hora->subMinutes(44));
        $this->assertSame('reservada', $this->mesa->fresh()->estado);
    }

    public function test_el_primer_pedido_dentro_de_la_ventana_es_la_llegada_aunque_la_mesa_siga_libre(): void
    {
        $reserva = $this->reservaConfirmada();
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 30000, 'tipo_venta' => 'alimento']);

        // 7:40 p. m.: el proceso aún no corrió en ese minuto y la mesa sigue Libre.
        $this->travelTo($this->hora->subMinutes(20));
        $this->assertSame('libre', $this->mesa->fresh()->estado);
        app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        $reserva->refresh();
        $this->assertSame('completada', $reserva->estado);
        $this->assertEquals(20000, $reserva->deposito_abonado);
    }

    public function test_RF_M05_024_el_recordatorio_sale_2_horas_antes_y_una_sola_vez(): void
    {
        Notification::fake();
        $reserva = $this->reservaConfirmada();

        $this->irA($this->hora->subMinutes(121));
        Notification::assertNothingSent();

        $this->irA($this->hora->subMinutes(119));
        Notification::assertSentTo(new AnonymousNotifiable, RecordatorioReservaNotification::class, function ($aviso, $canales, $destino) use ($reserva) {
            return $aviso->reserva->is($reserva) && array_key_exists('laura@correo.co', $destino->routes['mail']);
        });
        $this->assertNotNull($reserva->fresh()->recordatorio_enviado_at);

        $this->irA($this->hora->subMinutes(60));
        Notification::assertSentTimes(RecordatorioReservaNotification::class, 1);

        // El correo dice la hora y la tolerancia.
        $correo = (new RecordatorioReservaNotification($reserva->fresh('mesa')))->toMail(new AnonymousNotifiable);
        $this->assertStringContainsString('8:00 pm', $correo->subject);
        $this->assertStringContainsString('15 minutos', implode(' ', $correo->introLines));
    }

    public function test_no_se_recuerda_una_reserva_confirmada_dentro_del_plazo_del_recordatorio(): void
    {
        Notification::fake();
        $this->travelTo($this->hora->subMinutes(90)); // reserva hecha hora y media antes
        $this->reservaConfirmada();

        $this->irA($this->hora->subMinutes(80));
        Notification::assertNothingSent();
    }

    public function test_cancelar_dentro_de_la_ventana_devuelve_la_mesa_a_libre(): void
    {
        $reserva = $this->reservaConfirmada();
        $this->irA($this->hora->subMinutes(30));
        $this->assertSame('reservada', $this->mesa->fresh()->estado);

        $this->asegurarCaja();
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/reservas/{$reserva->id}/cancelar")->assertOk();

        $this->assertSame('libre', $this->mesa->fresh()->estado);
    }

    public function test_una_mesa_que_quedo_reservada_por_el_criterio_anterior_se_libera(): void
    {
        // Antes de esta tarea la mesa quedaba Reservada desde la confirmación, aunque faltaran días.
        $this->reservaConfirmada($this->hora->addDays(3));
        $this->mesa->forceFill(['estado' => 'reservada'])->save();

        $this->irA($this->hora->subHours(5));

        $this->assertSame('libre', $this->mesa->fresh()->estado);
    }

    public function test_el_proceso_queda_programado_cada_minuto(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('reservas:procesar')->assertSuccessful();
    }
}
