<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 2, tarea 3 del plan de implementación: el mismo catálogo en Menú, QR de mesa y pedido del
 * Mesero; extras generales asociables; bajas que se propagan a los combos; validaciones y
 * auditoría de cambios.
 *
 * RF-M04-003/010/011/020/021/022/023/027/028 · RN-M04-09/10/12/14 · HU-M04-004/008/011/012.
 */
class CatalogoCoherenteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private Categoria $hamburguesas;
    private Categoria $bebidas;
    private Producto $clasica;
    private Producto $doble;
    private Producto $gaseosa;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->hamburguesas = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->bebidas = Categoria::create(['nombre' => 'Bebidas']);
        $this->clasica = Producto::create(['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $this->doble = Producto::create(['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Doble', 'precio_base' => 30000, 'tipo_venta' => 'alimento']);
        $this->gaseosa = Producto::create(['categoria_id' => $this->bebidas->id, 'nombre' => 'Gaseosa', 'precio_base' => 5000, 'tipo_venta' => 'bebida']);
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
    }

    private function datosProducto(Producto $p, array $cambios = []): array
    {
        $p = $p->fresh(); // con los valores por defecto de la base (activo, disponible)

        return array_merge([
            'categoria_id' => $p->categoria_id, 'nombre' => $p->nombre, 'precio_base' => (float) $p->precio_base,
            'tipo_venta' => $p->tipo_venta, 'activo' => $p->activo, 'disponible' => $p->disponible,
        ], $cambios);
    }

    private function combo(array $productos, string $nombre = 'Combo pareja'): Combo
    {
        $combo = Combo::create(['nombre' => $nombre, 'precio' => 40000, 'tipo_venta' => 'alimento', 'activo' => true]);
        foreach ($productos as $p) {
            $combo->productos()->attach($p->id, ['cantidad' => 1]);
        }

        return $combo;
    }

    private function pedidoQr(array $linea)
    {
        return $this->postJson('/api/pedidos', ['tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $this->mesa->id, 'lineas' => [$linea]]);
    }

    // ---- Consultas públicas separadas de las de gestión (RF-M04-020/021, RN-M04-09) ----

    public function test_RF_M04_021_la_carta_publica_por_categorias_no_trae_productos_de_baja_ni_agotados(): void
    {
        $this->doble->update(['activo' => false]);
        $this->gaseosa->update(['disponible' => false]);

        $publico = collect($this->getJson('/api/categorias')->assertOk()->json());
        $this->assertSame(['Clásica'], collect($publico->firstWhere('nombre', 'Hamburguesas')['productos'])->pluck('nombre')->all());
        $this->assertSame([], $publico->firstWhere('nombre', 'Bebidas')['productos']);

        // El Administrador sí ve todo, para poder reactivarlo.
        Sanctum::actingAs($this->admin);
        $gestion = collect($this->getJson('/api/categorias')->assertOk()->json());
        $this->assertCount(2, $gestion->firstWhere('nombre', 'Hamburguesas')['productos']);
    }

    public function test_RF_M04_003_una_categoria_desactivada_oculta_sus_productos_y_combos_en_todos_los_canales(): void
    {
        $combo = $this->combo([$this->clasica, $this->gaseosa]);
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/categorias/{$this->bebidas->id}", ['nombre' => 'Bebidas', 'activo' => false])->assertOk();

        $this->app['auth']->forgetGuards();
        $nombres = collect($this->getJson('/api/productos')->json())->pluck('nombre');
        $this->assertNotContains('Gaseosa', $nombres);
        $this->assertNotContains($combo->id, collect($this->getJson('/api/combos')->json())->pluck('id'));

        // Ni por QR ni por el Mesero se puede pedir.
        $this->pedidoQr(['producto_id' => $this->gaseosa->id, 'cantidad' => 1])->assertStatus(422);
        $this->pedidoQr(['combo_id' => $combo->id, 'cantidad' => 1])->assertStatus(422);

        // El combo no se dio de baja: vuelve solo al reactivar la categoría.
        $this->assertTrue($combo->fresh()->activo);
    }

    // ---- Bajas que se propagan a los combos (RF-M04-027, RN-M04-12) ----

    public function test_RF_M04_027_dar_de_baja_un_producto_desactiva_sus_combos(): void
    {
        $combo = $this->combo([$this->clasica, $this->gaseosa]);
        $otro = $this->combo([$this->doble], 'Combo doble');
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/productos/{$this->gaseosa->id}", $this->datosProducto($this->gaseosa, ['activo' => false]))
            ->assertOk()
            ->assertJsonPath('combos_desactivados', ['Combo pareja']);

        $this->assertFalse($combo->fresh()->activo);
        $this->assertTrue($otro->fresh()->activo);
        $this->assertTrue(Auditoria::where('accion', 'desactivar_combo')->where('entidad_id', $combo->id)->exists());
    }

    public function test_RN_M04_12_el_combo_no_se_reactiva_con_un_componente_de_baja(): void
    {
        $combo = $this->combo([$this->clasica, $this->gaseosa]);
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/productos/{$this->gaseosa->id}", $this->datosProducto($this->gaseosa, ['activo' => false]))->assertOk();

        $datos = ['nombre' => 'Combo pareja', 'precio' => 40000, 'tipo_venta' => 'alimento', 'activo' => true];
        $this->putJson("/api/combos/{$combo->id}", $datos + ['productos' => [
            ['producto_id' => $this->clasica->id, 'cantidad' => 1], ['producto_id' => $this->gaseosa->id, 'cantidad' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('productos');
        $this->assertFalse($combo->fresh()->activo);

        // Reemplazando el componente sí se reactiva.
        $this->putJson("/api/combos/{$combo->id}", $datos + ['productos' => [
            ['producto_id' => $this->clasica->id, 'cantidad' => 1], ['producto_id' => $this->doble->id, 'cantidad' => 1],
        ]])->assertOk()->assertJsonPath('activo', true);
    }

    // ---- Extras generales en Menú, QR y Mesero (RF-M04-010/011, HU-M04-004) ----

    // CORREGIDO (16/10/2026, producción): antes un general sin asociar iba "con todo el menú", y a una
    // Limonada se le ofrecía "Extra queso". Según M04 (CA-M04-004) solo va con los productos asociados.
    public function test_RF_M04_010_un_extra_general_sin_asociar_no_se_ofrece_con_ningun_producto(): void
    {
        $salsa = Extra::create(['nombre' => 'Salsa de la casa', 'precio' => 1000, 'alcance' => 'general', 'activo' => true]);
        Extra::create(['nombre' => 'Inactivo', 'precio' => 1000, 'alcance' => 'general', 'activo' => false]);
        Extra::create(['nombre' => 'Tocineta', 'precio' => 3000, 'alcance' => 'especifico', 'producto_id' => $this->clasica->id, 'activo' => true]);
        Extra::create(['nombre' => 'Queso viejo', 'precio' => 3000, 'alcance' => 'especifico', 'producto_id' => $this->clasica->id, 'activo' => false]);

        $productos = collect($this->getJson('/api/productos')->assertOk()->json());

        $this->assertSame(['Tocineta'], collect($productos->firstWhere('nombre', 'Clásica')['extras_disponibles'])->pluck('nombre')->all());
        $this->assertSame([], $productos->firstWhere('nombre', 'Gaseosa')['extras_disponibles']);
        // El público no recibe los extras específicos inactivos.
        $this->assertSame(['Tocineta'], collect($productos->firstWhere('nombre', 'Clásica')['extras_especificos'])->pluck('nombre')->all());

        // Lo que no se ofrece tampoco se acepta.
        $this->pedidoQr(['producto_id' => $this->gaseosa->id, 'cantidad' => 1, 'extras_ids' => [$salsa->id]])->assertStatus(422);
        $this->pedidoQr(['producto_id' => $this->clasica->id, 'cantidad' => 1, 'extras_ids' => [$salsa->id]])->assertStatus(422);
    }

    public function test_la_migracion_asocia_los_generales_sueltos_solo_a_las_comidas(): void
    {
        $queso = Extra::create(['nombre' => 'Extra queso', 'precio' => 2000, 'alcance' => 'general', 'activo' => true]);
        $hielo = Extra::create(['nombre' => 'Hielo extra', 'precio' => 500, 'alcance' => 'general', 'activo' => true]);
        $hielo->productos()->attach($this->gaseosa->id); // ya estaba asociado: no se toca

        (require database_path('migrations/2026_10_23_000001_asociar_extras_generales_a_las_comidas.php'))->up();

        $this->assertEqualsCanonicalizing([$this->clasica->id, $this->doble->id], $queso->productos()->pluck('productos.id')->all());
        $this->assertSame([$this->gaseosa->id], $hielo->productos()->pluck('productos.id')->all());
    }

    public function test_un_extra_general_se_ofrece_con_una_bebida_solo_si_se_le_asocia(): void
    {
        $hielo = Extra::create(['nombre' => 'Hielo extra', 'precio' => 500, 'alcance' => 'general', 'activo' => true]);
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/productos/{$this->gaseosa->id}", $this->datosProducto($this->gaseosa, ['extras_generales' => [$hielo->id]]))->assertOk();

        $this->app['auth']->forgetGuards();
        $productos = collect($this->getJson('/api/productos')->json());
        $this->assertSame(['Hielo extra'], collect($productos->firstWhere('nombre', 'Gaseosa')['extras_disponibles'])->pluck('nombre')->all());
        $this->assertSame([], $productos->firstWhere('nombre', 'Clásica')['extras_disponibles']);
        $this->pedidoQr(['producto_id' => $this->gaseosa->id, 'cantidad' => 1, 'extras_ids' => [$hielo->id]])->assertCreated();
    }

    public function test_RF_M04_011_un_extra_general_asociado_solo_se_ofrece_y_se_acepta_con_sus_productos(): void
    {
        $papas = Extra::create(['nombre' => 'Papas extra', 'precio' => 4000, 'alcance' => 'general', 'activo' => true]);
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/productos/{$this->clasica->id}", $this->datosProducto($this->clasica, ['extras_generales' => [$papas->id]]))->assertOk();
        $this->putJson("/api/productos/{$this->doble->id}", $this->datosProducto($this->doble, ['extras_generales' => [$papas->id]]))->assertOk();

        $this->app['auth']->forgetGuards();
        $productos = collect($this->getJson('/api/productos')->json());
        $this->assertSame(['Papas extra'], collect($productos->firstWhere('nombre', 'Doble')['extras_disponibles'])->pluck('nombre')->all());
        $this->assertSame([], $productos->firstWhere('nombre', 'Gaseosa')['extras_disponibles']);

        // Lo que se ofrece es lo que se acepta.
        $this->pedidoQr(['producto_id' => $this->gaseosa->id, 'cantidad' => 1, 'extras_ids' => [$papas->id]])->assertStatus(422);
        $this->pedidoQr(['producto_id' => $this->clasica->id, 'cantidad' => 1, 'extras_ids' => [$papas->id]])
            ->assertCreated()->assertJsonPath('total', '24000.00');
    }

    public function test_solo_extras_generales_se_asocian_a_un_producto(): void
    {
        $tocineta = Extra::create(['nombre' => 'Tocineta', 'precio' => 3000, 'alcance' => 'especifico', 'producto_id' => $this->clasica->id, 'activo' => true]);
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/productos/{$this->doble->id}", $this->datosProducto($this->doble, ['extras_generales' => [$tocineta->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('extras_generales.0');
    }

    // ---- Validaciones (RF-M04-023/028, RN-M04-10/14) ----

    public function test_RN_M04_10_todo_precio_es_mayor_a_cero(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/productos', ['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Gratis', 'precio_base' => 0, 'tipo_venta' => 'alimento'])
            ->assertStatus(422)->assertJsonValidationErrors('precio_base');
        $this->postJson('/api/variantes', ['producto_id' => $this->clasica->id, 'nombre' => 'Mini', 'precio' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('precio');
        $this->postJson('/api/extras', ['nombre' => 'Hielo', 'precio' => 0, 'alcance' => 'general'])
            ->assertStatus(422)->assertJsonValidationErrors('precio');
        $this->postJson('/api/combos', ['nombre' => 'Combo cero', 'precio' => 0, 'tipo_venta' => 'otro', 'productos' => [['producto_id' => $this->clasica->id, 'cantidad' => 2]]])
            ->assertStatus(422)->assertJsonValidationErrors('precio');
    }

    public function test_RN_M04_14_nombres_repetidos_se_rechazan_con_un_mensaje_claro(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/productos', ['categoria_id' => $this->bebidas->id, 'nombre' => 'Clásica', 'precio_base' => 1000, 'tipo_venta' => 'bebida'])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Ya existe un producto con ese nombre.');
        $this->postJson('/api/categorias', ['nombre' => 'Bebidas'])
            ->assertStatus(422)->assertJsonPath('errors.nombre.0', 'Ya existe una categoría con ese nombre.');
        // Guardar el mismo producto con su propio nombre no choca consigo mismo.
        $this->putJson("/api/productos/{$this->clasica->id}", $this->datosProducto($this->clasica))->assertOk();
    }

    // ---- Auditoría de cambios del catálogo (RF-M04-022) ----

    public function test_RF_M04_022_crear_y_cambiar_el_precio_queda_en_la_auditoria(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/productos/{$this->clasica->id}", $this->datosProducto($this->clasica, ['precio_base' => 22000]))->assertOk();
        $id = $this->postJson('/api/categorias', ['nombre' => 'Postres'])->assertCreated()->json('id');

        $edicion = Auditoria::where('accion', 'editar_producto')->where('entidad_id', $this->clasica->id)->sole();
        $this->assertSame($this->admin->id, $edicion->user_id);
        $this->assertEquals(20000, $edicion->detalles['cambios']['precio_base']['antes']);
        $this->assertEquals(22000, $edicion->detalles['cambios']['precio_base']['despues']);
        $this->assertTrue(Auditoria::where('accion', 'crear_categoria')->where('entidad_id', $id)->exists());

        // Guardar sin cambios no deja registro.
        $this->putJson("/api/productos/{$this->clasica->id}", $this->datosProducto($this->clasica->fresh()))->assertOk();
        $this->assertSame(1, Auditoria::where('accion', 'editar_producto')->count());
    }
}
