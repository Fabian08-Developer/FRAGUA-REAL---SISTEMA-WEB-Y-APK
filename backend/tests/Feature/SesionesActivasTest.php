<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Fase 3, tarea 4 (12/10/2026): sesiones abiertas con su origen y su vencimiento.
 * RF-M01-010, 023, 024, HU-M01-010, RNF-M01-005, RNF-M01-015, CA-M01-010.
 */
class SesionesActivasTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36';
    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    private function entrar(string $correo, string $userAgent, string $ip): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('User-Agent', $userAgent)
            ->postJson('/api/auth/login', ['login' => $correo, 'password' => 'secreto123'])
            ->assertOk()->json('token');
    }

    private function como(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_RF_M01_024_cada_sesion_guarda_su_origen_y_la_persona_ve_las_suyas(): void
    {
        $celular = $this->entrar('mesero@test.co', self::CHROME_ANDROID, '190.85.10.4');
        $this->entrar('mesero@test.co', self::SAFARI_IPHONE, '181.50.2.9');

        $sesion = PersonalAccessToken::where('ip', '190.85.10.4')->sole();
        $this->assertSame(self::CHROME_ANDROID, $sesion->user_agent);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $sesion->expires_at->timestamp, 5);
        $this->assertSame($sesion->id, Auditoria::where('accion', 'inicio_sesion')->first()->detalles['sesion_id']);

        $lista = $this->como($celular)->getJson('/api/auth/sesiones')->assertOk();
        $this->assertCount(2, $lista->json('sesiones'));
        $actual = collect($lista->json('sesiones'))->firstWhere('actual', true);
        $this->assertSame('Chrome en Android', $actual['dispositivo']);
        $this->assertSame('190.85.10.4', $actual['ip']);
        $this->assertNotNull($actual['vence_at']);
        $this->assertSame(['inactividad_minutos' => 10080, 'duracion_maxima_minutos' => 43200], $lista->json('politica'));
        $this->assertContains('Safari en iOS', array_column($lista->json('sesiones'), 'dispositivo'));
    }

    public function test_RNF_M01_005_una_sesion_sin_uso_por_7_dias_se_cierra_sola(): void
    {
        $token = $this->entrar('mesero@test.co', self::CHROME_ANDROID, '190.85.10.4');

        $this->travel(6)->days();
        $this->como($token)->getJson('/api/auth/me')->assertOk(); // la usa: se renueva la inactividad

        $this->travel(6)->days();
        $this->como($token)->getJson('/api/auth/me')->assertOk();

        $this->travel(7)->days();
        $this->travel(2)->minutes();
        $this->como($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_RNF_M01_005_aunque_se_use_a_diario_vence_a_los_30_dias(): void
    {
        $token = $this->entrar('mesero@test.co', self::CHROME_ANDROID, '190.85.10.4');

        for ($dia = 1; $dia <= 29; $dia++) {
            $this->travel(1)->days();
            $this->como($token)->getJson('/api/auth/me')->assertOk();
        }

        $this->travel(1)->days();
        $this->travel(2)->minutes();
        $this->como($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_la_politica_se_cambia_desde_la_configuracion(): void
    {
        config(['fragua_real.sesiones.inactividad_minutos' => 60]);
        $token = $this->entrar('mesero@test.co', self::CHROME_ANDROID, '190.85.10.4');

        $this->travel(61)->minutes();
        $this->como($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_HU_M01_010_cerrar_una_sesion_propia_o_todas_las_demas(): void
    {
        $celular = $this->entrar('mesero@test.co', self::CHROME_ANDROID, '190.85.10.4');
        $tablet = $this->entrar('mesero@test.co', self::SAFARI_IPHONE, '181.50.2.9');
        $pc = $this->entrar('mesero@test.co', 'Mozilla/5.0 (Windows NT 10.0) Chrome/129.0 Safari/537.36', '181.50.2.10');
        $idTablet = PersonalAccessToken::where('ip', '181.50.2.9')->value('id');

        $this->como($celular)->deleteJson("/api/auth/sesiones/{$idTablet}")->assertOk()->assertJsonPath('era_la_actual', false);
        $this->como($tablet)->getJson('/api/auth/me')->assertUnauthorized();
        $registro = Auditoria::where('accion', 'cerrar_sesion_dispositivo')->sole();
        $this->assertSame('Safari en iOS', $registro->detalles['dispositivo']);

        // No puede cerrar sesiones de otra persona.
        $idAdmin = PersonalAccessToken::where('tokenable_id', $this->admin->id)->value('id')
            ?? $this->admin->createToken('acceso')->accessToken->id;
        $this->como($celular)->deleteJson("/api/auth/sesiones/{$idAdmin}")->assertNotFound();
        $this->assertNotNull(PersonalAccessToken::find($idAdmin));

        $this->como($celular)->postJson('/api/auth/sesiones/cerrar-otras')->assertOk()->assertJsonPath('cerradas', 1);
        $this->como($pc)->getJson('/api/auth/me')->assertUnauthorized();
        $this->como($celular)->getJson('/api/auth/me')->assertOk();
        $this->assertSame(1, Auditoria::where('accion', 'cerrar_otras_sesiones')->sole()->detalles['sesiones_cerradas']);
    }

    public function test_RF_M01_010_el_administrador_ve_y_cierra_una_sesion_de_otra_cuenta(): void
    {
        $celular = $this->entrar('mesero@test.co', self::CHROME_ANDROID, '190.85.10.4');
        $tablet = $this->entrar('mesero@test.co', self::SAFARI_IPHONE, '181.50.2.9');
        $admin = $this->entrar('admin@test.co', self::CHROME_ANDROID, '200.1.1.1');

        $usuarios = $this->como($admin)->getJson('/api/usuarios')->assertOk()->json('data');
        $this->assertSame(2, collect($usuarios)->firstWhere('id', $this->mesero->id)['sesiones_activas']);

        $sesiones = $this->como($admin)->getJson("/api/usuarios/{$this->mesero->id}/sesiones")->assertOk()->json('sesiones');
        $this->assertCount(2, $sesiones);
        $this->assertFalse(collect($sesiones)->contains('actual', true));

        $idCelular = PersonalAccessToken::where('ip', '190.85.10.4')->value('id');
        $this->como($admin)->deleteJson("/api/usuarios/{$this->mesero->id}/sesiones/{$idCelular}")->assertOk();
        $this->como($celular)->getJson('/api/auth/me')->assertUnauthorized();
        $this->como($tablet)->getJson('/api/auth/me')->assertOk();

        $registro = Auditoria::where('accion', 'revocar_sesion')->sole();
        $this->assertSame($this->admin->id, $registro->user_id);
        $this->assertSame('190.85.10.4', $registro->detalles['ip']);

        // Una sesión de otra cuenta no se cierra por esta ruta; las propias se cierran desde el perfil.
        $idAdmin = PersonalAccessToken::where('ip', '200.1.1.1')->value('id');
        $this->como($admin)->deleteJson("/api/usuarios/{$this->mesero->id}/sesiones/{$idAdmin}")->assertNotFound();
        $this->como($admin)->deleteJson("/api/usuarios/{$this->admin->id}/sesiones/{$idAdmin}")->assertStatus(422);

        // El Mesero no ve sesiones ajenas.
        $this->como($tablet)->getJson("/api/usuarios/{$this->admin->id}/sesiones")->assertForbidden();
    }

    public function test_sesiones_limpiar_borra_solo_las_vencidas_o_abandonadas(): void
    {
        $vigente = $this->mesero->createToken('vigente', ['*'], now()->addDays(10))->accessToken;
        $vencida = $this->mesero->createToken('vencida', ['*'], now()->subMinute())->accessToken;
        $abandonada = $this->mesero->createToken('abandonada', ['*'], now()->addDays(10))->accessToken;
        $abandonada->forceFill(['last_used_at' => now()->subDays(8)])->save();
        $vieja = $this->mesero->createToken('vieja')->accessToken;
        $vieja->forceFill(['created_at' => now()->subDays(9)])->save();

        $this->artisan('sesiones:limpiar')->expectsOutput('Sesiones vencidas borradas: 3')->assertSuccessful();

        $this->assertSame([$vigente->id], PersonalAccessToken::pluck('id')->all());
    }
}
