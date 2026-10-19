<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrección en producción (16/10/2026): extras generales según M04 (RF-M04-011, CA-M04-004).
 *
 * Hasta hoy, un extra general que el Administrador no asociaba a ningún producto se ofrecía con todo
 * el menú, y a una Limonada se le podía pedir "Extra queso". Desde ahora un general solo se ofrece con
 * los productos a los que se asocia (Extra::aplicaA). Para no quitarle de golpe los extras a las
 * comidas, los generales que estaban sin asociar se asocian aquí a todos los productos de tipo
 * Alimento; las bebidas y los postres quedan sin ellos. Desde Catálogo se ajusta producto por producto.
 *
 * Los generales que ya tenían productos asociados no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sueltos = DB::table('extras')
            ->where('alcance', 'general')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('producto_extra')->whereColumn('producto_extra.extra_id', 'extras.id'))
            ->pluck('id');
        $comidas = DB::table('productos')->where('tipo_venta', 'alimento')->pluck('id');

        $ahora = now();
        $filas = [];
        foreach ($sueltos as $extraId) {
            foreach ($comidas as $productoId) {
                $filas[] = ['producto_id' => $productoId, 'extra_id' => $extraId, 'created_at' => $ahora, 'updated_at' => $ahora];
            }
        }
        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('producto_extra')->insert($lote);
        }
    }

    public function down(): void
    {
        // Sin reversa: desde aquí las asociaciones son del Administrador, que puede haberlas cambiado, y
        // con la regla nueva un general sin asociar ya no se ofrece con ningún producto.
    }
};
