<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5, tarea 1 (13/10/2026) — RF-M06-009, 010, RN-M06-07, HU-M06-006, RF-M02-L-023,
 * RF-M02-T-013, HU-M02-019: comprobante de pago.
 *
 * La tabla `comprobantes` existía desde el inicio, pero nada la llenaba. Se completa:
 *  - consecutivo: número correlativo del comprobante (el `numero` visible es FR-000001).
 *  - detalle (json): copia de lo que dice el comprobante al generarse (líneas, totales, pagos,
 *    cliente), para que el documento no cambie aunque después cambien el catálogo o el pedido.
 *  - anulado_at / anulado_motivo: un comprobante no se borra ni se reescribe; si el pedido deja
 *    de estar pagado por completo (pago anulado, pedido cancelado o modificado), se anula y,
 *    cuando vuelva a estar pagado, sale uno nuevo con otro número.
 *  - Por eso un pedido puede tener varios comprobantes en su historia (se quita el único por
 *    pedido_id); solo uno está vigente a la vez.
 * Aditiva: la tabla estaba vacía en todas las instalaciones; si tuviera filas, se numeran por id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->unsignedInteger('consecutivo')->nullable()->after('pedido_id');
            $table->json('detalle')->nullable()->after('generado_at');
            $table->timestamp('anulado_at')->nullable()->after('detalle');
            $table->string('anulado_motivo')->nullable()->after('anulado_at');
        });

        DB::table('comprobantes')->whereNull('consecutivo')->orderBy('id')->pluck('id')
            ->each(fn ($id) => DB::table('comprobantes')->where('id', $id)->update(['consecutivo' => $id]));

        Schema::table('comprobantes', function (Blueprint $table) {
            $table->index('pedido_id');
            $table->dropUnique(['pedido_id']);
            $table->unique('consecutivo');
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropUnique(['consecutivo']);
            $table->unique('pedido_id');
            $table->dropIndex(['pedido_id']);
            $table->dropColumn(['consecutivo', 'detalle', 'anulado_at', 'anulado_motivo']);
        });
    }
};
