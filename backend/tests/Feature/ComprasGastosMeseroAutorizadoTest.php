<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CierreDiario;
use App\Models\CompraGasto;
use App\Models\User;
use App\Services\CajaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 7, tarea 2 (13/10/2026): el «usuario autorizado» de M03 (RNF-TRANS-012, RF-M03-32,
 * HU-M03-01 a 03; decisión del 13/10/2026 que revisa D7). El Administrador le da a un Mesero el
 * permiso de registrar compras y gastos: registra, adjunta el soporte y ve solo lo suyo. Editar,
 * anular, el historial de todos y el cierre diario siguen siendo del Administrador.
 */
class ComprasGastosMeseroAutorizadoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'America/Bogota'));

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mario', 'email' => 'mario@test.co', 'password' => bcrypt('secreto123'), 'activo' => true]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'tipo' => 'gasto', 'categoria' => 'Transporte', 'descripcion' => 'Taxi por el pan', 'valor' => 12000,
            'metodo_pago' => 'efectivo', 'fecha' => '2026-10-13',
        ], $cambios);
    }

    private function darPermiso(bool $permiso = true): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/usuarios/{$this->mesero->id}", [
            'nombre' => 'Mario', 'email' => 'mario@test.co', 'role' => 'mesero', 'puede_registrar_gastos' => $permiso,
        ])->assertOk()->assertJsonPath('usuario.puede_registrar_gastos', $permiso);
    }

    public function test_sin_permiso_el_mesero_no_entra_y_el_administrador_se_lo_da(): void
    {
        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/compras-gastos')->assertForbidden();
        $this->postJson('/api/compras-gastos', $this->datos())->assertForbidden();
        $this->getJson('/api/auth/me')->assertJsonPath('puede_registrar_gastos', false);

        $this->darPermiso();

        $registro = Auditoria::where('accion', 'cambiar_permiso_gastos')->sole();
        $this->assertSame($this->admin->id, $registro->user_id);
        $this->assertTrue($registro->detalles['cambios']['puede_registrar_gastos']['despues']);
        $this->assertSame(0, $this->mesero->tokens()->count(), 'No es un cambio de rol: no hace falta cerrarle la sesión.');

        Sanctum::actingAs($this->mesero->fresh());
        $this->getJson('/api/auth/me')->assertJsonPath('puede_registrar_gastos', true);
        $this->getJson('/api/compras-gastos/categorias')->assertOk();
    }

    public function test_el_mesero_autorizado_registra_adjunta_y_ve_solo_lo_suyo(): void
    {
        app(CajaService::class)->abrir($this->admin, 100000);
        Sanctum::actingAs($this->admin);
        $delAdmin = $this->post('/api/compras-gastos', ['tipo' => 'compra', 'categoria' => 'Alimentos', 'descripcion' => 'Carne', 'valor' => 50000, 'metodo_pago' => 'transferencia', 'fecha' => '2026-10-13', 'soporte' => UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');
        $this->darPermiso();

        Sanctum::actingAs($this->mesero->fresh());
        $id = $this->postJson('/api/compras-gastos', $this->datos())
            ->assertCreated()->assertJsonPath('registrado_por_usuario.nombre', 'Mario')->json('id');
        $this->post("/api/compras-gastos/{$id}/soporte", ['soporte' => UploadedFile::fake()->image('recibo.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->get("/api/compras-gastos/{$id}/soporte")->assertOk();

        // Un gasto en efectivo sale de la caja del día, como cuando lo registra el Administrador.
        $this->assertEquals(88000, app(CajaService::class)->turnoAbierto()->fresh()->efectivo_esperado);

        // Solo lo suyo: ni la lista ni los totales traen lo del Administrador, ni su soporte.
        $this->getJson('/api/compras-gastos')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('totales.total_compras', 0)->assertJsonPath('totales.total_gastos', 12000);
        $this->getJson("/api/compras-gastos/{$delAdmin}/soporte")->assertNotFound();
        $this->postJson("/api/compras-gastos/{$delAdmin}/soporte", [])->assertNotFound();

        // Editar, anular y el cierre diario siguen siendo del Administrador.
        $this->putJson("/api/compras-gastos/{$id}", ['valor' => 1000])->assertForbidden();
        $this->postJson("/api/compras-gastos/{$id}/anular", ['motivo' => 'Me equivoqué'])->assertForbidden();
        $this->getJson('/api/cierres-diarios')->assertForbidden();
        $this->getJson('/api/cierres-diarios/previa?fecha=2026-10-13')->assertForbidden();

        // El Administrador ve los dos registros.
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/compras-gastos')->assertJsonPath('total', 2);
        $this->assertSame($this->mesero->id, CompraGasto::findOrFail($id)->registrado_por);
    }

    public function test_un_dia_cerrado_lo_registra_el_administrador(): void
    {
        $this->darPermiso();
        CierreDiario::create([
            'fecha' => '2026-10-12', 'total_ventas' => 0, 'total_compras' => 0, 'total_gastos' => 0,
            'resultado_operativo' => 0, 'cerrado_por' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->mesero->fresh());
        $this->postJson('/api/compras-gastos', $this->datos(['fecha' => '2026-10-12', 'password_confirmacion' => 'secreto123', 'motivo_correccion' => 'Se me olvidó']))
            ->assertStatus(422)->assertJsonPath('message', 'El día 12/10/2026 ya tiene cierre: pídele al Administrador que lo registre.');
        $this->assertSame(0, CompraGasto::count());
    }

    public function test_el_permiso_es_solo_de_un_mesero(): void
    {
        $this->darPermiso();

        // Al pasar a otro rol, se le quita (un Cliente nunca lo tiene).
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/usuarios/{$this->mesero->id}", [
            'nombre' => 'Mario', 'email' => 'mario@test.co', 'role' => 'cliente', 'puede_registrar_gastos' => true,
        ])->assertOk()->assertJsonPath('usuario.puede_registrar_gastos', false);

        $this->assertFalse($this->mesero->fresh()->puedeRegistrarComprasGastos());
        $this->assertTrue($this->admin->puedeRegistrarComprasGastos());
    }
}
