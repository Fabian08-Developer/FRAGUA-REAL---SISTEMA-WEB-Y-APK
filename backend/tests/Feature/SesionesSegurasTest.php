<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 0, tarea 6 del plan de implementación: RF-M01-006, RF-M01-023, RN-USR-06,
 * RNF-M01-015 y límite de intentos en el acceso.
 */
class SesionesSegurasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => Hash::make('clave-admin'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => Hash::make('clave-mesero'), 'activo' => true]);
    }

    public function test_RF_M01_023_desactivar_una_cuenta_cierra_todas_sus_sesiones(): void
    {
        $this->mesero->createToken('celular');
        $this->mesero->createToken('tablet');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/usuarios/{$this->mesero->id}/desactivar")->assertOk();

        $this->assertSame(0, $this->mesero->tokens()->count());
    }

    public function test_RF_M01_006_una_cuenta_desactivada_no_usa_ni_las_rutas_de_perfil(): void
    {
        $token = $this->mesero->createToken('celular')->plainTextToken;
        $this->mesero->update(['activo' => false]);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/me')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->putJson('/api/perfil', ['nombre' => 'Otro'])->assertUnauthorized();
    }

    public function test_una_cuenta_activa_sigue_entrando_normal(): void
    {
        $token = $this->mesero->createToken('celular')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/me')->assertOk();
    }

    public function test_el_login_tiene_limite_de_intentos(): void
    {
        // Seguridad (21/10/2026, RN-USR-24): 5 intentos fallidos seguidos bloquean la cuenta 15 minutos,
        // y durante el bloqueo ni la contraseña correcta entra (SeguridadClavesTest cubre el resto).
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'mesero@test.co', 'password' => 'equivocada'])->assertStatus(422);
        }
        $this->postJson('/api/auth/login', ['email' => 'mesero@test.co', 'password' => 'equivocada'])->assertStatus(429);

        $this->postJson('/api/auth/login', ['email' => 'mesero@test.co', 'password' => 'clave-mesero'])->assertStatus(429);
    }
}
