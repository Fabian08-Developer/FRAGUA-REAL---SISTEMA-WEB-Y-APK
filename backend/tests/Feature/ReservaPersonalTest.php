<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Mesa;
use App\Models\Reserva;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1, tarea 4 del plan de implementación: reservas registradas por el personal, depósito
 * manual, llegada del cliente y reservas que no se cruzan.
 * RF-M05-012/013/015/031/036, RN-M05-06/07/12/16, HU-M05-009/010.
 */
class ReservaPersonalTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private Mesa $mesa;
    private Mesa $otraMesa;
    private CarbonImmutable $manana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        $this->otraMesa = Mesa::create(['nombre' => 'Mesa 2', 'qr_code' => 'qr-2', 'estado' => 'libre']);
        $this->manana = CarbonImmutable::now('America/Bogota')->addDay()->setTime(20, 0);
        // Fase 2, tarea 2: el depósito manual entra a la caja del día, así que necesita la caja abierta.
        app(\App\Services\CajaService::class)->abrir($this->admin, 0);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'mesa_id' => $this->mesa->id,
            'cliente_nombre' => 'Laura Pérez',
            'cliente_telefono' => '310 555 1234',
            'cliente_correo' => 'laura@correo.co',
            'fecha_hora' => $this->manana->format('Y-m-d H:i'),
        ], $cambios);
    }

    private function reservaConfirmada(array $cambios = []): Reserva
    {
        Sanctum::actingAs($this->mesero);
        $id = $this->postJson('/api/reservas', $this->datos($cambios))->assertCreated()->json('id');
        $this->postJson("/api/reservas/{$id}/deposito/manual", ['metodo' => 'efectivo'])->assertOk();

        return Reserva::findOrFail($id);
    }

    public function test_RF_M05_013_el_mesero_registra_una_reserva_por_telefono_y_queda_a_su_nombre(): void
    {
        Sanctum::actingAs($this->mesero);

        $id = $this->postJson('/api/reservas', $this->datos())->assertCreated()->json('id');

        $reserva = Reserva::findOrFail($id);
        $this->assertSame('mesero', $reserva->creado_por);
        $this->assertSame($this->mesero->id, $reserva->registrada_por);
        $this->assertSame('pendiente_pago', $reserva->estado);
        $this->assertSame(1, Auditoria::where('accion', 'crear_reserva_manual')->count());
    }

    public function test_RN_M05_06_el_administrador_tambien_registra_y_el_cliente_web_queda_como_tal(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/reservas', $this->datos())->assertCreated()->json('id');
        $this->assertSame('administrador', Reserva::findOrFail($id)->creado_por);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->flushHeaders();
        $idWeb = $this->postJson('/api/reservas', $this->datos(['mesa_id' => $this->otraMesa->id]))->assertCreated()->json('id');
        $web = Reserva::findOrFail($idWeb);
        $this->assertSame('cliente_web', $web->creado_por);
        $this->assertNull($web->registrada_por);
    }

    public function test_una_mesa_desactivada_no_recibe_reservas(): void
    {
        $this->mesa->update(['activa' => false]);
        Sanctum::actingAs($this->mesero);

        $this->postJson('/api/reservas', $this->datos())->assertStatus(422)->assertJsonValidationErrors(['mesa_id']);
    }

    public function test_dos_reservas_de_la_misma_mesa_no_se_cruzan(): void
    {
        $this->reservaConfirmada();

        // Una hora después: se cruza con la de las 8:00 p. m.
        $this->postJson('/api/reservas', $this->datos(['fecha_hora' => $this->manana->addHour()->format('Y-m-d H:i')]))
            ->assertStatus(422);
        // Dos horas y media después, u otra mesa a la misma hora: sí.
        $this->postJson('/api/reservas', $this->datos(['fecha_hora' => $this->manana->addMinutes(150)->format('Y-m-d H:i')]))
            ->assertCreated();
        $this->postJson('/api/reservas', $this->datos(['mesa_id' => $this->otraMesa->id]))->assertCreated();
    }

    public function test_una_reserva_web_sin_pagar_no_aparta_la_mesa_y_la_primera_que_paga_se_queda_con_ella(): void
    {
        $primera = $this->postJson('/api/reservas', $this->datos())->assertCreated()->json('id');
        $segunda = $this->postJson('/api/reservas', $this->datos(['cliente_correo' => 'otro@correo.co']))->assertCreated()->json('id');

        $this->postJson("/api/reservas/{$segunda}/deposito/en-linea", ['pasarela' => 'nequi'])->assertOk();
        $this->postJson("/api/reservas/{$primera}/deposito/en-linea", ['pasarela' => 'bancolombia'])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/reservas/{$segunda}/deposito/en-linea/confirmar")->assertOk();
        $this->postJson("/api/reservas/{$primera}/deposito/en-linea/confirmar")->assertStatus(422);

        $this->assertSame('pendiente_pago', Reserva::findOrFail($primera)->estado);
        $this->assertSame(1, Auditoria::where('accion', 'confirmar_deposito_reserva')->count());
    }

    public function test_RN_M05_16_el_deposito_manual_es_presencial_y_queda_auditado(): void
    {
        Sanctum::actingAs($this->mesero);
        $id = $this->postJson('/api/reservas', $this->datos())->assertCreated()->json('id');

        $this->postJson("/api/reservas/{$id}/deposito/manual", ['metodo' => 'en_linea'])->assertStatus(422);
        $this->postJson("/api/reservas/{$id}/deposito/manual", ['metodo' => 'transferencia'])
            ->assertOk()->assertJsonPath('estado', 'confirmada');

        $registro = Auditoria::where('accion', 'registrar_deposito_reserva')->sole();
        $this->assertSame($this->mesero->id, $registro->user_id);
        $this->assertSame('transferencia', $registro->detalles['metodo']);
    }

    public function test_mesas_disponibles_quita_las_reservadas_a_esa_hora_y_las_inactivas(): void
    {
        $this->reservaConfirmada();
        Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-3', 'estado' => 'libre', 'activa' => false]);

        $nombres = collect($this->getJson('/api/mesas/disponibles?fecha_hora='.urlencode($this->manana->addMinutes(30)->format('Y-m-d H:i')))
            ->assertOk()->json())->pluck('nombre')->all();
        $this->assertSame(['Mesa 2'], $nombres);

        $libresMasTarde = $this->getJson('/api/mesas/disponibles?fecha_hora='.urlencode($this->manana->addHours(3)->format('Y-m-d H:i')))->json();
        $this->assertCount(2, $libresMasTarde);
    }

    public function test_proximas_lista_las_pendientes_y_confirmadas_de_hoy_en_adelante_en_orden(): void
    {
        $lejana = $this->reservaConfirmada(['fecha_hora' => $this->manana->addDays(3)->format('Y-m-d H:i')]);
        $cercana = $this->reservaConfirmada();
        $cancelada = $this->reservaConfirmada(['mesa_id' => $this->otraMesa->id]);
        $this->postJson("/api/reservas/{$cancelada->id}/cancelar")->assertOk();

        $ids = array_column($this->getJson('/api/reservas?proximas=1')->assertOk()->json('data'), 'id');

        $this->assertSame([$cercana->id, $lejana->id], $ids);
    }

    public function test_RF_M05_015_el_mesero_confirma_la_llegada_y_la_mesa_queda_ocupada(): void
    {
        $reserva = $this->reservaConfirmada();
        $this->assertSame('reservada', $this->mesa->fresh()->estado);

        $this->postJson("/api/reservas/{$reserva->id}/completar")->assertOk()->assertJsonPath('estado', 'completada');

        $mesa = $this->mesa->fresh();
        $this->assertSame('ocupada', $mesa->estado);
        $this->assertNotNull($mesa->ocupada_desde, 'La llegada abre la cuenta de la mesa.');
    }
}
