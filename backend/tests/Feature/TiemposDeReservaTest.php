<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\User;
use App\Support\Reservas\PoliticaReservas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 4, tarea 2 (12/10/2026): tolerancia, recordatorio y ventana de mesa Reservada,
 * configurables por el Administrador. RF-M05-017, 026, RN-M05-07, 08, 13; decisión D6.
 */
class TiemposDeReservaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'valor_domicilio' => 2000, 'deposito_reserva' => 20000,
            'reserva_tolerancia_minutos' => 15, 'reserva_recordatorio_minutos' => 120, 'reserva_ventana_reservada_minutos' => 60,
        ], $cambios);
    }

    public function test_D6_valores_iniciales_15_min_2_h_y_1_h_visibles_para_todos(): void
    {
        $this->getJson('/api/configuracion')->assertOk()
            ->assertJsonPath('reserva_tolerancia_minutos', 15)
            ->assertJsonPath('reserva_recordatorio_minutos', 120)
            ->assertJsonPath('reserva_ventana_reservada_minutos', 60);

        $this->assertSame(15, PoliticaReservas::toleranciaMinutos());
        $this->assertSame(120, PoliticaReservas::recordatorioMinutos());
        $this->assertSame(60, PoliticaReservas::ventanaReservadaMinutos());
    }

    public function test_RF_M05_017_y_026_el_administrador_los_cambia_y_queda_auditado(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/configuraciones', $this->datos(['reserva_tolerancia_minutos' => 20, 'reserva_recordatorio_minutos' => 180]))->assertOk();

        $this->assertSame(20, PoliticaReservas::toleranciaMinutos());
        $this->assertSame(180, PoliticaReservas::recordatorioMinutos());
        $this->getJson('/api/configuracion')->assertJsonPath('reserva_tolerancia_minutos', 20);

        $cambios = Auditoria::where('accion', 'editar_configuracion')->latest('id')->first()->detalles['cambios'];
        $this->assertSame('20', $cambios['reserva_tolerancia_minutos']['despues']);
        $this->assertSame('180', $cambios['reserva_recordatorio_minutos']['despues']);
    }

    public function test_rechaza_valores_fuera_de_rango_o_no_enteros(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson('/api/configuraciones', $this->datos(['reserva_tolerancia_minutos' => 0]))
            ->assertStatus(422)->assertJsonPath('errors.reserva_tolerancia_minutos.0', 'La tolerancia debe ser de al menos 5 minutos.');
        $this->putJson('/api/configuraciones', $this->datos(['reserva_recordatorio_minutos' => 3000]))
            ->assertStatus(422)->assertJsonValidationErrors('reserva_recordatorio_minutos');
        $this->putJson('/api/configuraciones', $this->datos(['reserva_ventana_reservada_minutos' => 30.5]))
            ->assertStatus(422)->assertJsonValidationErrors('reserva_ventana_reservada_minutos');

        $this->assertSame(15, PoliticaReservas::toleranciaMinutos(), 'nada se guardó');
    }

    public function test_quien_solo_envia_domicilio_y_deposito_no_borra_los_tiempos(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/api/configuraciones', $this->datos(['reserva_tolerancia_minutos' => 25]))->assertOk();

        $this->putJson('/api/configuraciones', ['valor_domicilio' => 3000, 'deposito_reserva' => 20000])->assertOk();

        $this->assertSame(25, PoliticaReservas::toleranciaMinutos());
    }

    public function test_solo_el_administrador_cambia_los_tiempos(): void
    {
        $mesero = User::create(['role' => 'mesero', 'nombre' => 'M', 'email' => 'm@test.co', 'password' => bcrypt('x12345678'), 'activo' => true]);
        Sanctum::actingAs($mesero);

        $this->putJson('/api/configuraciones', $this->datos(['reserva_tolerancia_minutos' => 60]))->assertForbidden();
        $this->assertSame(15, PoliticaReservas::toleranciaMinutos());
    }
}
