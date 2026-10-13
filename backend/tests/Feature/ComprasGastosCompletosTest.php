<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CompraGasto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 2, tarea 4 del plan de implementación: compras y gastos completos (M03).
 *
 * RF-M03-01/03/04/07/13/19/30 · RN-M03-02/03/14 · RNF-M03-06/11 · HU-M03-02/03 · CA-M03-02/03.
 */
class ComprasGastosCompletosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'America/Bogota'));

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'tipo' => 'compra', 'categoria' => 'Alimentos', 'descripcion' => 'Carne molida', 'valor' => 85000,
            'metodo_pago' => 'efectivo', 'fecha' => '2026-10-13',
        ], $cambios);
    }

    // ---- Registro completo (RF-M03-01/03/04, RN-M03-02/03) ----

    public function test_RF_M03_03_registra_cantidad_unidad_y_observaciones_informativas(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/compras-gastos', $this->datos([
            'cantidad' => 5, 'unidad' => 'kilos', 'proveedor' => 'Carnes La 70', 'observaciones' => 'Para el fin de semana',
        ]))->assertCreated()
            ->assertJsonPath('cantidad', '5.00')
            ->assertJsonPath('unidad', 'kilos')
            ->assertJsonPath('observaciones', 'Para el fin de semana')
            ->assertJsonPath('registrado_por_usuario.nombre', 'Admin');

        $this->assertTrue(Auditoria::where('accion', 'registrar_compra_gasto')->exists());
    }

    public function test_RN_M03_03_la_categoria_es_obligatoria_y_de_su_tipo(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/compras-gastos', $this->datos(['categoria' => null]))
            ->assertStatus(422)->assertJsonPath('errors.categoria.0', 'Elige la categoría.');
        // "Transporte" es de gastos, no de compras.
        $this->postJson('/api/compras-gastos', $this->datos(['categoria' => 'Transporte']))
            ->assertStatus(422)->assertJsonValidationErrors('categoria');
        $this->postJson('/api/compras-gastos', $this->datos(['tipo' => 'gasto', 'categoria' => 'Transporte', 'descripcion' => 'Domicilio de insumos']))
            ->assertCreated();

        $this->getJson('/api/compras-gastos/categorias')->assertOk()
            ->assertJsonPath('compra', ['Alimentos', 'Bebidas', 'Empaques', 'Insumos', 'Otros']);
    }

    public function test_RN_M03_14_la_fecha_de_la_compra_no_puede_ser_futura(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/compras-gastos', $this->datos(['fecha' => '2026-10-14']))
            ->assertStatus(422)->assertJsonPath('errors.fecha.0', 'La fecha de la compra no puede ser futura.');
        // Puede ser anterior al día en que se registra.
        $this->postJson('/api/compras-gastos', $this->datos(['fecha' => '2026-10-10']))->assertCreated();
    }

    // ---- Soporte (RF-M03-07, RNF-M03-06/11, HU-M03-02) ----

    public function test_RF_M03_07_adjunta_un_soporte_jpg_png_o_pdf_al_registrar(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->post('/api/compras-gastos', $this->datos(['soporte' => UploadedFile::fake()->image('factura.jpg')]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('tiene_soporte', true)
            ->assertJsonPath('soporte_nombre', 'factura.jpg')
            ->assertJsonMissingPath('soporte_path')
            ->json('id');

        $registro = CompraGasto::findOrFail($id);
        Storage::disk('local')->assertExists($registro->soporte_path);

        // Un PDF y un PNG también.
        $this->post("/api/compras-gastos/{$id}/soporte", ['soporte' => UploadedFile::fake()->create('recibo.pdf', 200, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('soporte_nombre', 'recibo.pdf');
        $this->post("/api/compras-gastos/{$id}/soporte", ['soporte' => UploadedFile::fake()->image('foto.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('soporte_nombre', 'foto.png');
        // El soporte anterior no se borra: queda como evidencia.
        $this->assertCount(3, Storage::disk('local')->allFiles("soportes-compras-gastos/{$id}"));
        $this->assertSame(2, Auditoria::where('accion', 'adjuntar_soporte_compra_gasto')->count());
    }

    public function test_RNF_M03_06_rechaza_formatos_no_permitidos_y_archivos_muy_pesados(): void
    {
        Sanctum::actingAs($this->admin);

        $this->post('/api/compras-gastos', $this->datos(['soporte' => UploadedFile::fake()->create('factura.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('soporte');
        $this->post('/api/compras-gastos', $this->datos(['soporte' => UploadedFile::fake()->create('virus.pdf', 50, 'application/x-msdownload')]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('soporte');
        $this->post('/api/compras-gastos', $this->datos(['soporte' => UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf')]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('soporte');

        $this->assertSame(0, CompraGasto::count());
    }

    public function test_RNF_M03_11_el_soporte_solo_lo_ve_el_administrador(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->post('/api/compras-gastos', $this->datos(['soporte' => UploadedFile::fake()->create('recibo.pdf', 20, 'application/pdf')]), ['Accept' => 'application/json'])
            ->assertCreated()->json('id');

        $respuesta = $this->get("/api/compras-gastos/{$id}/soporte");
        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));

        Sanctum::actingAs($this->mesero);
        $this->getJson("/api/compras-gastos/{$id}/soporte")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/compras-gastos/{$id}/soporte")->assertUnauthorized();
    }

    // ---- Historial con filtros y totales (RF-M03-13, HU-M03-03) ----

    public function test_RF_M03_13_filtra_por_fecha_categoria_forma_de_pago_proveedor_y_tipo(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/compras-gastos', $this->datos(['fecha' => '2026-10-10', 'proveedor' => 'Carnes La 70']))->assertCreated();
        $this->postJson('/api/compras-gastos', $this->datos(['categoria' => 'Bebidas', 'descripcion' => 'Gaseosas', 'valor' => 40000, 'metodo_pago' => 'transferencia', 'proveedor' => 'Postobón']))->assertCreated();
        $this->postJson('/api/compras-gastos', $this->datos(['tipo' => 'gasto', 'categoria' => 'Servicios', 'descripcion' => 'Gas', 'valor' => 30000]))->assertCreated();
        $anulado = $this->postJson('/api/compras-gastos', $this->datos(['descripcion' => 'Duplicado', 'valor' => 10000]))->json('id');
        $this->postJson("/api/compras-gastos/{$anulado}/anular", ['motivo' => 'Registrado dos veces'])->assertOk();

        $this->getJson('/api/compras-gastos?desde=2026-10-11&hasta=2026-10-13')->assertJsonPath('total', 3);
        $this->getJson('/api/compras-gastos?categoria=Bebidas')->assertJsonPath('total', 1)->assertJsonPath('data.0.descripcion', 'Gaseosas');
        $this->getJson('/api/compras-gastos?metodo_pago=transferencia')->assertJsonPath('total', 1);
        $this->getJson('/api/compras-gastos?proveedor=carnes')->assertJsonPath('total', 1)->assertJsonPath('data.0.proveedor', 'Carnes La 70');
        $this->getJson('/api/compras-gastos?tipo=gasto')->assertJsonPath('total', 1);
        $this->getJson('/api/compras-gastos?estado=anulado')->assertJsonPath('total', 1);

        // Totales de lo filtrado: solo activos (RN-M03-04).
        $this->getJson('/api/compras-gastos')
            ->assertJsonPath('total', 4)
            ->assertJsonPath('totales.total_compras', 125000)
            ->assertJsonPath('totales.total_gastos', 30000);
    }

    public function test_el_historial_es_solo_del_administrador(): void
    {
        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/compras-gastos')->assertForbidden();
        $this->postJson('/api/compras-gastos', $this->datos())->assertForbidden();
    }

    // ---- Trazabilidad de correcciones y anulaciones (RF-M03-19, RNF-M03-04) ----

    public function test_RF_M03_19_editar_y_anular_quedan_con_usuario_fecha_y_cambios(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/compras-gastos', $this->datos())->json('id');

        $this->putJson("/api/compras-gastos/{$id}", ['valor' => 90000, 'unidad' => 'kilos'])
            ->assertOk()
            ->assertJsonPath('editado_por_usuario.nombre', 'Admin');
        $registro = CompraGasto::findOrFail($id);
        $this->assertNotNull($registro->editado_at);
        $cambios = Auditoria::where('accion', 'editar_compra_gasto')->sole()->detalles['cambios'];
        $this->assertEquals(85000, $cambios['valor']['antes']);
        $this->assertEquals(90000, $cambios['valor']['despues']);

        $this->postJson("/api/compras-gastos/{$id}/anular", ['motivo' => 'Se devolvió la carne'])
            ->assertOk()
            ->assertJsonPath('anulado_por_usuario.nombre', 'Admin');
        $this->assertNotNull(CompraGasto::findOrFail($id)->anulado_at);
        $this->assertSame('Se devolvió la carne', Auditoria::where('accion', 'anular_compra_gasto')->sole()->detalles['motivo']);
    }

    public function test_un_registro_viejo_con_otra_categoria_se_puede_editar_sin_cambiarla(): void
    {
        $viejo = CompraGasto::create([
            'tipo' => 'compra', 'categoria' => 'Verduras', 'descripcion' => 'Tomate', 'valor' => 12000,
            'metodo_pago' => 'efectivo', 'fecha' => '2026-10-01', 'registrado_por' => $this->admin->id, 'estado' => 'activo',
        ]);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/compras-gastos/{$viejo->id}", ['categoria' => 'Verduras', 'valor' => 13000])->assertOk();
        // Cambiarla sí exige una de la lista.
        $this->putJson("/api/compras-gastos/{$viejo->id}", ['categoria' => 'Frutas'])->assertStatus(422)->assertJsonValidationErrors('categoria');
        $this->putJson("/api/compras-gastos/{$viejo->id}", ['categoria' => 'Alimentos'])->assertOk();
    }
}
