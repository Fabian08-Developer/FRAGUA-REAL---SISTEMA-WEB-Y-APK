<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 7, tarea 4 (13/10/2026): el Mesero ve el estado de la caja de hoy en Cobrar (RF-M06-023,
 * HU-M06-009). Antes solo se enteraba de que estaba cerrada cuando el cobro le fallaba.
 */
class CajaEstadoMeseroTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00', 'America/Bogota'));
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario', 'email' => 'mario@test.co', 'password' => 'secreto123', 'activo' => true]);
    }

    public function test_el_mesero_ve_si_la_caja_esta_sin_abrir_abierta_o_cerrada(): void
    {
        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/caja/estado')->assertOk()
            ->assertJsonPath('fecha', '2026-10-13')
            ->assertJsonPath('estado', 'sin_abrir')
            ->assertJsonPath('efectivo_esperado', null);

        // El Administrador abre la caja y registra un gasto en efectivo.
        Sanctum::actingAs($this->admin);
        $turno = $this->postJson('/api/caja/abrir', ['base_inicial' => 100000])->assertCreated()->json('id');
        $this->postJson('/api/compras-gastos', [
            'tipo' => 'gasto', 'categoria' => 'Transporte', 'descripcion' => 'Taxi', 'valor' => 10000,
            'metodo_pago' => 'efectivo', 'fecha' => '2026-10-13',
        ])->assertCreated();

        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/caja/estado')->assertOk()
            ->assertJsonPath('estado', 'abierta')
            ->assertJsonPath('abierta_por', 'Ana Admin')
            ->assertJsonPath('efectivo_esperado', fn ($v) => (float) $v === 90000.0);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/caja/turnos/{$turno}/cerrar", ['efectivo_contado' => 90000])->assertOk();

        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/caja/estado')->assertOk()
            ->assertJsonPath('estado', 'cerrada')
            ->assertJsonPath('cerrada_por', 'Ana Admin')
            ->assertJsonPath('efectivo_esperado', null);

        // El detalle del turno sigue siendo del Administrador.
        $this->getJson('/api/caja/turno-actual')->assertForbidden();
    }

    public function test_el_cliente_no_ve_la_caja(): void
    {
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Luz', 'email' => 'luz@test.co', 'password' => 'secreto123', 'activo' => true]);
        Sanctum::actingAs($cliente);
        $this->getJson('/api/caja/estado')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/caja/estado')->assertUnauthorized();
    }
}
