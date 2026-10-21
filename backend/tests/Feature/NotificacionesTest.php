<?php

namespace Tests\Feature;

use App\Models\CajaTurno;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Notificacion;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\User;
use App\Services\CajaService;
use App\Services\EstadoSistemaService;
use App\Services\NotificacionService;
use App\Services\ReservaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * M11 (21/10/2026): notificaciones del sistema para el personal (la campana). Cada aviso le llega a
 * quien le toca según su rol y sus menús, nunca a quien hizo la acción, y no se repite.
 */
class NotificacionesTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;
    private User $adminSinPedidos;
    private User $mesero;
    private User $otroMesero;
    private User $soporte;
    private User $cliente;
    private Mesa $mesa;
    private Producto $hamburguesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 18:00:00', 'America/Bogota'));
        $crear = fn (string $rol, string $correo, array $extra = []) => User::create($extra + ['role' => $rol, 'nombre' => ucfirst(strtok($correo, '@')), 'email' => $correo, 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->super = $crear('superadmin', 'super@test.co');
        $this->admin = $crear('administrador', 'admin@test.co');
        $this->adminSinPedidos = $crear('administrador', 'caja@test.co', ['menus_permitidos' => ['dashboard', 'caja']]);
        $this->mesero = $crear('mesero', 'mesero@test.co');
        $this->otroMesero = $crear('mesero', 'mario@test.co');
        $this->soporte = $crear('soporte', 'soporte@test.co');
        $this->cliente = $crear('cliente', 'cliente@test.co');
        $crear('administrador', 'inactivo@test.co', ['activo' => false]);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 3', 'qr_code' => 'qr-3', 'estado' => 'libre']);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->hamburguesa = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
    }

    private function como(?User $usuario): void
    {
        $this->app['auth']->forgetGuards();
        if ($usuario) {
            Sanctum::actingAs($usuario);
        }
    }

    /** @return array<int, string> correos de quienes recibieron ese tipo */
    private function quienes(string $tipo): array
    {
        return Notificacion::where('tipo', $tipo)->with('usuario')->get()->pluck('usuario.email')->sort()->values()->all();
    }

    private function reserva(array $cambios = []): Reserva
    {
        return Reserva::create(array_merge([
            'mesa_id' => $this->mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3001234567', 'cliente_correo' => 'laura@test.co',
            'fecha_hora' => now()->addDay(), 'estado' => 'confirmada', 'deposito_valor' => 20000, 'deposito_pagado' => true,
            'deposito_reembolsado' => false, 'deposito_aplicado_abono' => false, 'creado_por' => 'cliente_web',
        ], $cambios));
    }

    public function test_un_pedido_por_el_qr_avisa_al_personal_que_tiene_el_menu_de_pedidos(): void
    {
        $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 2]]])->assertCreated();

        // Ni Soporte, ni el Cliente, ni la cuenta inactiva, ni el Administrador sin el menú Pedidos.
        $this->assertSame(['admin@test.co', 'mario@test.co', 'mesero@test.co', 'super@test.co'], $this->quienes('pedido_nuevo'));
        $n = Notificacion::where('user_id', $this->mesero->id)->sole();
        $this->assertSame(['pedidos', '/mesero'], [$n->categoria, $n->enlace]);
        $this->assertStringContainsString('Mesa 3 (QR)', $n->texto);
        $this->assertStringContainsString('$40.000', $n->texto);
        $this->assertSame('/admin/pedidos', Notificacion::where('user_id', $this->admin->id)->value('enlace'));

        // El pedido que toma el Mesero no avisa a nadie.
        $this->como($this->mesero);
        $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->hamburguesa->id, 'cantidad' => 1]]])->assertCreated();
        $this->assertSame(4, Notificacion::count());
    }

    public function test_quien_hace_la_accion_no_recibe_su_propio_aviso(): void
    {
        $this->como($this->mesero);
        $this->postJson("/api/productos/{$this->hamburguesa->id}/disponibilidad", ['disponible' => false])->assertOk();

        $this->assertSame(['admin@test.co', 'mario@test.co', 'super@test.co'], $this->quienes('producto_agotado'));
        $this->assertSame('/mesero/menu', Notificacion::where('user_id', $this->otroMesero->id)->value('enlace'));

        // Volver a ponerlo disponible no avisa.
        $this->postJson("/api/productos/{$this->hamburguesa->id}/disponibilidad", ['disponible' => true])->assertOk();
        $this->assertSame(3, Notificacion::count());
    }

    public function test_reservas_nueva_deposito_y_cancelacion_del_cliente(): void
    {
        $servicio = app(ReservaService::class);
        $reserva = $servicio->crear(['mesa_id' => $this->mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3001234567',
            'cliente_correo' => 'laura@test.co', 'fecha_hora' => now()->addDay(), 'creado_por' => 'cliente_web'], 20000);
        $this->assertSame(['admin@test.co', 'mario@test.co', 'mesero@test.co', 'super@test.co'], $this->quienes('reserva_nueva'));
        $this->assertSame('/mesero/reservas', Notificacion::where('user_id', $this->mesero->id)->value('enlace'));

        // El depósito lo registra el Mesero: les llega a los demás.
        app(CajaService::class)->abrir($this->admin, 0);
        $servicio->registrarDepositoManual($reserva, 'efectivo', $this->mesero);
        $this->assertSame(['admin@test.co', 'mario@test.co', 'super@test.co'], $this->quienes('reserva_confirmada'));

        // La cancela el cliente con su enlace.
        $servicio->cancelar($reserva->fresh(), null, true);
        $this->assertCount(4, $this->quienes('reserva_cancelada'));
    }

    public function test_la_campana_lista_cuenta_y_marca_solo_las_propias(): void
    {
        $servicio = app(NotificacionService::class);
        $servicio->notificar('pedido_nuevo', 'Uno', 'Texto', 'prueba:1');
        $servicio->notificar('pedido_nuevo', 'Dos', 'Texto', 'prueba:2');
        $servicio->notificar('pedido_nuevo', 'Dos', 'Texto', 'prueba:2'); // misma clave: no se repite

        $this->como($this->mesero);
        $this->getJson('/api/notificaciones/contador')->assertOk()->assertJsonPath('no_leidas', 2);
        $lista = $this->getJson('/api/notificaciones')->assertOk()->assertJsonPath('no_leidas', 2)->assertJsonMissingPath('data.0.clave');
        $this->assertSame(['Dos', 'Uno'], collect($lista->json('data'))->pluck('titulo')->all());

        $mia = Notificacion::where('user_id', $this->mesero->id)->where('titulo', 'Uno')->sole();
        $ajena = Notificacion::where('user_id', $this->admin->id)->where('titulo', 'Uno')->sole();
        $this->postJson("/api/notificaciones/{$mia->id}/leida")->assertOk()->assertJsonPath('no_leidas', 1);
        $this->postJson("/api/notificaciones/{$ajena->id}/leida")->assertNotFound();
        $this->assertNull($ajena->fresh()->leida_at);
        $this->postJson('/api/notificaciones/leidas')->assertOk()->assertJsonPath('marcadas', 1);
        $this->getJson('/api/notificaciones/contador')->assertJsonPath('no_leidas', 0);

        // El Cliente no tiene campana.
        $this->como($this->cliente);
        $this->getJson('/api/notificaciones')->assertForbidden();
    }

    public function test_avisos_programados_de_reserva_caja_y_cierre_salen_una_sola_vez(): void
    {
        $servicio = app(NotificacionService::class);
        $this->reserva(['fecha_hora' => now()->addMinutes(20)]);
        $this->reserva(['fecha_hora' => now()->addHours(3)]);
        $servicio->revisar();
        $servicio->revisar();
        $this->assertCount(4, $this->quienes('reserva_proxima'));
        $this->assertStringContainsString('Reserva en 20 min', Notificacion::where('tipo', 'reserva_proxima')->value('titulo'));

        // 11:40 p. m. con la caja abierta: a los que tienen el menú Caja.
        app(CajaService::class)->abrir($this->admin, 0);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 23:40:00', 'America/Bogota'));
        $servicio->revisar();
        $servicio->revisar();
        $this->assertSame(['admin@test.co', 'caja@test.co', 'super@test.co'], $this->quienes('caja_abierta'));

        // Al otro día a las 10:30, el día anterior tuvo caja y no tiene cierre.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:30:00', 'America/Bogota'));
        $servicio->revisar();
        $this->assertSame(['admin@test.co', 'super@test.co'], $this->quienes('cierre_pendiente'));
        $this->assertTrue(CajaTurno::count() === 1);
    }

    public function test_los_avisos_del_sistema_llegan_tambien_a_soporte_y_se_limpian_a_los_30_dias(): void
    {
        Cache::forever(EstadoSistemaService::CLAVE_PROGRAMADOR, now()->subMinutes(30)->toIso8601String());
        $this->como($this->admin);
        $this->getJson('/api/notificaciones/contador')->assertJsonPath('no_leidas', 1);
        $this->assertSame(['admin@test.co', 'soporte@test.co', 'super@test.co'], $this->quienes('sistema'));
        $this->assertSame('/admin/configuracion', Notificacion::where('user_id', $this->soporte->id)->value('enlace'));

        // Soporte solo consulta, pero sí marca sus notificaciones como leídas.
        $this->como($this->soporte);
        $this->postJson('/api/notificaciones/leidas')->assertOk()->assertJsonPath('marcadas', 1);

        // Un mesero no recibe los del sistema; y a los 31 días se borran.
        $this->assertSame(0, Notificacion::where('user_id', $this->mesero->id)->count());
        $this->travel(31)->days();
        $this->assertSame(3, app(NotificacionService::class)->limpiar());
    }
}
