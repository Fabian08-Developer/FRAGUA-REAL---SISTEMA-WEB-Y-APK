<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Comprobante;
use App\Models\Mesa;
use App\Models\Pago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Reserva;
use App\Models\TransaccionPasarela;
use App\Models\User;
use App\Services\PasarelaPagoService;
use App\Services\PedidoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 6, tarea 1 (13/10/2026, decisión D4: Wompi): pago en línea con confirmación automática.
 * RF-M06-006, 007, 008, 017, RN-M06-05, RF-M07-013 a 015, RF-M05-029, 030.
 *
 * No se llama a Wompi de verdad: las respuestas de su API se simulan con Http::fake y los eventos
 * se firman aquí con un secreto de prueba, igual que los firma Wompi.
 */
class PasarelaWompiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO_EVENTOS = 'test_events_secreto';
    private const SECRETO_INTEGRIDAD = 'test_integrity_secreto';
    private const API = 'https://sandbox.wompi.co/v1';

    private Producto $producto;
    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-13 12:00:00', 'America/Bogota'));
        config([
            'fragua_real.pasarela.proveedor' => 'wompi',
            'fragua_real.frontend_url' => 'https://fraguareal.co',
            'services.wompi' => [
                'ambiente' => 'sandbox',
                'llave_publica' => 'pub_test_abc',
                'llave_privada' => 'prv_test_abc',
                'secreto_eventos' => self::SECRETO_EVENTOS,
                'secreto_integridad' => self::SECRETO_INTEGRIDAD,
                'url_checkout' => 'https://checkout.wompi.co/p/',
            ],
        ]);
        Http::preventStrayRequests();

        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Ana Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
    }

    // ───────────── ayudas ─────────────

    /** Pedido para recoger de un invitado; devuelve [id, token]. */
    private function pedidoInvitado(): array
    {
        $r = $this->postJson('/api/pedidos', [
            'tipo' => 'recogida', 'origen' => 'cliente_web',
            'cliente_invitado_nombre' => 'Ana Pérez', 'cliente_invitado_telefono' => '3001234567', 'cliente_invitado_correo' => 'ana@correo.co',
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertCreated();

        return [$r->json('id'), $r->json('token_publico')];
    }

    private function pagar(string $token, string $pasarela = 'nequi')
    {
        return $this->postJson("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => $pasarela, 'monto' => 20000]);
    }

    private function evento(string $referencia, string $estado, int $centavos = 2000000, string $id = '1234-1760000000-00001', string $metodo = 'NEQUI', ?int $timestamp = null): array
    {
        $timestamp ??= now()->getTimestamp();

        return [
            'event' => 'transaction.updated',
            'data' => ['transaction' => [
                'id' => $id, 'amount_in_cents' => $centavos, 'reference' => $referencia, 'currency' => 'COP',
                'payment_method_type' => $metodo, 'status' => $estado, 'customer_email' => 'ana@correo.co',
            ]],
            'environment' => 'test',
            'signature' => [
                'properties' => ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
                'checksum' => hash('sha256', $id.$estado.$centavos.$timestamp.self::SECRETO_EVENTOS),
            ],
            'timestamp' => $timestamp,
            'sent_at' => '2026-10-13T17:00:00.000Z',
        ];
    }

    private function enviarEvento(array $evento)
    {
        return $this->postJson('/api/pasarela/wompi/eventos', $evento, ['X-Event-Checksum' => $evento['signature']['checksum']]);
    }

    // ───────────── pruebas ─────────────

    public function test_RF_M06_006_pagar_en_linea_devuelve_el_checkout_de_wompi_firmado(): void
    {
        [$id, $token] = $this->pedidoInvitado();

        $respuesta = $this->postJson("/api/pedidos/seguimiento/{$token}/pagar-en-linea", ['pasarela' => 'nequi', 'monto' => 1])->assertCreated()
            ->assertJsonPath('estado', 'pendiente');

        $tx = TransaccionPasarela::sole();
        $this->assertSame(['wompi', 'pendiente', 'nequi', '20000.00'], [$tx->proveedor, $tx->estado, $tx->pasarela_preferida, $tx->monto]);
        $this->assertSame((float) 20000, (float) Pago::sole()->monto, 'con la pasarela se cobra lo que falta, no lo que diga el navegador');
        $this->assertMatchesRegularExpression('/^FRP-\d+-[A-Z0-9]{20}$/', $tx->referencia);

        $url = $respuesta->json('checkout.url');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['pub_test_abc', 'COP', '2000000', $tx->referencia], [$q['public-key'], $q['currency'], $q['amount-in-cents'], $q['reference']]);
        $this->assertSame("https://fraguareal.co/pago/retorno/{$tx->referencia}", $q['redirect-url']);
        $this->assertSame('2026-10-13T17:30:00.000Z', $q['expiration-time']);
        $this->assertSame(hash('sha256', $tx->referencia.'2000000COP2026-10-13T17:30:00.000Z'.self::SECRETO_INTEGRIDAD), $q['signature:integrity']);
        $this->assertSame(['ana@correo.co', '3001234567', '+57'], [$q['customer-data:email'], $q['customer-data:phone-number'], $q['customer-data:phone-number-prefix']]);
        $this->assertSame('en_linea', Pedido::find($id)->metodo_pago_preferido);
    }

    public function test_RN_M06_05_el_evento_firmado_aprueba_el_pago_una_sola_vez_y_el_pedido_ya_se_puede_confirmar(): void
    {
        [$id, $token] = $this->pedidoInvitado();
        $this->pagar($token);
        $tx = TransaccionPasarela::sole();

        $this->enviarEvento($this->evento($tx->referencia, 'APPROVED'))->assertOk()->assertJsonPath('resultado', 'aplicado');
        $this->enviarEvento($this->evento($tx->referencia, 'APPROVED'))->assertOk(); // Wompi lo reintenta

        $pago = Pago::sole();
        $this->assertSame(['aprobado', 'pasarela', null], [$pago->estado, $pago->confirmado_via, $pago->confirmado_por]);
        $tx->refresh();
        $this->assertSame(['aprobado', 'NEQUI', '1234-1760000000-00001', 2], [$tx->estado, $tx->metodo_pago, $tx->transaccion_id, $tx->eventos]);
        $registro = Auditoria::where('accion', 'confirmar_pago_en_linea')->sole();
        $this->assertSame(['pasarela', $tx->referencia, null], [$registro->detalles['via'], $registro->detalles['referencia'], $registro->user_id]);
        $this->assertSame(1, Comprobante::count(), 'RN-M06-07: el pago completo genera su comprobante digital');

        // RF-M06-007: ya nada impide confirmar el pedido.
        app(PedidoService::class)->confirmar(Pedido::find($id), $this->mesero);
        $this->assertSame('confirmado', Pedido::find($id)->estado);
    }

    public function test_un_evento_con_la_firma_mal_no_cambia_nada(): void
    {
        [, $token] = $this->pedidoInvitado();
        $this->pagar($token);
        $tx = TransaccionPasarela::sole();

        $falso = $this->evento($tx->referencia, 'APPROVED');
        $falso['data']['transaction']['amount_in_cents'] = 100; // alterado en el camino
        $this->postJson('/api/pasarela/wompi/eventos', $falso)->assertStatus(401);

        $sinFirma = $this->evento($tx->referencia, 'APPROVED');
        unset($sinFirma['signature']);
        $this->postJson('/api/pasarela/wompi/eventos', $sinFirma)->assertStatus(401);

        $bueno = $this->evento($tx->referencia, 'APPROVED');
        $this->postJson('/api/pasarela/wompi/eventos', $bueno, ['X-Event-Checksum' => str_repeat('0', 64)])->assertStatus(401);

        $this->assertSame('pendiente', Pago::sole()->estado);
        $this->assertSame('pendiente', $tx->fresh()->estado);

        // Un evento válido de otra cosa, o de una referencia ajena, se acepta sin hacer nada.
        $this->enviarEvento($this->evento('OTRO-SISTEMA-1', 'APPROVED'))->assertOk()->assertJsonPath('resultado', 'desconocido');
    }

    public function test_un_evento_firmado_pero_viejo_no_se_aplica_y_la_conciliacion_lo_resuelve(): void
    {
        [, $token] = $this->pedidoInvitado();
        $this->pagar($token);
        $tx = TransaccionPasarela::sole();

        // Firma correcta, pero de hace 4 días (por ejemplo, uno capturado y reenviado).
        $viejo = $this->evento($tx->referencia, 'APPROVED', 2000000, 'tx-v', 'NEQUI', now()->subDays(4)->getTimestamp());
        $this->enviarEvento($viejo)->assertOk()->assertJsonPath('resultado', 'vencido');
        $futuro = $this->evento($tx->referencia, 'APPROVED', 2000000, 'tx-v', 'NEQUI', now()->addHour()->getTimestamp());
        $this->enviarEvento($futuro)->assertOk()->assertJsonPath('resultado', 'vencido');
        $this->assertSame('pendiente', Pago::sole()->estado);

        Http::fake([self::API.'/transactions?*' => Http::response(['data' => [['id' => 'tx-v', 'reference' => $tx->referencia, 'status' => 'APPROVED', 'amount_in_cents' => 2000000, 'payment_method_type' => 'NEQUI']]])]);
        $this->travel(3)->minutes();
        app(PasarelaPagoService::class)->conciliar();
        $this->assertSame('aprobado', Pago::sole()->estado);
    }

    public function test_RF_M06_008_rechazado_por_la_pasarela_y_el_reintento_abre_otro_intento(): void
    {
        [$id, $token] = $this->pedidoInvitado();
        $this->pagar($token);
        $primera = TransaccionPasarela::sole();

        $this->enviarEvento($this->evento($primera->referencia, 'DECLINED'))->assertOk();
        $this->assertSame('rechazado', Pago::sole()->estado);
        $this->assertSame('pasarela', Auditoria::where('accion', 'rechazar_pago_en_linea')->sole()->detalles['via']);

        $this->pagar($token, 'bancolombia')->assertCreated();
        $this->assertSame(2, TransaccionPasarela::count());
        $this->assertSame(['rechazado', 'pendiente'], Pago::where('pedido_id', $id)->orderBy('id')->pluck('estado')->all());
        $this->assertSame('bancolombia', Pedido::find($id)->pasarela_preferida);
    }

    public function test_RF_M06_008_reintentar_sin_haber_pagado_cierra_el_intento_abierto_y_si_sigue_en_curso_avisa(): void
    {
        [, $token] = $this->pedidoInvitado();
        $this->pagar($token);
        $primera = TransaccionPasarela::sole();

        // El Cliente cerró el checkout sin pagar: Wompi no tiene ninguna transacción con esa referencia.
        // (Http::fake acumula; la respuesta la decide $enWompi.)
        $enWompi = [];
        Http::fake([self::API.'/transactions?*' => function () use (&$enWompi) {
            return Http::response(['data' => $enWompi]);
        }]);
        $this->pagar($token)->assertCreated();
        $this->assertSame('abandonado', $primera->fresh()->estado);
        $this->assertSame('rechazado', Pago::orderBy('id')->first()->estado);
        Http::assertSent(fn (PeticionHttp $p) => str_contains($p->url(), 'reference='.$primera->referencia) && $p->hasHeader('Authorization', 'Bearer prv_test_abc'));

        // El segundo sí llegó a Wompi y está esperando que lo apruebe en Nequi: no se abre un tercero.
        $segunda = TransaccionPasarela::latest('id')->first();
        $enWompi = [[
            'id' => 'tx-2', 'reference' => $segunda->referencia, 'status' => 'PENDING', 'amount_in_cents' => 2000000, 'payment_method_type' => 'NEQUI',
        ]];
        $this->pagar($token)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'sigue en proceso'));
        $this->assertSame(2, Pago::count());
        $this->assertSame('tx-2', $segunda->fresh()->transaccion_id);
    }

    public function test_la_pagina_de_retorno_consulta_wompi_y_no_acepta_un_id_de_otra_referencia(): void
    {
        [$id, $token] = $this->pedidoInvitado();
        $this->pagar($token);
        $tx = TransaccionPasarela::sole();

        Http::fake([
            self::API.'/transactions/tx-ajena' => Http::response(['data' => ['id' => 'tx-ajena', 'reference' => 'OTRA', 'status' => 'APPROVED', 'amount_in_cents' => 2000000]]),
            self::API.'/transactions/tx-9' => Http::response(['data' => ['id' => 'tx-9', 'reference' => $tx->referencia, 'status' => 'APPROVED', 'amount_in_cents' => 2000000, 'payment_method_type' => 'BANCOLOMBIA_TRANSFER']]),
        ]);

        $this->getJson("/api/pasarela/transacciones/{$tx->referencia}?id=tx-ajena")->assertOk()->assertJsonPath('estado', 'pendiente');
        $this->assertSame('pendiente', Pago::sole()->estado);

        $this->getJson("/api/pasarela/transacciones/{$tx->referencia}?id=tx-9")->assertOk()
            ->assertJsonPath('estado', 'aprobado')
            ->assertJsonPath('tipo', 'pedido')
            ->assertJsonPath('pedido.id', $id)
            ->assertJsonPath('pedido.seguimiento', $token)
            ->assertJsonMissingPath('respuesta');
        $this->assertSame('aprobado', Pago::sole()->estado);

        $this->getJson('/api/pasarela/transacciones/FRP-1-NOEXISTE00000000000A')->assertNotFound();
    }

    public function test_un_monto_distinto_no_se_confirma_solo_y_queda_para_revisar(): void
    {
        [, $token] = $this->pedidoInvitado();
        $this->pagar($token);
        $tx = TransaccionPasarela::sole();

        $this->enviarEvento($this->evento($tx->referencia, 'APPROVED', 1000000))->assertOk();

        $this->assertSame('pendiente', Pago::sole()->estado);
        $this->assertTrue($tx->fresh()->requiere_revision);
        $this->assertSame(1, Auditoria::where('accion', 'pago_pasarela_por_revisar')->count());

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/pasarela/estado')->assertOk()->assertJsonPath('activa', true)->assertJsonPath('por_revisar', 1);
        $this->getJson('/api/pasarela/transacciones?revision=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.referencia', $tx->referencia);
        $this->postJson("/api/pasarela/transacciones/{$tx->id}/revisada", ['nota' => 'Llamé al cliente; se aprueba a mano.'])->assertOk()->assertJsonPath('requiere_revision', false);
        $this->assertSame(1, Auditoria::where('accion', 'revisar_pago_pasarela')->count());
    }

    public function test_aprobado_por_wompi_despues_de_rechazado_a_mano_queda_para_revisar(): void
    {
        [, $token] = $this->pedidoInvitado();
        $this->pagar($token);
        $tx = TransaccionPasarela::sole();

        // La aprobación manual sigue de respaldo (D4).
        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/pagos/'.Pago::sole()->id.'/rechazar-en-linea')->assertOk()->assertJsonPath('confirmado_via', 'manual');
        $this->assertSame($this->mesero->id, Auditoria::where('accion', 'rechazar_pago_en_linea')->sole()->user_id);

        $this->enviarEvento($this->evento($tx->referencia, 'APPROVED'))->assertOk();

        $this->assertSame('rechazado', Pago::sole()->estado);
        $tx->refresh();
        $this->assertSame('aprobado', $tx->estado);
        $this->assertTrue($tx->requiere_revision);
        $this->assertStringContainsString('ya estaba rechazado', $tx->motivo_revision);
    }

    public function test_RF_M05_030_el_deposito_de_la_reserva_usa_el_mismo_camino(): void
    {
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        $creada = $this->postJson('/api/reservas', [
            'mesa_id' => $mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234', 'cliente_correo' => 'laura@correo.co',
            'fecha_hora' => '2026-10-14 20:00',
        ])->assertCreated();
        $reservaId = $creada->json('id');

        $this->postJson("/api/reservas/{$reservaId}/deposito/en-linea", ['pasarela' => 'nequi', 'token' => 'otro'])->assertNotFound();
        $respuesta = $this->postJson("/api/reservas/{$reservaId}/deposito/en-linea", ['pasarela' => 'nequi', 'token' => $creada->json('token_publico')])->assertOk();

        $tx = TransaccionPasarela::sole();
        $this->assertMatchesRegularExpression('/^FRR-'.$reservaId.'-/', $tx->referencia);
        $this->assertSame('20000.00', $tx->monto);
        $this->assertStringContainsString('amount-in-cents=2000000', $respuesta->json('checkout.url'));

        $this->enviarEvento($this->evento($tx->referencia, 'APPROVED'))->assertOk();

        $reserva = Reserva::find($reservaId);
        $this->assertSame(['confirmada', true, 'aprobado', 'pasarela'], [$reserva->estado, $reserva->deposito_pagado, $reserva->deposito_estado, $reserva->deposito_confirmado_via]);
        $this->getJson("/api/pasarela/transacciones/{$tx->referencia}")->assertOk()
            ->assertJsonPath('tipo', 'reserva')
            ->assertJsonPath('reserva.estado', 'confirmada')
            ->assertJsonPath('reserva.enlace', $reserva->token_publico);
    }

    public function test_RF_M06_017_el_reembolso_se_pide_a_wompi_y_si_el_medio_no_lo_permite_lo_dice(): void
    {
        Http::fake([self::API.'/transactions/tx-card/void' => Http::response(['data' => ['id' => 'void-1', 'status' => 'PENDING']], 201)]);

        // Con tarjeta: Wompi lo anula por API.
        [$id1, $token1] = $this->pedidoInvitado();
        $this->pagar($token1);
        $conTarjeta = TransaccionPasarela::latest('id')->first();
        $this->enviarEvento($this->evento($conTarjeta->referencia, 'APPROVED', 2000000, 'tx-card', 'CARD'))->assertOk();

        // Con Nequi: se devuelve desde el panel de Wompi.
        [$id2, $token2] = $this->pedidoInvitado();
        $this->pagar($token2);
        $conNequi = TransaccionPasarela::latest('id')->first();
        $this->enviarEvento($this->evento($conNequi->referencia, 'APPROVED', 2000000, 'tx-nequi', 'NEQUI'))->assertOk();

        Sanctum::actingAs($this->admin);
        $pago1 = Pago::where('pedido_id', $id1)->sole();
        $this->postJson("/api/pagos/{$pago1->id}/anular", ['motivo' => 'El cliente no recogió', 'tipo' => 'reembolso'])->assertOk()
            ->assertJsonPath('transaccion_pasarela.reembolso_estado', 'solicitado');
        Http::assertSent(fn (PeticionHttp $p) => $p->method() === 'POST' && str_ends_with($p->url(), '/transactions/tx-card/void') && $p->hasHeader('Authorization', 'Bearer prv_test_abc'));
        $this->assertSame('void-1', $conTarjeta->fresh()->reembolso_id);

        $pago2 = Pago::where('pedido_id', $id2)->sole();
        $this->postJson("/api/pagos/{$pago2->id}/anular", ['motivo' => 'Producto agotado', 'tipo' => 'reembolso'])->assertOk()
            ->assertJsonPath('transaccion_pasarela.reembolso_estado', 'no_soportado');
        $this->assertStringContainsString('panel de Wompi', $conNequi->fresh()->reembolso_mensaje);
        $this->assertSame(2, Auditoria::where('accion', 'reembolso_pasarela')->count());

        // Wompi confirma la anulación de la tarjeta con otro evento.
        $this->enviarEvento($this->evento($conTarjeta->referencia, 'VOIDED', 2000000, 'tx-card', 'CARD'))->assertOk();
        $this->assertSame(['anulado', 'aprobado'], [$conTarjeta->fresh()->estado, $conTarjeta->fresh()->reembolso_estado]);
    }

    public function test_cancelar_una_reserva_pagada_en_linea_pide_el_reembolso_y_un_pedido_cancelado_ya_pagado_queda_por_revisar(): void
    {
        Http::fake([self::API.'/transactions/tx-r/void' => Http::sequence()
            ->push(['error' => ['type' => 'INPUT_VALIDATION_ERROR']], 422)
            ->push(['data' => ['id' => 'void-r']], 201)]);
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        $creada = $this->postJson('/api/reservas', [
            'mesa_id' => $mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234', 'cliente_correo' => 'laura@correo.co',
            'fecha_hora' => '2026-10-14 20:00',
        ])->assertCreated();
        $this->postJson("/api/reservas/{$creada->json('id')}/deposito/en-linea", ['pasarela' => 'nequi', 'token' => $creada->json('token_publico')])->assertOk();
        $tx = TransaccionPasarela::sole();
        $this->enviarEvento($this->evento($tx->referencia, 'APPROVED', 2000000, 'tx-r', 'CARD'))->assertOk();

        $this->postJson("/api/reservas/enlace/{$creada->json('token_publico')}/cancelar")->assertOk();
        $this->assertSame('fallido', $tx->fresh()->reembolso_estado, 'si Wompi no lo acepta, la cancelación sigue en pie y queda para reintentar');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/pasarela/transacciones/{$tx->id}/reembolsar")->assertOk()->assertJsonPath('reembolso_estado', 'solicitado');
        $this->postJson("/api/pasarela/transacciones/{$tx->id}/reembolsar")->assertStatus(422);

        // Pedido pagado por la pasarela y luego cancelado: no se devuelve solo, pero queda marcado.
        [$id, $token] = $this->pedidoInvitado();
        $this->app['auth']->forgetGuards();
        $this->pagar($token);
        $txPedido = TransaccionPasarela::latest('id')->first();
        $this->enviarEvento($this->evento($txPedido->referencia, 'APPROVED', 2000000, 'tx-p'))->assertOk();
        app(PedidoService::class)->cancelar(Pedido::find($id), $this->admin, 'Se cerró la cocina');
        $this->assertTrue($txPedido->fresh()->requiere_revision);
        $this->assertStringContainsString('reembolsa el pago desde Caja', $txPedido->fresh()->motivo_revision);
    }

    public function test_la_conciliacion_cierra_lo_que_el_evento_no_alcanzo_a_avisar(): void
    {
        [, $token1] = $this->pedidoInvitado();
        $this->pagar($token1);
        $aprobada = TransaccionPasarela::latest('id')->first();
        [, $token2] = $this->pedidoInvitado();
        $this->pagar($token2);
        $vencida = TransaccionPasarela::latest('id')->first();

        Http::fake([self::API.'/transactions?*' => function (PeticionHttp $p) use ($aprobada) {
            return str_contains($p->url(), $aprobada->referencia)
                ? Http::response(['data' => [['id' => 'tx-c', 'reference' => $aprobada->referencia, 'status' => 'APPROVED', 'amount_in_cents' => 2000000, 'payment_method_type' => 'NEQUI']]])
                : Http::response(['data' => []]);
        }]);

        $this->travel(3)->minutes();
        $r = app(PasarelaPagoService::class)->conciliar();
        $this->assertSame(['revisadas' => 2, 'cerradas' => 1, 'abandonadas' => 0, 'sin_respuesta' => 0], $r);
        $this->assertSame('aprobado', $aprobada->fresh()->estado);
        $this->assertSame('pendiente', $vencida->fresh()->estado, 'el enlace aún no vence');

        $this->travel(40)->minutes();
        $this->artisan('pasarela:conciliar')->assertSuccessful();
        $this->assertSame('abandonado', $vencida->fresh()->estado);
        $this->assertSame('rechazado', $vencida->pagable->fresh()->estado);
    }

    public function test_RF_M05_030_si_wompi_rechaza_el_deposito_se_reintenta_desde_el_enlace_de_la_reserva(): void
    {
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        $creada = $this->postJson('/api/reservas', [
            'mesa_id' => $mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234', 'cliente_correo' => 'laura@correo.co',
            'fecha_hora' => '2026-10-14 20:00',
        ])->assertCreated();
        $token = $creada->json('token_publico');
        $this->postJson("/api/reservas/{$creada->json('id')}/deposito/en-linea", ['pasarela' => 'nequi', 'token' => $token])->assertOk();
        $primera = TransaccionPasarela::sole();
        $this->enviarEvento($this->evento($primera->referencia, 'DECLINED'))->assertOk();

        $this->assertSame(['pendiente_pago', 'rechazado'], [Reserva::sole()->estado, Reserva::sole()->deposito_estado]);
        $this->getJson("/api/pasarela/transacciones/{$primera->referencia}")->assertJsonPath('estado', 'rechazado')->assertJsonPath('reserva.enlace', $token);
        $this->getJson("/api/reservas/enlace/{$token}")->assertJsonPath('puede_pagar', true)->assertJsonPath('deposito_estado', 'rechazado');

        $this->postJson("/api/reservas/enlace/{$token}/deposito", ['pasarela' => 'bancolombia'])->assertOk()
            ->assertJsonPath('deposito_estado', 'pendiente')
            ->assertJsonPath('checkout.proveedor', 'wompi');
        $this->assertSame(2, TransaccionPasarela::count());

        $segunda = TransaccionPasarela::latest('id')->first();
        $this->enviarEvento($this->evento($segunda->referencia, 'APPROVED', 2000000, 'tx-ok'))->assertOk();
        $this->assertSame('confirmada', Reserva::sole()->estado);
        $this->getJson("/api/reservas/enlace/{$token}")->assertJsonPath('puede_pagar', false);
        $this->postJson("/api/reservas/enlace/{$token}/deposito", ['pasarela' => 'nequi'])->assertStatus(422);
    }

    public function test_un_reembolso_que_wompi_no_hace_queda_en_la_lista_por_revisar(): void
    {
        [$id, $token] = $this->pedidoInvitado();
        $this->pagar($token);
        $tx = TransaccionPasarela::sole();
        $this->enviarEvento($this->evento($tx->referencia, 'APPROVED'))->assertOk(); // Nequi

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/pagos/'.Pago::sole()->id.'/anular', ['motivo' => 'Producto agotado', 'tipo' => 'reembolso'])->assertOk();

        $this->getJson('/api/pasarela/transacciones?revision=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reembolso_estado', 'no_soportado')
            ->assertJsonPath('data.0.pedido_id', $id);
        // Una anulación (error de registro) no mueve dinero: no se pide a la pasarela.
        $this->assertSame(1, Auditoria::where('accion', 'reembolso_pasarela')->count());
    }

    public function test_sin_llaves_sigue_la_aprobacion_manual_de_siempre(): void
    {
        config(['services.wompi.secreto_eventos' => null]);
        [, $token] = $this->pedidoInvitado();

        $this->pagar($token)->assertCreated()->assertJsonPath('checkout', null);
        $this->assertSame(0, TransaccionPasarela::count());

        Sanctum::actingAs($this->mesero);
        $this->postJson('/api/pagos/'.Pago::sole()->id.'/confirmar-en-linea')->assertOk()->assertJsonPath('estado', 'aprobado');
        $registro = Auditoria::where('accion', 'confirmar_pago_en_linea')->sole();
        $this->assertSame(['manual', $this->mesero->id], [$registro->detalles['via'], $registro->user_id]);
        $this->assertSame(['manual', $this->mesero->id], [Pago::sole()->confirmado_via, Pago::sole()->confirmado_por]);
    }
}
