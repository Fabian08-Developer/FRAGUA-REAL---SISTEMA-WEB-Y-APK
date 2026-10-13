<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 3, tarea 2 (12/10/2026): el Administrador edita los datos de una cuenta y cambia su rol.
 * RF-M01-003, 005, 011, 021, 022, 023, HU-M01-003, 005, CA-M01-003.1/003.2, CA-M01-005.1/005.2.
 */
class EditarUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'telefono' => '3001112233', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'telefono' => '3001112233', 'role' => 'mesero',
        ], $cambios);
    }

    public function test_RF_M01_003_edita_nombre_correo_y_telefono_con_auditoria_de_lo_que_cambio(): void
    {
        $tokenMesero = $this->mesero->createToken('acceso')->plainTextToken;
        Sanctum::actingAs($this->admin);

        $respuesta = $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos([
            'nombre' => '  Mario Pérez ', 'email' => 'mario@test.co', 'telefono' => '',
        ]))->assertOk();

        $respuesta->assertJsonPath('usuario.nombre', 'Mario Pérez')
            ->assertJsonPath('usuario.email', 'mario@test.co')
            ->assertJsonPath('usuario.telefono', null)
            ->assertJsonPath('sesiones_cerradas', false);
        $this->assertSame(1, $this->mesero->tokens()->count(), 'Editar datos no cierra sus sesiones');
        $this->assertNotNull($tokenMesero);

        $registro = Auditoria::where('accion', 'editar_usuario')->sole();
        $this->assertSame($this->admin->id, $registro->user_id);
        $this->assertSame(['antes' => 'mesero@test.co', 'despues' => 'mario@test.co'], $registro->detalles['cambios']['email']);
        $this->assertSame(['antes' => '3001112233', 'despues' => null], $registro->detalles['cambios']['telefono']);
        $this->assertFalse(Auditoria::where('accion', 'cambiar_rol')->exists());

        // Guardar sin cambios no deja registros vacíos en la auditoría.
        $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos(['nombre' => 'Mario Pérez', 'email' => 'mario@test.co', 'telefono' => null]))->assertOk();
        $this->assertSame(1, Auditoria::where('accion', 'editar_usuario')->count());
    }

    public function test_CA_M01_003_2_valida_formato_y_correo_unico(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos(['email' => 'admin@test.co']))
            ->assertStatus(422)->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'Ese correo ya lo usa otra cuenta.');
        $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos(['email' => 'no-es-correo', 'nombre' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'nombre']);

        // Conservar su propio correo no choca consigo mismo.
        $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos(['nombre' => 'Otro nombre']))->assertOk();
    }

    public function test_CA_M01_003_1_la_contrasena_no_se_cambia_desde_aqui(): void
    {
        Sanctum::actingAs($this->admin);
        $hashAntes = $this->mesero->password;

        $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos(['password' => 'otraClave99', 'password_confirmation' => 'otraClave99', 'activo' => false]))
            ->assertOk();

        $this->mesero->refresh();
        $this->assertSame($hashAntes, $this->mesero->password);
        $this->assertTrue($this->mesero->activo, 'activar/desactivar tiene su propio botón y su propia auditoría');
    }

    public function test_RF_M01_005_cambiar_el_rol_cierra_sus_sesiones_y_queda_auditado(): void
    {
        $token = $this->mesero->createToken('acceso')->plainTextToken;
        $this->mesero->createToken('tablet');
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos(['role' => 'administrador']))
            ->assertOk()
            ->assertJsonPath('usuario.role', 'administrador')
            ->assertJsonPath('sesiones_cerradas', true);

        $this->assertSame(0, $this->mesero->tokens()->count());
        $registro = Auditoria::where('accion', 'cambiar_rol')->sole();
        $this->assertSame(['antes' => 'mesero', 'despues' => 'administrador'], $registro->detalles['cambios']['rol']);
        $this->assertSame(2, $registro->detalles['sesiones_cerradas']);

        // La sesión que tenía abierta ya no sirve: debe volver a entrar con su nuevo rol.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_CA_M01_005_1_solo_roles_del_catalogo(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['cocina', 'domiciliario', 'superadmin', ''] as $rol) {
            $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos(['role' => $rol]))
                ->assertStatus(422)->assertJsonValidationErrors('role');
        }
        $this->assertSame('mesero', $this->mesero->fresh()->role);

        // Un Cliente que entra a trabajar puede pasar a Mesero (y al revés).
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Carla', 'email' => 'carla@test.co', 'password' => bcrypt('x12345678'), 'activo' => true]);
        $this->putJson("/api/usuarios/{$cliente->id}", ['nombre' => 'Carla', 'email' => 'carla@test.co', 'role' => 'mesero'])
            ->assertOk()->assertJsonPath('usuario.role', 'mesero');
    }

    public function test_el_administrador_no_cambia_su_propio_rol_pero_si_sus_datos(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/usuarios/{$this->admin->id}", ['nombre' => 'Ana', 'email' => 'admin@test.co', 'role' => 'mesero'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes cambiar tu propio rol. Pídeselo a otro Administrador.');
        $this->assertSame('administrador', $this->admin->fresh()->role);
        $this->assertSame('Ana Admin', $this->admin->fresh()->nombre, 'nada se guarda si el cambio se rechaza');

        $this->putJson("/api/usuarios/{$this->admin->id}", ['nombre' => 'Ana María', 'email' => 'admin@test.co', 'role' => 'administrador'])
            ->assertOk()->assertJsonPath('usuario.nombre', 'Ana María');
    }

    public function test_CA_M01_005_2_solo_el_administrador_edita_usuarios(): void
    {
        $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos(['role' => 'administrador']))->assertUnauthorized();

        Sanctum::actingAs($this->mesero);
        $this->putJson("/api/usuarios/{$this->mesero->id}", $this->datos(['role' => 'administrador']))->assertForbidden();
        $this->assertSame('mesero', $this->mesero->fresh()->role);
    }
}
