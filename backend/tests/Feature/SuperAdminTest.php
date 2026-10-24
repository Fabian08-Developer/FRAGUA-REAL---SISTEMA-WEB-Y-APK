<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Mesa;
use App\Models\User;
use App\Services\NegocioService;
use App\Services\RevisionProduccionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M09 (19/10/2026): rol SuperAdmin, el dueño del sistema. Puede todo lo del Administrador y,
 * además, es el único que gestiona las cuentas de Administrador y cambia el nombre y el NIT del
 * negocio. Su cuenta se crea en el servidor (php artisan fragua:crear-superadmin).
 */
class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;
    private User $otroAdmin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::create(['role' => 'superadmin', 'nombre' => 'Sara Super', 'email' => 'super@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->otroAdmin = User::create(['role' => 'administrador', 'nombre' => 'Beto Admin', 'email' => 'beto@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    private function datosDe(User $u, array $cambios = []): array
    {
        return array_merge(['nombre' => $u->nombre, 'email' => $u->email, 'role' => $u->role], $cambios);
    }

    public function test_el_superadmin_entra_a_todo_lo_del_administrador(): void
    {
        Sanctum::actingAs($this->super);
        foreach (['/api/usuarios', '/api/configuraciones', '/api/auditorias', '/api/caja/turnos', '/api/reportes/ventas-totales', '/api/sistema/revision-produccion'] as $ruta) {
            $this->getJson($ruta)->assertOk();
        }
        // Y lo que comparten Administrador y Mesero.
        $this->getJson('/api/comandas/pendientes')->assertOk();
        $this->assertTrue($this->super->esAdministrador());
        $this->assertTrue($this->super->esSuperAdmin());
        $this->assertFalse($this->admin->esSuperAdmin());
    }

    public function test_solo_el_superadmin_crea_cuentas_de_administrador(): void
    {
        $nueva = ['role' => 'administrador', 'nombre' => 'Caro', 'email' => 'caro@test.co', 'password' => 'Secreto123', 'password_confirmation' => 'Secreto123'];

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/usuarios', $nueva)->assertForbidden()
            ->assertJsonPath('message', 'Solo el SuperAdmin puede gestionar las cuentas de Administrador.');
        // El Administrador sigue creando Meseros.
        $this->postJson('/api/usuarios', array_merge($nueva, ['role' => 'mesero', 'email' => 'mesero2@test.co']))->assertCreated();

        Sanctum::actingAs($this->super);
        $this->postJson('/api/usuarios', $nueva)->assertCreated()->assertJsonPath('role', 'administrador');
        // Nadie crea un SuperAdmin desde la aplicación.
        $this->postJson('/api/usuarios', array_merge($nueva, ['role' => 'superadmin', 'email' => 'otro@test.co']))->assertStatus(422);
    }

    public function test_el_administrador_no_toca_otras_cuentas_de_administrador_ni_la_del_superadmin(): void
    {
        Sanctum::actingAs($this->admin);
        foreach ([$this->otroAdmin, $this->super] as $cuenta) {
            $this->putJson("/api/usuarios/{$cuenta->id}", $this->datosDe($cuenta, ['nombre' => 'Cambiado']))->assertForbidden();
            $this->postJson("/api/usuarios/{$cuenta->id}/desactivar")->assertForbidden();
            $this->postJson("/api/usuarios/{$cuenta->id}/revocar-sesiones")->assertForbidden();
            $this->getJson("/api/usuarios/{$cuenta->id}/sesiones")->assertForbidden();
        }
        $this->assertTrue($this->otroAdmin->fresh()->activo);
        $this->assertSame('Beto Admin', $this->otroAdmin->fresh()->nombre);

        // Sí gestiona Meseros, y sus propios datos.
        $this->postJson("/api/usuarios/{$this->mesero->id}/desactivar")->assertOk();
        $this->putJson("/api/usuarios/{$this->admin->id}", $this->datosDe($this->admin, ['nombre' => 'Ana María']))->assertOk();
    }

    public function test_el_superadmin_gestiona_administradores_pero_no_otra_cuenta_superadmin(): void
    {
        Sanctum::actingAs($this->super);
        $this->putJson("/api/usuarios/{$this->otroAdmin->id}", $this->datosDe($this->otroAdmin, ['role' => 'mesero']))->assertOk()
            ->assertJsonPath('usuario.role', 'mesero');
        $this->postJson("/api/usuarios/{$this->admin->id}/desactivar")->assertOk();
        $this->assertFalse($this->admin->fresh()->activo);
        $this->assertTrue(Auditoria::where('accion', 'desactivar_usuario')->where('user_id', $this->super->id)->exists());

        $otroSuper = User::create(['role' => 'superadmin', 'nombre' => 'Otro Super', 'email' => 'super2@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->postJson("/api/usuarios/{$otroSuper->id}/desactivar")->assertForbidden();
        // Ni a sí mismo se cambia el rol desde Usuarios.
        $this->putJson("/api/usuarios/{$this->super->id}", $this->datosDe($this->super, ['role' => 'administrador']))->assertStatus(422);
    }

    public function test_el_listado_dice_que_cuentas_puede_gestionar_quien_lo_mira(): void
    {
        Sanctum::actingAs($this->admin);
        $filas = collect($this->getJson('/api/usuarios')->assertOk()->json('data'))->keyBy('email');
        $this->assertFalse($filas['super@test.co']['gestionable']);
        $this->assertFalse($filas['beto@test.co']['gestionable']);
        $this->assertTrue($filas['mesero@test.co']['gestionable']);
        $this->assertTrue($filas['admin@test.co']['gestionable']);

        Sanctum::actingAs($this->super);
        $filas = collect($this->getJson('/api/usuarios')->assertOk()->json('data'))->keyBy('email');
        $this->assertTrue($filas['beto@test.co']['gestionable']);
    }

    public function test_el_comando_crea_el_superadmin_o_vuelve_superadmin_una_cuenta(): void
    {
        $this->artisan('fragua:crear-superadmin', ['correo' => 'duena@test.co', '--nombre' => 'Dueña'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con mayúscula, minúscula y número)', 'Clave-Duena-2026')
            ->expectsQuestion('Repite la contraseña', 'Clave-Duena-2026')
            ->expectsOutput('Cuenta SuperAdmin creada: duena@test.co')
            ->assertExitCode(0);
        $duena = User::where('email', 'duena@test.co')->sole();
        $this->assertSame('superadmin', $duena->role);
        $this->assertTrue(Hash::check('Clave-Duena-2026', $duena->password));

        // Una cuenta que ya existe: se confirma y conserva su contraseña.
        $this->admin->createToken('tablet');
        $this->artisan('fragua:crear-superadmin', ['correo' => 'admin@test.co'])
            ->expectsConfirmation('Ya existe la cuenta admin@test.co (administrador). ¿La vuelvo SuperAdmin?', 'yes')
            ->expectsOutput('Ahora es SuperAdmin: admin@test.co')
            ->assertExitCode(0);
        $this->assertSame('superadmin', $this->admin->fresh()->role);
        $this->assertTrue(Hash::check('secreto123', $this->admin->fresh()->password));
        $this->assertSame(0, $this->admin->tokens()->count(), 'con otro rol, sus sesiones se cierran');
        $this->assertEqualsCanonicalizing(['crear_superadmin', 'asignar_superadmin'],
            Auditoria::whereIn('accion', ['crear_superadmin', 'asignar_superadmin'])->pluck('accion')->all());

        // Contraseñas que no coinciden: no cambia nada.
        $this->artisan('fragua:crear-superadmin', ['correo' => 'nadie@test.co'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con mayúscula, minúscula y número)', 'Clave-1234')
            ->expectsQuestion('Repite la contraseña', 'Otra-1234')
            ->assertExitCode(1);
        $this->assertNull(User::where('email', 'nadie@test.co')->first());
    }

    public function test_solo_el_superadmin_cambia_nombre_y_nit_del_negocio(): void
    {
        $datos = ['direccion' => 'Calle 1', 'telefono' => '3105551234', 'nombre' => 'Fragua Real Burger & Grill', 'nit' => '900.123.456-7'];

        Sanctum::actingAs($this->admin);
        $this->putJson('/api/negocio', $datos)->assertForbidden();
        // Sin nombre ni NIT, el Administrador sí guarda dirección y teléfono.
        $this->putJson('/api/negocio', ['direccion' => 'Calle 1', 'telefono' => '3105551234'])->assertOk();

        Sanctum::actingAs($this->super);
        $this->putJson('/api/negocio', $datos)->assertOk()
            ->assertJsonPath('nombre', 'Fragua Real Burger & Grill')
            ->assertJsonPath('nit', '900123456-7'); // se guarda sin puntos (22/10/2026)
        $this->assertSame('900123456-7', app(NegocioService::class)->datos()['nit']);
        $this->putJson('/api/negocio', array_merge($datos, ['nit' => 'NIT-ABC']))->assertStatus(422)->assertJsonValidationErrors(['nit']);
    }

    public function test_la_revision_de_produccion_avisa_si_no_hay_superadmin(): void
    {
        $item = fn () => collect(app(RevisionProduccionService::class)->revisar())->firstWhere('clave', 'superadmin');
        $this->assertSame('ok', $item()['nivel']);

        $this->super->update(['activo' => false]);
        $this->assertSame('aviso', $item()['nivel']);
    }

    public function test_la_reserva_que_registra_el_superadmin_queda_como_del_administrador(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'America/Bogota'));
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        Sanctum::actingAs($this->super);

        $this->postJson('/api/reservas', [
            'mesa_id' => $mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234',
            'cliente_correo' => 'laura@correo.co', 'fecha_hora' => '2026-10-20 19:00',
        ])->assertCreated()->assertJsonPath('creado_por', 'administrador');
    }
}
