<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Notificacion;
use App\Models\Pago;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\PagoDirectoDePrueba;
use Tests\TestCase;

/**
 * Pago directo (22/10/2026, RF-M06-030 a 035, RN-M06-16 a 19): sin Wompi, el Cliente transfiere a las
 * cuentas que dejó el SuperAdmin (Nequi, Bancolombia o la llave Bre-B) y sube la captura; el personal
 * la revisa y aprueba o rechaza con motivo, que el Cliente ve al seguir su pedido o su reserva.
 */
class PagoDirectoTest extends TestCase
{
    use PagoDirectoDePrueba;
    use RefreshDatabase;

    private User $super;
    private User $admin;
    private User $mesero;
    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $crear = fn (string $rol, string $correo) => User::create([
            'role' => $rol, 'nombre' => ucfirst($rol), 'email' => $correo, 'password' => 'Clave12345', 'activo' => true,
        ]);
        $this->super = $crear('superadmin', 'super@test.co');
        $this->admin = $crear('administrador', 'admin@test.co');
        $this->mesero = $crear('mesero', 'mesero@test.co');

        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
    }

    private function recogidaInvitado(): array
    {
        $respuesta = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'metodo_pago_preferido' => 'en_linea', 'pasarela_preferida' => 'nequi',
            'cliente_invitado_nombre' => 'Ana', 'cliente_invitado_telefono' => '3001234567',
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertCreated();

        return [$respuesta->json('id'), $respuesta->json('token_publico')];
    }

    private function reservaWeb(): array
    {
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        $respuesta = $this->postJson('/api/reservas', [
            'mesa_id' => $mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234', 'cliente_correo' => 'laura@correo.co',
            'fecha_hora' => CarbonImmutable::now('America/Bogota')->addDay()->setTime(20, 0)->format('Y-m-d H:i'),
        ])->assertCreated();

        return [$respuesta->json('id'), $respuesta->json('token_publico')];
    }

    public function test_RN_M06_16_solo_el_superadmin_cambia_las_cuentas_y_queda_en_la_auditoria(): void
    {
        $cuentas = ['nequi' => ['numero' => '300 123 4567', 'titular' => 'Fragua Real'], 'breb' => ['llave' => '@fraguareal', 'titular' => 'Fragua Real']];

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/negocio/pagos-directos')->assertOk()->assertJsonPath('datos.nequi.numero', '');
        $this->putJson('/api/negocio/pagos-directos', $cuentas)->assertForbidden();

        Sanctum::actingAs($this->super);
        $this->putJson('/api/negocio/pagos-directos', ['nequi' => ['numero' => '12ab']])
            ->assertStatus(422)->assertJsonValidationErrors('nequi.numero');
        $this->putJson('/api/negocio/pagos-directos', $cuentas)
            ->assertOk()
            ->assertJsonPath('datos.nequi.numero', '3001234567')
            ->assertJsonPath('publico.activo', true);

        $auditoria = Auditoria::where('accion', 'editar_pagos_directos')->sole();
        $this->assertSame($this->super->id, $auditoria->user_id);
    }

    public function test_RF_M06_030_el_cliente_solo_ve_los_medios_completos(): void
    {
        $this->getJson('/api/configuracion')
            ->assertOk()->assertJsonPath('pagos_directos.activo', false)->assertJsonPath('pagos_directos.medios', []);

        Sanctum::actingAs($this->super);
        // Bancolombia sin número: no se ofrece.
        $this->putJson('/api/negocio/pagos-directos', [
            'nequi' => ['numero' => '3001234567', 'titular' => 'Fragua Real'],
            'bancolombia' => ['tipo' => 'ahorros', 'titular' => 'Fragua Real'],
        ])->assertOk();

        $medios = $this->getJson('/api/configuracion')
            ->assertOk()
            ->assertJsonPath('pagos_directos.activo', true)
            ->assertJsonPath('pagos_directos.automatico', false)
            ->json('pagos_directos.medios');
        $this->assertSame(['nequi'], array_column($medios, 'medio'));
        $this->assertSame('3001234567', collect($medios[0]['campos'])->firstWhere('campo', 'numero')['valor']);
    }

    public function test_sin_cuentas_no_se_puede_pagar_en_linea_y_un_medio_sin_datos_tampoco(): void
    {
        Storage::fake('local');
        [, $token] = $this->recogidaInvitado();

        $this->post("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'nequi', 'comprobante' => $this->captura()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('pasarela');

        Sanctum::actingAs($this->super);
        $this->putJson('/api/negocio/pagos-directos', ['nequi' => ['numero' => '3001234567', 'titular' => 'Fragua Real']])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->post("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'breb', 'comprobante' => $this->captura()], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('pasarela');
        $this->assertSame(0, Pago::count());
    }

    public function test_RF_M06_031_la_captura_es_obligatoria_y_solo_foto_o_pdf_de_hasta_5_mb(): void
    {
        $this->configurarPagoDirecto();
        [, $token] = $this->recogidaInvitado();
        $uri = "/api/pedidos/seguimiento/{$token}/pagar-en-linea";

        $this->post($uri, ['pasarela' => 'nequi'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('comprobante');
        $this->pagoDirecto($uri, ['pasarela' => 'nequi'], UploadedFile::fake()->create('nota.txt', 2, 'text/plain'))
            ->assertStatus(422)->assertJsonValidationErrors('comprobante');
        $this->pagoDirecto($uri, ['pasarela' => 'nequi'], UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('comprobante');
        $this->assertSame(0, Pago::count());

        $this->pagoDirecto($uri, ['pasarela' => 'nequi'], UploadedFile::fake()->create('comprobante.pdf', 300, 'application/pdf'))->assertCreated();
    }

    public function test_RF_M06_032_la_captura_se_guarda_privada_y_solo_la_ve_el_personal(): void
    {
        [$pedidoId, $token] = $this->recogidaInvitado();

        $respuesta = $this->pagoDirecto("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'bancolombia', 'monto' => 1, 'referencia' => ' M123 '])
            ->assertCreated()
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('tiene_comprobante', true)
            ->assertJsonPath('referencia_cliente', 'M123')
            ->assertJsonMissingPath('comprobante_path');

        $pago = Pago::findOrFail($respuesta->json('id'));
        // El monto lo fija el servidor (el saldo del pedido), no el Cliente.
        $this->assertSame(20000.0, (float) $pago->monto);
        Storage::disk('local')->assertExists($pago->comprobante_path);
        $this->assertStringStartsWith('comprobantes-pago/', $pago->comprobante_path);

        // Avisa al personal que tiene un comprobante por revisar.
        $this->assertTrue(Notificacion::where('user_id', $this->mesero->id)->where('tipo', 'pago_por_confirmar')->exists());

        $this->getJson("/api/pagos/{$pago->id}/comprobante")->assertUnauthorized();
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Otro', 'email' => 'otro@test.co', 'password' => 'Clave12345', 'activo' => true]);
        Sanctum::actingAs($cliente);
        $this->getJson("/api/pagos/{$pago->id}/comprobante")->assertForbidden();

        Sanctum::actingAs($this->mesero);
        $archivo = $this->get("/api/pagos/{$pago->id}/comprobante")->assertOk();
        $this->assertSame('image/jpeg', $archivo->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $archivo->headers->get('Cache-Control'));

        $this->assertSame(1, Pago::where('pedido_id', $pedidoId)->count());
    }

    public function test_RF_M06_033_el_rechazo_lleva_motivo_que_ve_el_cliente_y_puede_enviarla_de_nuevo(): void
    {
        [, $token] = $this->recogidaInvitado();
        $pagoId = $this->pagoDirecto("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'nequi'])->json('id');

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pagos/{$pagoId}/rechazar-en-linea", ['motivo' => 'No nos ha llegado el dinero'])
            ->assertOk()->assertJsonPath('estado', 'rechazado')->assertJsonPath('rechazo_motivo', 'No nos ha llegado el dinero');
        $this->app['auth']->forgetGuards();

        $pagos = $this->getJson("/api/pedidos/seguimiento/{$token}")->assertOk()->json('pagos');
        $this->assertSame('No nos ha llegado el dinero', $pagos[0]['rechazo_motivo']);

        $this->pagoDirecto("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'breb'])->assertCreated();

        Sanctum::actingAs($this->admin);
        $segundo = Pago::where('estado', 'pendiente')->sole();
        $this->postJson("/api/pagos/{$segundo->id}/confirmar-en-linea")->assertOk()->assertJsonPath('estado', 'aprobado');
        $this->assertSame('breb', $segundo->fresh()->pasarela);
    }

    public function test_RF_M05_030_el_deposito_de_la_reserva_tambien_se_paga_con_la_captura(): void
    {
        [$reservaId, $token] = $this->reservaWeb();

        $this->pagoDirecto("/api/reservas/enlace/{$token}/deposito", ['pasarela' => 'breb', 'referencia' => 'BRB-9'])
            ->assertOk()
            ->assertJsonPath('deposito_estado', 'pendiente')
            ->assertJsonPath('deposito_tiene_comprobante', true)
            ->assertJsonPath('puede_pagar', false);

        $reserva = Reserva::findOrFail($reservaId);
        Storage::disk('local')->assertExists($reserva->deposito_comprobante_path);
        $this->assertSame('BRB-9', $reserva->deposito_referencia);
        $this->assertTrue(Notificacion::where('user_id', $this->admin->id)->where('tipo', 'deposito_por_confirmar')->exists());

        $this->getJson("/api/reservas/{$reservaId}/deposito/comprobante")->assertUnauthorized();
        Sanctum::actingAs($this->mesero);
        $this->get("/api/reservas/{$reservaId}/deposito/comprobante")->assertOk();
        $this->postJson("/api/reservas/{$reservaId}/deposito/en-linea/rechazar", ['motivo' => 'El valor no coincide'])
            ->assertOk()->assertJsonPath('deposito_estado', 'rechazado')->assertJsonMissingPath('deposito_comprobante_path');
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/reservas/enlace/{$token}")
            ->assertOk()
            ->assertJsonPath('puede_pagar', true)
            ->assertJsonPath('deposito_rechazo_motivo', 'El valor no coincide');

        // Al volver a pagar se limpia el rechazo anterior.
        $this->pagoDirecto("/api/reservas/enlace/{$token}/deposito", ['pasarela' => 'nequi'])->assertOk();
        $this->assertNull(Reserva::findOrFail($reservaId)->deposito_rechazo_motivo);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/reservas/{$reservaId}/deposito/en-linea/confirmar")->assertOk()->assertJsonPath('estado', 'confirmada');
    }
}
