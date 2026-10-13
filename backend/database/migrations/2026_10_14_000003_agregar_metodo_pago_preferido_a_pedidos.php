<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 1, tarea 2 del plan de implementación (11/10/2026).
// RF-M07-013/015, RF-M07-R-007/008, HU-M07-004: el domicilio y la recogida guardan cómo dijo el
// cliente que iba a pagar (contra entrega / al recoger, o en línea) y, si es en línea, con qué
// pasarela (Nequi o Bancolombia), aunque el pago todavía no exista. Antes la elección del
// Checkout se perdía si el Cliente elegía pagar contra entrega, y el Mesero no sabía qué esperar.
// Aditiva: los pedidos existentes quedan en NULL (sin dato).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('metodo_pago_preferido', 20)->nullable()->after('observaciones_entrega'); // contra_entrega | en_linea
            $table->string('pasarela_preferida', 20)->nullable()->after('metodo_pago_preferido'); // nequi | bancolombia
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['metodo_pago_preferido', 'pasarela_preferida']);
        });
    }
};
