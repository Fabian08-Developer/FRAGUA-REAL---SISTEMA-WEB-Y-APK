<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 1, tarea 2 del plan de implementación (11/10/2026).
// - RF-M07-004, RF-M07-R-003: todo domicilio y toda recogida guardan un teléfono de contacto,
//   también los del Cliente con cuenta (antes solo el invitado dejaba teléfono, y el del perfil
//   es opcional). Así el Mesero siempre tiene a quién llamar, y el número queda congelado en el
//   pedido aunque el Cliente cambie su perfil después.
// - RF-M07-010: observaciones de entrega del pedido completo (portería, timbre, "llamar al
//   llegar"), distintas de las observaciones de cada producto.
// Aditiva: los pedidos existentes toman el teléfono del invitado o del perfil del Cliente.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('telefono_contacto', 30)->nullable()->after('cliente_invitado_telefono');
            $table->string('observaciones_entrega', 500)->nullable()->after('referencia');
        });

        DB::table('pedidos')->whereNotNull('cliente_invitado_telefono')
            ->update(['telefono_contacto' => DB::raw('cliente_invitado_telefono')]);

        DB::table('pedidos')->whereNull('telefono_contacto')->whereNotNull('cliente_id')
            ->whereIn('tipo', ['domicilio', 'recogida'])
            ->orderBy('id')
            ->each(function ($pedido) {
                $telefono = DB::table('users')->where('id', $pedido->cliente_id)->value('telefono');
                if ($telefono) {
                    DB::table('pedidos')->where('id', $pedido->id)->update(['telefono_contacto' => $telefono]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn(['telefono_contacto', 'observaciones_entrega']);
        });
    }
};
