<?php

namespace Tests\Feature;

use App\Jobs\EnviarAvisoReserva;
use App\Models\Auditoria;
use App\Models\Mesa;
use App\Models\Reserva;
use App\Models\ReservaAviso;
use App\Models\User;
use App\Notifications\CancelacionReservaNotification;
use App\Notifications\ConfirmacionReservaNotification;
use App\Notifications\NoShowReservaNotification;
use App\Services\AvisoReservaService;
use App\Services\CajaService;
use App\Services\ReservaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 4, tarea 4 (13/10/2026): avisos de reserva por correo (D3) con registro de cada envío o
 * falla, y cancelación por enlace sin cuenta.
 * RF-M05-018, 023, 025, 027, RN-M05-09, 17, 18, HU-M05-008, 011, 014.
 */
class AvisosDeReservaTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private Mesa $mesa;
    private CarbonImmutable $hora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hora = CarbonImmutable::parse('2026-10-13 20:00:00', 'America/Bogota');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'America/Bogota'));
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 4', 'qr_code' => 'qr-4', 'estado' => 'libre']);
        app(CajaService::class)->abrir($this->admin, 0);
    }

    private function reserva(string $creadoPor = 'cliente_web', ?Mesa $mesa = null): Reserva
    {
        return Reserva::create([
            'mesa_id' => ($mesa ?? $this->mesa)->id, 'cliente_nombre' => 'Laura Pérez', 'cliente_telefono' => '3105551234',
            'cliente_correo' => 'laura@correo.co', 'fecha_hora' => $this->hora->utc(),
            'estado' => 'pendiente_pago', 'deposito_valor' => 20000, 'creado_por' => $creadoPor,
        ]);
    }

    private function confirmadaEnLinea(?Mesa $mesa = null): Reserva
    {
        $reserva = $this->reserva('cliente_web', $mesa);
        $servicio = app(ReservaService::class);
        $servicio->registrarDepositoEnLinea($reserva, 'nequi');
        $servicio->confirmarDepositoEnLinea($reserva->fresh(), $this->admin);

        return $reserva->fresh();
    }

    public function test_RF_M05_023_al_confirmar_sale_el_correo_con_el_enlace_y_queda_registrado(): void
    {
        Notification::fake();
        $reserva = $this->confirmadaEnLinea();

        Notification::assertSentTo(new AnonymousNotifiable, ConfirmacionReservaNotification::class, function ($aviso, $canales, $destino) use ($reserva) {
            return $aviso->reserva->is($reserva) && array_key_exists('laura@correo.co', $destino->routes['mail']);
        });

        $registro = ReservaAviso::sole();
        $this->assertSame('confirmacion', $registro->tipo);
        $this->assertSame('enviado', $registro->estado);
        $this->assertSame(1, $registro->intentos);
        $this->assertNotNull($registro->enviado_at);
        $this->assertTrue(Auditoria::where('accion', 'enviar_aviso_reserva')->where('entidad_id', $reserva->id)->exists());

        $correo = (new ConfirmacionReservaNotification($reserva->fresh('mesa')))->toMail(new AnonymousNotifiable);
        $this->assertSame($reserva->enlacePublico(), $correo->actionUrl);
        $this->assertStringContainsString('/reserva/'.$reserva->token_publico, $correo->actionUrl);
        $this->assertStringContainsString('$20.000', implode(' ', $correo->introLines));
    }

    public function test_RF_M05_025_cancelacion_y_no_show_avisan_al_cliente(): void
    {
        Notification::fake();
        $cancelada = $this->confirmadaEnLinea();
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/reservas/{$cancelada->id}/cancelar")->assertOk();
        Notification::assertSentTo(new AnonymousNotifiable, CancelacionReservaNotification::class);
        $this->assertSame($this->mesero->id, Auditoria::where('accion', 'cancelar_reserva')->sole()->user_id);

        $otra = $this->confirmadaEnLinea();
        $this->travelTo($this->hora->addMinutes(16));
        $this->artisan('reservas:procesar')->assertSuccessful();
        Notification::assertSentTo(new AnonymousNotifiable, NoShowReservaNotification::class, fn ($aviso) => $aviso->reserva->is($otra));

        $this->assertEqualsCanonicalizing(
            ['confirmacion', 'cancelacion', 'confirmacion', 'no_show'],
            ReservaAviso::pluck('tipo')->all(),
        );
    }

    public function test_RF_M05_027_si_el_correo_falla_queda_fallido_con_el_error_sin_romper_la_reserva(): void
    {
        $this->mock(AvisoReservaService::class, function ($mock) {
            $mock->makePartial();
            $mock->shouldReceive('entregar')->andThrow(new RuntimeException('Conexión rechazada por el servidor SMTP'));
        });

        $reserva = $this->confirmadaEnLinea();

        $this->assertSame('confirmada', $reserva->estado, 'la reserva se confirma aunque el correo falle');
        $registro = ReservaAviso::sole();
        $this->assertSame('fallido', $registro->estado);
        $this->assertStringContainsString('SMTP', $registro->error);
        $falla = Auditoria::where('accion', 'falla_aviso_reserva')->sole();
        $this->assertStringContainsString('SMTP', $falla->detalles['error']);
    }

    public function test_el_personal_reintenta_un_aviso_fallido(): void
    {
        Notification::fake();
        $reserva = $this->confirmadaEnLinea();
        $aviso = ReservaAviso::sole();
        $aviso->forceFill(['estado' => 'fallido', 'error' => 'Tiempo de espera agotado'])->save();

        Sanctum::actingAs($this->mesero);
        $respuesta = $this->postJson("/api/reservas/{$reserva->id}/avisos/{$aviso->id}/reintentar")->assertOk();

        $this->assertSame('enviado', $aviso->fresh()->estado);
        $this->assertSame(2, $aviso->fresh()->intentos);
        $this->assertSame('enviado', $respuesta->json('avisos.0.estado'));
        // Uno que ya salió no se reintenta.
        $this->postJson("/api/reservas/{$reserva->id}/avisos/{$aviso->id}/reintentar")->assertStatus(422);
    }

    public function test_reservas_lista_los_avisos_de_cada_reserva(): void
    {
        Notification::fake();
        $this->confirmadaEnLinea();
        Sanctum::actingAs($this->admin);

        $avisos = $this->getJson('/api/reservas?proximas=1')->assertOk()->json('data.0.avisos');
        $this->assertSame('confirmacion', $avisos[0]['tipo']);
        $this->assertSame('enviado', $avisos[0]['estado']);
        $this->assertArrayNotHasKey('token_publico', $this->getJson('/api/reservas')->json('data.0'));
    }

    public function test_RF_M05_018_el_invitado_ve_y_cancela_su_reserva_con_el_enlace(): void
    {
        Notification::fake();
        $reserva = $this->confirmadaEnLinea();

        $this->getJson("/api/reservas/enlace/{$reserva->token_publico}")->assertOk()
            ->assertJsonPath('cliente_nombre', 'Laura Pérez')
            ->assertJsonPath('mesa', 'Mesa 4')
            ->assertJsonPath('estado', 'confirmada')
            ->assertJsonPath('puede_cancelar', true)
            ->assertJsonMissingPath('cliente_correo');

        $this->postJson("/api/reservas/enlace/{$reserva->token_publico}/cancelar")->assertOk()
            ->assertJsonPath('estado', 'cancelada')
            ->assertJsonPath('deposito_reembolsado', true)
            ->assertJsonPath('puede_cancelar', false);

        $registro = Auditoria::where('accion', 'cancelar_reserva')->sole();
        $this->assertNull($registro->user_id);
        $this->assertTrue($registro->detalles['por_enlace']);
        Notification::assertSentTo(new AnonymousNotifiable, CancelacionReservaNotification::class);
    }

    public function test_el_enlace_no_cancela_despues_de_la_hora_ni_un_deposito_en_efectivo(): void
    {
        Notification::fake();
        $enEfectivo = $this->reserva('mesero');
        app(ReservaService::class)->registrarDepositoManual($enEfectivo, 'efectivo', $this->mesero);

        $this->postJson("/api/reservas/enlace/{$enEfectivo->token_publico}/cancelar")
            ->assertStatus(422)->assertJsonPath('message', 'Pagaste el depósito en efectivo en el local: para cancelarla y recibir tu dinero, llama o pasa por el restaurante.');

        $web = $this->confirmadaEnLinea(Mesa::create(['nombre' => 'Mesa 5', 'qr_code' => 'qr-5', 'estado' => 'libre']));
        $this->travelTo($this->hora->addMinutes(5));
        $this->getJson("/api/reservas/enlace/{$web->token_publico}")->assertJsonPath('puede_cancelar', false);
        $this->postJson("/api/reservas/enlace/{$web->token_publico}/cancelar")->assertStatus(422);
        $this->assertSame('confirmada', $web->fresh()->estado);

        $this->getJson('/api/reservas/enlace/'.str_repeat('x', 48))->assertNotFound();
    }

    public function test_toda_reserva_tiene_su_enlace_secreto(): void
    {
        $a = $this->reserva();
        $b = $this->reserva();

        $this->assertSame(48, strlen($a->token_publico));
        $this->assertNotSame($a->token_publico, $b->token_publico);
    }

    public function test_el_trabajo_no_reenvia_un_aviso_ya_enviado(): void
    {
        Notification::fake();
        $this->confirmadaEnLinea();
        $aviso = ReservaAviso::sole();

        (new EnviarAvisoReserva($aviso->id))->handle(app(AvisoReservaService::class));

        Notification::assertSentTimes(ConfirmacionReservaNotification::class, 1);
        $this->assertSame(1, $aviso->fresh()->intentos);
    }
}
