<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Combo;
use App\Models\Configuracion;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Datos de prueba para desarrollo local: un usuario por cada rol, el
     * catálogo real de categorías/productos/variantes/extras/combos y un
     * juego de mesas. No usa factories porque el esquema de usuarios
     * (role/nombre/telefono, sin "name") es propio del proyecto.
     *
     * Uso: php artisan db:seed (o de una vez con la migración: php artisan migrate:fresh --seed)
     */
    public function run(): void
    {
        $this->crearUsuarios();
        $categorias = $this->crearCategorias();
        $productos = $this->crearProductos($categorias);
        $this->crearVariantes($productos);
        $this->crearExtras($productos);
        $this->crearCombos($productos);
        $this->crearMesas();
        $this->crearConfiguraciones();
    }

    private function crearUsuarios(): void
    {
        $clave = Hash::make('password');

        // Contraseña de los 5 para pruebas: "password". Desde la Fase 3 también entran con su
        // nombre de usuario: superadmin (M09, 19/10/2026), soporte (M10, 20/10/2026), admin, mesero y cliente.
        User::query()->firstOrCreate(
            ['email' => 'superadmin@fraguareal.com'],
            ['role' => 'superadmin', 'nombre' => 'SuperAdmin Fragua Real', 'username' => 'superadmin', 'password' => $clave, 'activo' => true]
        );

        // M10 (20/10/2026): la cuenta de Soporte para probar en local (solo consulta).
        User::query()->firstOrCreate(
            ['email' => 'soporte@fraguareal.com'],
            ['role' => 'soporte', 'nombre' => 'Soporte Fragua Real', 'username' => 'soporte', 'password' => $clave, 'activo' => true]
        );

        User::query()->firstOrCreate(
            ['email' => 'admin@fraguareal.com'],
            ['role' => 'administrador', 'nombre' => 'Admin Fragua Real', 'username' => 'admin', 'password' => $clave, 'activo' => true]
        );

        User::query()->firstOrCreate(
            ['email' => 'mesero@fraguareal.com'],
            ['role' => 'mesero', 'nombre' => 'Mesero Demo', 'username' => 'mesero', 'password' => $clave, 'activo' => true]
        );

        User::query()->firstOrCreate(
            ['email' => 'cliente@fraguareal.com'],
            ['role' => 'cliente', 'nombre' => 'Cliente Demo', 'username' => 'cliente', 'telefono' => '3001234567', 'password' => $clave, 'activo' => true]
        );
    }

    /** @return array<string, Categoria> */
    private function crearCategorias(): array
    {
        $nombres = ['Hamburguesas', 'Perros Calientes', 'Salchipapas', 'Picadas', 'Bebidas', 'Postres'];

        $categorias = [];
        foreach ($nombres as $nombre) {
            $categorias[$nombre] = Categoria::query()->firstOrCreate(['nombre' => $nombre]);
        }

        return $categorias;
    }

    /**
     * @param  array<string, Categoria>  $categorias
     * @return array<string, Producto>
     */
    private function crearProductos(array $categorias): array
    {
        // [nombre, categoria, descripcion, precio_base, tipo_venta]
        $datos = [
            ['Hamburguesa Clásica', 'Hamburguesas', 'Carne de res, queso cheddar, lechuga, tomate y nuestra salsa de la casa.', 18000, 'alimento'],
            ['Hamburguesa BBQ', 'Hamburguesas', 'Doble carne, tocineta, aros de cebolla y salsa BBQ ahumada.', 24000, 'alimento'],
            ['Hamburguesa Especial Fragua Real', 'Hamburguesas', 'La corona de la casa: doble carne, queso doble, tocineta, huevo y salsa especial.', 28000, 'alimento'],
            ['Perro Clásico', 'Perros Calientes', 'Salchicha, papitas, queso rallado y salsas.', 12000, 'alimento'],
            ['Perro Especial', 'Perros Calientes', 'Doble salchicha, tocineta, queso fundido y maíz tierno.', 16000, 'alimento'],
            ['Salchipapa Personal', 'Salchipapas', 'Papa a la francesa con salchicha, queso y salsas.', 14000, 'alimento'],
            ['Salchipapa Especial', 'Salchipapas', 'Papa, salchicha, carne desmechada, tocineta y queso fundido.', 19000, 'alimento'],
            ['Picada Familiar', 'Picadas', 'Chorizo, carne asada, papa criolla, arepa y ensalada. Para compartir.', 45000, 'alimento'],
            ['Picada Fragua Real', 'Picadas', 'La picada más completa: chorizo, carne, pollo, morcilla, papa criolla y arepa.', 58000, 'alimento'],
            ['Gaseosa 400ml', 'Bebidas', 'Coca-Cola, Manzana Postobón o Colombiana.', 5000, 'bebida'],
            ['Limonada Natural', 'Bebidas', 'Limonada de panela o natural, bien fría.', 6000, 'bebida'],
            ['Jugo en Agua', 'Bebidas', 'Mora, lulo, mango o maracuyá.', 7000, 'bebida'],
            ['Helado de Vainilla', 'Postres', 'Copa de helado con salsa de chocolate.', 8000, 'otro'],
            ['Torta del Día', 'Postres', 'Pregunta por la torta disponible hoy.', 9000, 'otro'],
        ];

        $productos = [];
        foreach ($datos as [$nombre, $categoria, $descripcion, $precio, $tipoVenta]) {
            $productos[$nombre] = Producto::query()->firstOrCreate(
                ['nombre' => $nombre],
                [
                    'categoria_id' => $categorias[$categoria]->id,
                    'descripcion' => $descripcion,
                    'precio_base' => $precio,
                    'tipo_venta' => $tipoVenta,
                    'disponible' => true,
                ]
            );
        }

        return $productos;
    }

    /** @param array<string, Producto> $productos */
    private function crearVariantes(array $productos): void
    {
        $variantes = [
            'Hamburguesa Clásica' => [['Personal', 18000], ['Familiar', 32000]],
            'Salchipapa Personal' => [['Personal', 14000], ['Familiar', 26000]],
        ];

        foreach ($variantes as $productoNombre => $opciones) {
            foreach ($opciones as [$nombre, $precio]) {
                Variante::query()->firstOrCreate(
                    ['producto_id' => $productos[$productoNombre]->id, 'nombre' => $nombre],
                    ['precio' => $precio]
                );
            }
        }
    }

    /** @param array<string, Producto> $productos */
    private function crearExtras(array $productos): void
    {
        // Generales (RF-M04-010/011): reutilizables, pero solo se ofrecen con los productos a los que se
        // asocian. Aquí, con las comidas (tipo Alimento); no con bebidas ni postres.
        $generales = [
            ['Extra queso', 2000],
            ['Tocineta extra', 3000],
            ['Salsa especial', 1000],
        ];
        $comidas = collect($productos)->where('tipo_venta', 'alimento')->pluck('id')->all();

        foreach ($generales as [$nombre, $precio]) {
            $extra = Extra::query()->firstOrCreate(
                ['nombre' => $nombre, 'alcance' => 'general'],
                ['precio' => $precio, 'producto_id' => null]
            );
            $extra->productos()->syncWithoutDetaching($comidas);
        }

        // Específico: solo puede agregarse a la Salchipapa Especial.
        Extra::query()->firstOrCreate(
            ['nombre' => 'Doble carne desmechada', 'alcance' => 'especifico', 'producto_id' => $productos['Salchipapa Especial']->id],
            ['precio' => 5000]
        );
    }

    /** @param array<string, Producto> $productos */
    private function crearCombos(array $productos): void
    {
        $combo = Combo::query()->firstOrCreate(
            ['nombre' => 'Combo Clásico'],
            ['precio' => 21000, 'tipo_venta' => 'alimento']
        );

        DB::table('combo_producto')->updateOrInsert(
            ['combo_id' => $combo->id, 'producto_id' => $productos['Hamburguesa Clásica']->id],
            ['cantidad' => 1]
        );

        DB::table('combo_producto')->updateOrInsert(
            ['combo_id' => $combo->id, 'producto_id' => $productos['Gaseosa 400ml']->id],
            ['cantidad' => 1]
        );
    }

    private function crearMesas(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            Mesa::query()->firstOrCreate(['nombre' => "Mesa {$i}"], ['estado' => 'libre', 'activa' => true]);
        }
    }

    // RF-M07-030, RN-M05-08 a 13: valores iniciales de la tabla `configuraciones`, con los
    // mismos montos que hoy tiene config/fragua_real.php como default (ver ese archivo).
    private function crearConfiguraciones(): void
    {
        Configuracion::query()->firstOrCreate(
            ['clave' => 'valor_domicilio'],
            ['valor' => '2000', 'descripcion' => 'Valor fijo del domicilio (COP), editable por el Administrador.']
        );

        Configuracion::query()->firstOrCreate(
            ['clave' => 'deposito_reserva'],
            ['valor' => '20000', 'descripcion' => 'Depósito obligatorio para reservar mesa (COP), editable por el Administrador.']
        );
    }
}
