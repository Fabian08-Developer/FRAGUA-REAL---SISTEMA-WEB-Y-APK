<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Revisión por rol (13/10/2026): el enlace de seguimiento del invitado se puede reenviar (a quien
 * pasa a recoger, por ejemplo). Muestra el estado, las líneas, lo pagado y el comprobante, pero ya
 * no el teléfono ni el correo del cliente, ni quién del personal hizo cada paso, ni las notas
 * internas de los pagos. RF-M07-027, RNF-TRANS-010.
 */
class EnlaceSeguimientoTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVADOS_PEDIDO = [
        'cliente_invitado_telefono', 'cliente_invitado_correo', 'telefono_contacto', 'cliente_id',
        'confirmado_por', 'entregado_por', 'finalizado_por', 'cancelado_por',
    ];

    private User $mesero;
    private Producto $clasica;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->clasica = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        app(\App\Services\CajaService::class)->abrir($admin, 0);
    }

    private function domicilioInvitado(): array
    {
        $respuesta = $this->postJson('/api/pedidos', [
            'tipo' => 'domicilio', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Laura', 'cliente_invitado_telefono' => '3001234567', 'cliente_invitado_correo' => 'laura@correo.co',
            'direccion_entrega' => 'Calle 5 # 3-20', 'barrio' => 'Centro', 'referencia' => 'Portón negro',
            'lineas' => [['producto_id' => $this->clasica->id, 'cantidad' => 1]],
        ])->assertCreated();

        return [$respuesta->json('id'), $respuesta->json('token_publico')];
    }

    public function test_el_enlace_muestra_el_pedido_sin_datos_de_contacto_ni_del_personal(): void
    {
        [$id, $token] = $this->domicilioInvitado();

        // El Mesero lo atiende de punta a punta: confirma, entrega y cobra.
        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$id}/confirmar")->assertOk();
        $this->postJson("/api/pedidos/{$id}/preparando")->assertOk();
        $this->postJson("/api/pedidos/{$id}/entregar")->assertOk();
        $this->postJson("/api/pedidos/{$id}/cobrar", ['metodo' => 'efectivo', 'monto' => 22000])->assertSuccessful();
        $this->app['auth']->forgetGuards();

        $datos = $this->getJson("/api/pedidos/seguimiento/{$token}")->assertOk()->json();

        // Lo que la pantalla de seguimiento usa sigue ahí.
        $this->assertSame('finalizado', $datos['estado']);
        $this->assertEquals(22000, $datos['total']);
        $this->assertSame('Calle 5 # 3-20', $datos['direccion_entrega']);
        $this->assertSame('Clásica', $datos['detalles'][0]['producto']['nombre']);
        $this->assertSame('efectivo', $datos['pagos'][0]['metodo']);
        $this->assertNotEmpty($datos['comprobante']['numero']);

        // Lo privado no.
        foreach (self::PRIVADOS_PEDIDO as $campo) {
            $this->assertArrayNotHasKey($campo, $datos, "El enlace no debe traer {$campo}.");
        }
        foreach (['registrado_por', 'caja_turno_id', 'anulado_motivo'] as $campo) {
            $this->assertArrayNotHasKey($campo, $datos['pagos'][0]);
        }
        $this->assertStringNotContainsString('3001234567', json_encode($datos));
        $this->assertStringNotContainsString('laura@correo.co', json_encode($datos));
    }

    public function test_cancelar_por_el_enlace_tampoco_devuelve_los_datos_de_contacto(): void
    {
        [, $token] = $this->domicilioInvitado();

        $datos = $this->postJson("/api/pedidos/seguimiento/{$token}/cancelar", ['motivo' => 'Me equivoqué de dirección'])
            ->assertOk()->assertJsonPath('estado', 'cancelado')->json();

        foreach (self::PRIVADOS_PEDIDO as $campo) {
            $this->assertArrayNotHasKey($campo, $datos);
        }
    }

    public function test_el_personal_sigue_viendo_los_datos_de_contacto(): void
    {
        [$id] = $this->domicilioInvitado();

        Sanctum::actingAs($this->mesero);
        $this->getJson("/api/pedidos/{$id}")->assertOk()
            ->assertJsonPath('cliente_invitado_telefono', '3001234567')
            ->assertJsonPath('cliente_invitado_correo', 'laura@correo.co');
    }
}
