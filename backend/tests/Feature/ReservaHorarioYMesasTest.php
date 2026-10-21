<?php

namespace Tests\Feature;

use App\Models\Mesa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Correcciones del 19/10/2026 (decisión del negocio):
 *  - Las reservas son de 4:00 p. m. a 11:00 p. m. (hora de Colombia) y con al menos 3 horas de
 *    anticipación, en la web y por teléfono (PoliticaReservas, StoreReservaRequest).
 *  - El cliente puede dejar una descripción en su reserva.
 *  - El Mesero ya no ve las mesas dadas de baja (RF-M05-004); el Administrador sí, para reactivarlas.
 */
class ReservaHorarioYMesasTest extends TestCase
{
    use RefreshDatabase;

    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes 20/10/2026, 2:00 p. m. en Colombia.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 14:00', 'America/Bogota'));
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
    }

    private function reservar(string $fechaHora, array $cambios = [])
    {
        return $this->postJson('/api/reservas', array_merge([
            'mesa_id' => $this->mesa->id,
            'cliente_nombre' => 'Laura Pérez',
            'cliente_telefono' => '3105551234',
            'cliente_correo' => 'laura@correo.co',
            'fecha_hora' => $fechaHora,
        ], $cambios));
    }

    public function test_solo_se_reserva_entre_las_4_pm_y_las_11_pm(): void
    {
        $this->reservar('2026-10-21 15:30')->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_hora' => 'Las reservas son de 4:00 p. m. a 11:00 p. m.']);
        $this->reservar('2026-10-21 23:30')->assertStatus(422)->assertJsonValidationErrors(['fecha_hora']);
        $this->reservar('2026-10-21 12:00')->assertStatus(422)->assertJsonValidationErrors(['fecha_hora']);

        $this->reservar('2026-10-21 16:00')->assertCreated();
        $this->reservar('2026-10-22 23:00')->assertCreated();
    }

    public function test_la_reserva_se_hace_con_al_menos_3_horas_de_anticipacion(): void
    {
        // A las 2:00 p. m.: a las 4:30 p. m. faltan 2 h 30 min.
        $this->reservar('2026-10-20 16:30')->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_hora' => 'La reserva se hace con al menos 3 horas de anticipación.']);
        // A las 5:00 p. m. faltan exactamente 3 horas.
        $this->reservar('2026-10-20 17:00')->assertCreated();
    }

    public function test_el_personal_tambien_sigue_el_horario_y_la_anticipacion(): void
    {
        Sanctum::actingAs(User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]));

        $this->reservar('2026-10-20 16:00')->assertStatus(422)->assertJsonValidationErrors(['fecha_hora']);
        $this->reservar('2026-10-21 10:00')->assertStatus(422)->assertJsonValidationErrors(['fecha_hora']);
        $this->reservar('2026-10-21 19:00')->assertCreated()->assertJsonPath('creado_por', 'mesero');
    }

    public function test_el_horario_se_publica_para_la_pantalla_reservar(): void
    {
        $this->getJson('/api/configuracion')->assertOk()
            ->assertJsonPath('reserva_hora_inicio', '16:00')
            ->assertJsonPath('reserva_hora_fin', '23:00')
            ->assertJsonPath('reserva_anticipacion_minutos', 180);
    }

    public function test_la_descripcion_del_cliente_queda_en_la_reserva(): void
    {
        $id = $this->reservar('2026-10-21 19:00', ['descripcion' => '  Es un cumpleaños; silla para bebé, por favor.  '])
            ->assertCreated()
            ->assertJsonPath('descripcion', 'Es un cumpleaños; silla para bebé, por favor.')
            ->json('id');

        $this->reservar('2026-10-21 21:30', ['descripcion' => str_repeat('a', 501)])
            ->assertStatus(422)->assertJsonValidationErrors(['descripcion']);
        // Sin descripción también se puede.
        $this->reservar('2026-10-22 19:00')->assertCreated()->assertJsonPath('descripcion', null);

        Sanctum::actingAs(User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]));
        $this->assertSame('Es un cumpleaños; silla para bebé, por favor.', \App\Models\Reserva::find($id)->descripcion);
    }

    public function test_el_mesero_no_ve_las_mesas_dadas_de_baja_y_el_administrador_si(): void
    {
        Mesa::create(['nombre' => 'Mesa 9', 'qr_code' => 'qr-9', 'estado' => 'libre', 'activa' => false]);

        Sanctum::actingAs(User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]));
        $this->assertSame(['Mesa 1'], collect($this->getJson('/api/mesas')->assertOk()->json())->pluck('nombre')->all());

        Sanctum::actingAs(User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]));
        $this->assertEqualsCanonicalizing(['Mesa 1', 'Mesa 9'], collect($this->getJson('/api/mesas')->assertOk()->json())->pluck('nombre')->all());
    }
}
