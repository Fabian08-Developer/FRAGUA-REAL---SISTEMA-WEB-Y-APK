<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Mejora (16/10/2026): importación masiva de productos desde Excel o CSV en Catálogo. Plantilla, vista
 * previa sin guardar, e importación de todo o nada, con las mismas reglas del formulario
 * (RN-M04-10/14, RF-M04-008/042) y auditoría (RF-M04-022).
 */
class ImportacionProductosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;
    private Categoria $hamburguesas;
    private Producto $clasica;

    private const TITULOS = ['Categoría', 'Nombre', 'Descripción', 'Precio base', 'Tipo de venta', 'Presentaciones', 'Disponible'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->hamburguesas = Categoria::create(['nombre' => 'Hamburguesas']);
        $this->clasica = Producto::create(['categoria_id' => $this->hamburguesas->id, 'nombre' => 'Hamburguesa Clásica', 'precio_base' => 18000, 'tipo_venta' => 'alimento']);
        Variante::create(['producto_id' => $this->clasica->id, 'nombre' => 'Personal', 'precio' => 18000]);
    }

    /** @param list<list<mixed>> $filas */
    private function excel(array $filas): UploadedFile
    {
        $libro = new Spreadsheet();
        // strictNullComparison: sin eso, fromArray() no escribe las celdas con 0.
        $libro->getActiveSheet()->fromArray([self::TITULOS, ...$filas], null, 'A1', true);
        $ruta = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'productos.xlsx', null, null, true);
    }

    private function enviar(string $ruta, UploadedFile $archivo, bool $actualizar = true)
    {
        Sanctum::actingAs($this->admin);

        return $this->post($ruta, ['archivo' => $archivo, 'actualizar' => $actualizar ? '1' : '0'], ['Accept' => 'application/json']);
    }

    public function test_la_plantilla_trae_las_columnas_y_solo_la_baja_el_administrador(): void
    {
        Sanctum::actingAs($this->admin);
        $respuesta = $this->get('/api/productos/importacion/plantilla')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $respuesta->headers->get('Content-Type'));

        $ruta = tempnam(sys_get_temp_dir(), 'pla').'.xlsx';
        file_put_contents($ruta, $respuesta->getContent());
        $hoja = IOFactory::load($ruta)->getSheetByName('Productos');
        $this->assertSame(self::TITULOS, $hoja->rangeToArray('A1:G1')[0]);
        $this->assertNotNull(IOFactory::load($ruta)->getSheetByName('Instrucciones'));

        Sanctum::actingAs($this->mesero);
        $this->getJson('/api/productos/importacion/plantilla')->assertForbidden();
    }

    public function test_la_vista_previa_dice_que_haria_sin_guardar_nada(): void
    {
        $archivo = $this->excel([
            ['Hamburguesas', 'Hamburguesa BBQ', 'Doble carne', 24000, 'Alimento', 'Personal:24000 | Familiar:40000', 'Sí'],
            ['Bebidas', 'Limonada', '', 6000, 'bebida', '', ''],
            ['', 'hamburguesa clásica', '', 19000, '', 'Personal:19000 | Familiar:33000', ''],
            ['Postres', '', '', 5000, 'Otro', '', ''],
            ['Postres', 'Torta', '', 0, 'Otro', '', ''],
            ['Postres', 'Helado', '', 7000, 'Comida', '', ''],
            ['Postres', 'Limonada', '', 7000, 'Bebida', '', ''],
            ['Postres', 'Malteada', '', 9000, 'Bebida', 'Grande 12000', 'Tal vez'],
        ]);

        $vista = $this->enviar('/api/productos/importacion/vista-previa', $archivo)->assertOk()->json();
        $porFila = collect($vista['filas'])->keyBy('fila');

        $this->assertSame('crear', $porFila[2]['accion']);
        $this->assertCount(2, $porFila[2]['presentaciones']);
        $this->assertSame('crear', $porFila[3]['accion']);
        $this->assertEquals(6000, $porFila[3]['precio_base']);
        $this->assertSame('bebida', $porFila[3]['tipo_venta'], 'El tipo de venta se reconoce sin importar mayúsculas.');
        $this->assertSame('actualizar', $porFila[4]['accion'], 'El nombre se reconoce sin importar mayúsculas.');
        $this->assertContains('nueva presentación Familiar', $porFila[4]['cambios']);
        $this->assertSame(['Falta el nombre.'], $porFila[5]['errores']);
        $this->assertSame(['El precio base debe ser mayor a cero.'], $porFila[6]['errores']);
        $this->assertSame(['El tipo de venta debe ser Alimento, Bebida u Otro.'], $porFila[7]['errores']);
        $this->assertSame(['El nombre se repite en la fila 3.'], $porFila[8]['errores']);
        $this->assertCount(2, $porFila[9]['errores']);
        $this->assertSame(['Bebidas', 'Postres'], $vista['categorias_nuevas']);
        $this->assertSame(['total' => 8, 'crear' => 2, 'actualizar' => 1, 'sin_cambios' => 0, 'errores' => 5], $vista['resumen']);

        // Nada se guardó.
        $this->assertSame(1, Producto::count());
        $this->assertSame(1, Categoria::count());
    }

    public function test_con_errores_no_se_importa_nada(): void
    {
        $archivo = $this->excel([
            ['Bebidas', 'Gaseosa', '', 5000, 'Bebida', '', ''],
            ['Bebidas', 'Jugo', '', -1, 'Bebida', '', ''],
        ]);

        $this->enviar('/api/productos/importacion', $archivo)->assertStatus(422)
            ->assertJsonPath('message', 'El archivo tiene 1 fila(s) con errores. Corrígelas y vuelve a intentarlo; no se guardó nada.');
        $this->assertNull(Producto::where('nombre', 'Gaseosa')->first());
        $this->assertNull(Categoria::where('nombre', 'Bebidas')->first());
    }

    public function test_importa_crea_actualiza_y_queda_en_la_auditoria(): void
    {
        $archivo = $this->excel([
            ['Hamburguesas', 'Hamburguesa BBQ', 'Doble carne', 24000, 'Alimento', 'Personal:24000 | Familiar:40000', 'Sí'],
            ['Bebidas', 'Limonada', '', 6000, 'Bebida', '', 'No'],
            ['', 'Hamburguesa Clásica', '', 19000, '', 'Personal:19000 | Familiar:33000', ''],
        ]);

        $this->enviar('/api/productos/importacion', $archivo)->assertOk()
            ->assertJsonPath('resumen.crear', 2)
            ->assertJsonPath('resumen.actualizar', 1)
            ->assertJsonPath('categorias_nuevas', ['Bebidas']);

        $bbq = Producto::where('nombre', 'Hamburguesa BBQ')->firstOrFail();
        $this->assertSame($this->hamburguesas->id, $bbq->categoria_id);
        $this->assertSame(['Familiar' => '40000.00', 'Personal' => '24000.00'], $bbq->variantes()->orderBy('nombre')->pluck('precio', 'nombre')->all());
        $limonada = Producto::where('nombre', 'Limonada')->firstOrFail();
        $this->assertFalse($limonada->disponible);
        $this->assertSame('bebida', $limonada->tipo_venta);
        $this->assertSame('Bebidas', $limonada->categoria->nombre);

        // La Clásica cambió de precio, su Personal también y ganó la Familiar; lo demás quedó igual.
        $clasica = $this->clasica->fresh();
        $this->assertEquals(19000, $clasica->precio_base);
        $this->assertSame('alimento', $clasica->tipo_venta);
        $this->assertSame(['Familiar' => '33000.00', 'Personal' => '19000.00'], $clasica->variantes()->orderBy('nombre')->pluck('precio', 'nombre')->all());

        $this->assertTrue(Auditoria::where('accion', 'importar_productos')->exists());
        $this->assertSame(2, Auditoria::where('accion', 'crear_producto')->count());
        $this->assertTrue(Auditoria::where('accion', 'editar_producto')->where('entidad_id', $this->clasica->id)->exists());
        $this->assertTrue(Auditoria::where('accion', 'crear_categoria')->exists());
    }

    public function test_sin_actualizar_un_nombre_existente_es_un_error(): void
    {
        $archivo = $this->excel([['Hamburguesas', 'Hamburguesa Clásica', '', 25000, 'Alimento', '', '']]);

        $vista = $this->enviar('/api/productos/importacion/vista-previa', $archivo, false)->assertOk()->json();
        $this->assertSame('error', $vista['filas'][0]['accion']);
        $this->assertStringContainsString('Ya existe un producto con ese nombre', $vista['filas'][0]['errores'][0]);
    }

    public function test_acepta_un_csv_de_excel_en_espanol(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($ruta, "Categoría;Nombre;Descripción;Precio base;Tipo de venta;Presentaciones;Disponible\nBebidas;Jugo de mora;En agua;\"7.000\";Bebida;;Sí\n");

        $this->enviar('/api/productos/importacion', new UploadedFile($ruta, 'productos.csv', null, null, true))
            ->assertOk()->assertJsonPath('resumen.crear', 1);
        $this->assertEquals(7000, Producto::where('nombre', 'Jugo de mora')->value('precio_base'));
    }

    public function test_solo_el_administrador_importa(): void
    {
        Sanctum::actingAs($this->mesero);
        $this->post('/api/productos/importacion', ['archivo' => $this->excel([['Bebidas', 'Agua', '', 3000, 'Bebida', '', '']])], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->assertNull(Producto::where('nombre', 'Agua')->first());
    }
}
