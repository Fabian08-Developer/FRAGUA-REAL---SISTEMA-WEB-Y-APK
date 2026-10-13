<?php

namespace Tests\Feature;

use App\Jobs\LatidoCola;
use App\Models\User;
use App\Services\EstadoSistemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 4, tarea 1 (12/10/2026): cola de trabajos y programador de tareas en marcha, con su
 * estado visible para el Administrador. Base de RF-M05-016/024 y de los avisos de la Fase 4.
 */
class ColaYProgramadorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    public function test_existen_las_tablas_de_cola_cache_y_sesiones(): void
    {
        foreach (['jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions'] as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}");
        }
    }

    public function test_el_latido_del_programador_encola_una_prueba_para_la_cola(): void
    {
        Queue::fake();

        $this->artisan('fragua:latido')->assertSuccessful();

        $this->assertNotNull(Cache::get(EstadoSistemaService::CLAVE_PROGRAMADOR));
        Queue::assertPushed(LatidoCola::class);
        $this->assertNull(Cache::get(EstadoSistemaService::CLAVE_COLA), 'la cola aún no lo procesó');
    }

    public function test_cuando_la_cola_procesa_el_trabajo_queda_su_latido(): void
    {
        config(['queue.default' => 'database']);
        $this->artisan('fragua:latido')->assertSuccessful();
        $this->assertSame(1, DB::table('jobs')->count(), 'el trabajo quedó guardado en la tabla jobs');

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertNotNull(Cache::get(EstadoSistemaService::CLAVE_COLA));
    }

    public function test_el_administrador_ve_si_cada_proceso_esta_activo(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'log']);
        Sanctum::actingAs($this->admin);

        $sinNada = $this->getJson('/api/sistema/estado')->assertOk();
        $sinNada->assertJsonPath('programador.activo', false)
            ->assertJsonPath('programador.ultimo_latido', null)
            ->assertJsonPath('cola.activa', false)
            ->assertJsonPath('cola.conexion', 'database')
            ->assertJsonPath('cola.pendientes', 0)
            ->assertJsonPath('cola.fallidos', 0)
            ->assertJsonPath('correo.envia_de_verdad', false);

        Cache::forever(EstadoSistemaService::CLAVE_PROGRAMADOR, now()->subMinute()->toIso8601String());
        Cache::forever(EstadoSistemaService::CLAVE_COLA, now()->subMinutes(10)->toIso8601String());
        DB::table('failed_jobs')->insert(['uuid' => 'x-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Error de prueba', 'failed_at' => now()]);

        $this->getJson('/api/sistema/estado')
            ->assertJsonPath('programador.activo', true)
            ->assertJsonPath('cola.activa', false) // su último latido tiene 10 minutos
            ->assertJsonPath('cola.fallidos', 1);

        // Con la cola "sync" no hace falta trabajador: los trabajos se hacen en el momento.
        config(['queue.default' => 'sync']);
        $this->getJson('/api/sistema/estado')->assertJsonPath('cola.activa', true)->assertJsonPath('cola.sincronica', true);
    }

    public function test_solo_el_administrador_ve_el_estado(): void
    {
        $this->getJson('/api/sistema/estado')->assertUnauthorized();
        $mesero = User::create(['role' => 'mesero', 'nombre' => 'M', 'email' => 'm@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        Sanctum::actingAs($mesero);
        $this->getJson('/api/sistema/estado')->assertForbidden();
    }

    public function test_las_tareas_quedan_programadas(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('fragua:latido')->expectsOutputToContain('sesiones:limpiar')->assertSuccessful();
    }
}
