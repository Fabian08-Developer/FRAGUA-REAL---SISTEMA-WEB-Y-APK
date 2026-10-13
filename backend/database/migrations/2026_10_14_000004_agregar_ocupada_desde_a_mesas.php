<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 1, tarea 3 del plan de implementación (11/10/2026).
// RF-M02-L-019/020, RN-PED-13/14/30, HU-M02-006: la cuenta de una mesa reúne los pedidos de
// la visita en curso. M05 (introducción) define la visita: el paso de Libre a Ocupada abre una
// cuenta nueva y el paso de Ocupada a Libre la cierra. `ocupada_desde` guarda ese inicio, para
// no mezclar en la cuenta los pedidos de clientes anteriores en la misma mesa.
// Aditiva: las mesas ocupadas hoy toman la hora de su pedido activo más antiguo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->dateTime('ocupada_desde')->nullable()->after('estado');
        });

        DB::table('mesas')->where('estado', 'ocupada')->orderBy('id')->each(function ($mesa) {
            $desde = DB::table('pedidos')
                ->where('mesa_id', $mesa->id)
                ->where('tipo', 'local')
                ->whereIn('estado', ['pendiente', 'confirmado', 'preparando'])
                ->min('created_at');

            DB::table('mesas')->where('id', $mesa->id)->update(['ocupada_desde' => $desde ?? $mesa->updated_at ?? now()]);
        });
    }

    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->dropColumn('ocupada_desde');
        });
    }
};
