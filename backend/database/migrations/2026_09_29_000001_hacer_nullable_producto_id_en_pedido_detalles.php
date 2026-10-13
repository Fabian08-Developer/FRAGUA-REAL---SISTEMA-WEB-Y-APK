<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CORREGIDO (29/09/2026): pedido_detalles.producto_id se creó como NOT NULL
// (2026_01_01_000015_create_pedido_detalles_table), pero una línea de COMBO nunca manda
// producto_id (PedidoService::crearLinea la deja en null a propósito cuando combo_id viene
// lleno; el mismo detalle NOT NULL falta en variante_id/combo_id, que sí son nullable desde
// esa migración). Cualquier pedido con un combo termina en un error 500 de Postgres
// ("null value in column producto_id violates not-null constraint") en vez de crearse.
// Sin doctrine/dbal instalado, ->nullable()->change() no está disponible — se usa SQL nativo.
return new class extends Migration
{
    // ACTUALIZADO (Fase 0, 09/10/2026): el SQL nativo es de PostgreSQL y rompía `php artisan
    // test`, que corre en SQLite en memoria (phpunit.xml). En PostgreSQL se sigue usando el
    // mismo ALTER; en otros motores, el change() nativo de Laravel.
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pedido_detalles ALTER COLUMN producto_id DROP NOT NULL');

            return;
        }

        Schema::table('pedido_detalles', function (Blueprint $table) {
            $table->unsignedBigInteger('producto_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE pedido_detalles ALTER COLUMN producto_id SET NOT NULL');

            return;
        }

        Schema::table('pedido_detalles', function (Blueprint $table) {
            $table->unsignedBigInteger('producto_id')->nullable(false)->change();
        });
    }
};
