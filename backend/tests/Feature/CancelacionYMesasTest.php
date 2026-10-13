<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\User;
use App\Services\PedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 1, tarea 6 del plan de implementación: cancelación según actor y estado, y edición de mesas.
 * RF-M02-L-024, RF-M07-016/017, RF-M07-R-009/010, RN-M07-04/05 · RF-M05-002/021, HU-M05-002.
 */
class CancelacionYMesasTest extends TestCase
{
    use RefreshDatabase;

    private User $mesero;
    private User $admin;
    private User $cliente;
    private Producto $producto;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => Hash::make('clave-mesero'), 'activo' => true]);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => Hash::make('clave-admin'), 'activo' => true]);
        $this->cliente = User::create(['role' => 'cliente', 'nombre' => 'Ana', 'email' => 'ana@test.co', 'telefono' => '3001234567', 'password' => Hash::make('clave-cliente'), 'activo' => true]);
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
    }

    private function local(bool $confirmado = false): Pedido
    {
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $this->mesa->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ]);
        if ($confirmado) {
            app(PedidoService::class)->confirmar($pedido, $this->mesero);
        }

        return $pedido->fresh();
    }

    private function recogidaDelCliente(): Pedido
    {
        return app(PedidoService::class)->crear([
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'cliente_id' => $this->cliente->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ]);
    }

    public function test_RF_M02_L_024_el_mesero_cancela_un_pedido_pendiente(): void
    {
        $pedido = $this->local();
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/pedidos/{$pedido->id}/cancelar", ['motivo' => 'Se fue'])->assertOk()->assertJsonPath('estado', 'cancelado');
    }

    public function test_RN_M07_05_un_pedido_confirmado_solo_lo_cancela_el_mesero_con_la_clave_de_un_administrador(): void
    {
        $pedido = $this->local(confirmado: true);
        Sanctum::actingAs($this->mesero);

        $this->postJson("/api/pedidos/{$pedido->id}/cancelar", ['motivo' => 'Se fue'])->assertStatus(422);
        $this->postJson("/api/pedidos/{$pedido->id}/cancelar", ['motivo' => 'Se fue', 'password_administrador' => 'clave-mesero'])->assertStatus(422);
        $this->assertSame('confirmado', $pedido->fresh()->estado);

        $this->postJson("/api/pedidos/{$pedido->id}/cancelar", ['motivo' => 'Se fue', 'password_administrador' => 'clave-admin'])
            ->assertOk()->assertJsonPath('estado', 'cancelado');

        $registro = Auditoria::where('accion', 'cancelar_pedido')->sole();
        $this->assertSame($this->mesero->id, $registro->user_id);
        $this->assertSame($this->admin->id, $registro->detalles['autorizado_por']);
    }

    public function test_el_administrador_cancela_un_pedido_confirmado_sin_mas(): void
    {
        $pedido = $this->local(confirmado: true);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/pedidos/{$pedido->id}/cancelar", ['motivo' => 'Error de digitación'])->assertOk();
    }

    public function test_RF_M07_R_009_el_cliente_cancela_su_recogida_pendiente_pero_no_una_confirmada(): void
    {
        $pendiente = $this->recogidaDelCliente();
        $confirmada = $this->recogidaDelCliente();
        app(PedidoService::class)->confirmar($confirmada, $this->mesero);
        Sanctum::actingAs($this->cliente);

        $this->postJson("/api/pedidos/{$pendiente->id}/cancelar", ['motivo' => 'Ya no lo necesito'])->assertOk();
        $this->postJson("/api/pedidos/{$confirmada->id}/cancelar", ['motivo' => 'Ya no lo necesito'])->assertForbidden();
    }

    public function test_el_cliente_no_cancela_un_pedido_de_mesa_ni_uno_ajeno(): void
    {
        $local = $this->local();
        $otro = User::create(['role' => 'cliente', 'nombre' => 'Luis', 'email' => 'luis@test.co', 'telefono' => '3009998888', 'password' => 'secreto123', 'activo' => true]);
        $ajeno = app(PedidoService::class)->crear([
            'tipo' => 'recogida', 'origen' => 'cliente_web', 'cliente_id' => $otro->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ]);
        Sanctum::actingAs($this->cliente);

        $this->postJson("/api/pedidos/{$local->id}/cancelar", ['motivo' => 'x'])->assertForbidden();
        $this->postJson("/api/pedidos/{$ajeno->id}/cancelar", ['motivo' => 'x'])->assertForbidden();
    }

    public function test_RF_M07_016_el_invitado_cancela_su_pedido_pendiente_con_su_enlace(): void
    {
        $respuesta = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Invitado', 'cliente_invitado_telefono' => '3115556666',
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertCreated();
        $token = $respuesta->json('token_publico');

        $this->postJson('/api/pedidos/seguimiento/token-falso/cancelar', ['motivo' => 'x'])->assertNotFound();
        $this->postJson("/api/pedidos/seguimiento/{$token}/cancelar", ['motivo' => 'Me equivoqué'])
            ->assertOk()->assertJsonPath('estado', 'cancelado');
        $this->assertTrue(Auditoria::where('accion', 'cancelar_pedido')->sole()->detalles['por_el_cliente']);
    }

    public function test_RF_M05_002_el_administrador_corrige_el_nombre_de_una_mesa_sin_repetir_otro(): void
    {
        Mesa::create(['nombre' => 'Mesa 2', 'qr_code' => 'qr-2']);
        $qr = $this->mesa->qr_code;
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/mesas/{$this->mesa->id}", ['nombre' => 'Mesa 2'])->assertStatus(422)->assertJsonValidationErrors(['nombre']);
        $this->putJson("/api/mesas/{$this->mesa->id}", ['nombre' => 'Terraza 1'])->assertOk()->assertJsonPath('nombre', 'Terraza 1');
        // Guardar el mismo nombre no choca consigo misma.
        $this->putJson("/api/mesas/{$this->mesa->id}", ['nombre' => 'Terraza 1'])->assertOk();

        $this->assertSame($qr, $this->mesa->fresh()->qr_code, 'El QR impreso sigue sirviendo.');
        $this->assertSame(2, Auditoria::where('accion', 'editar_mesa')->count());
    }

    public function test_el_mesero_no_edita_mesas(): void
    {
        Sanctum::actingAs($this->mesero);

        $this->putJson("/api/mesas/{$this->mesa->id}", ['nombre' => 'Otra'])->assertForbidden();
    }
}
