<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 0, tarea 4 del plan de implementación: el invitado también paga en línea, y reintentar
 * un pago no crea pedidos ni pagos duplicados. RF-M06-005, RF-M06-008, HU-M07-002, HU-M07-011.
 */
class PagoEnLineaInvitadoTest extends TestCase
{
    use RefreshDatabase;

    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
    }

    private function crearRecogidaInvitado()
    {
        return $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Ana', 'cliente_invitado_telefono' => '3001234567',
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertCreated();
    }

    public function test_RF_M06_005_crear_el_pedido_entrega_un_token_que_no_aparece_despues(): void
    {
        $respuesta = $this->crearRecogidaInvitado();
        $token = $respuesta->json('token_publico');
        $this->assertNotEmpty($token);

        $this->getJson("/api/pedidos/seguimiento/{$token}")
            ->assertOk()
            ->assertJsonPath('id', $respuesta->json('id'))
            ->assertJsonMissingPath('token_publico');
    }

    public function test_un_token_inventado_no_revela_ningun_pedido(): void
    {
        $this->crearRecogidaInvitado();

        $this->getJson('/api/pedidos/seguimiento/token-que-no-existe')->assertNotFound();
        $this->postJson('/api/pedidos/seguimiento/token-que-no-existe/pagar-en-linea', ['pasarela' => 'nequi', 'monto' => 20000])
            ->assertNotFound();
    }

    public function test_RF_M06_005_el_invitado_paga_en_linea_con_su_token(): void
    {
        $respuesta = $this->crearRecogidaInvitado();

        $this->postJson("/api/pedidos/seguimiento/{$respuesta->json('token_publico')}/pagar-en-linea", ['pasarela' => 'nequi', 'monto' => 20000])
            ->assertCreated()
            ->assertJsonPath('estado', 'pendiente');

        $this->assertSame(1, Pago::where('pedido_id', $respuesta->json('id'))->count());
    }

    public function test_RF_M06_008_no_se_acumulan_dos_pagos_en_linea_pendientes(): void
    {
        $token = $this->crearRecogidaInvitado()->json('token_publico');

        $this->postJson("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'nequi', 'monto' => 20000])->assertCreated();
        $this->postJson("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'nequi', 'monto' => 20000])->assertStatus(422);

        $this->assertSame(1, Pago::count());
    }

    public function test_RF_M06_008_despues_de_un_rechazo_se_puede_reintentar_el_mismo_pedido(): void
    {
        $respuesta = $this->crearRecogidaInvitado();
        $token = $respuesta->json('token_publico');

        $primero = $this->postJson("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'nequi', 'monto' => 20000])->json('id');
        Pago::find($primero)->update(['estado' => 'rechazado']);

        $this->postJson("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'bancolombia', 'monto' => 20000])->assertCreated();

        $this->assertSame(1, Pedido::count(), 'Reintentar el pago no crea otro pedido.');
    }

    public function test_un_pedido_ya_confirmado_no_recibe_pagos_en_linea_nuevos(): void
    {
        $respuesta = $this->crearRecogidaInvitado();
        Pedido::find($respuesta->json('id'))->update(['estado' => 'confirmado']);

        $this->postJson("/api/pedidos/seguimiento/{$respuesta->json('token_publico')}/pagar-en-linea", ['pasarela' => 'nequi', 'monto' => 20000])
            ->assertStatus(422);
    }

    public function test_el_cliente_con_sesion_sigue_pagando_por_su_ruta_y_no_ve_el_token_en_su_lista(): void
    {
        $cliente = User::create(['role' => 'cliente', 'nombre' => 'Luis', 'email' => 'luis@test.co', 'telefono' => '3001234567', 'password' => 'secreto123', 'activo' => true]);
        Sanctum::actingAs($cliente);

        $id = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/pedidos/{$id}/pagar-en-linea", ['pasarela' => 'nequi', 'monto' => 20000])->assertCreated();
        $this->getJson('/api/pedidos')->assertOk()->assertJsonMissingPath('data.0.token_publico');
    }
}
