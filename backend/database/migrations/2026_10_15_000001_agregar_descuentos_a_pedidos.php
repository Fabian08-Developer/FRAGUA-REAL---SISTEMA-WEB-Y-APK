<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 2, tarea 1 del plan de implementación (11/10/2026).
// RF-M04-035 a 039, RN-M04-18 a 23: las promociones automáticas vigentes se aplican solas al
// pedido y se combinan entre sí; el Mesero o el Administrador pueden aplicar un descuento manual
// con motivo, que reemplaza a las automáticas (no se acumulan).
//
// - pedidos.descuento: lo que se rebaja en total. El total queda = subtotal − descuento + domicilio.
// - pedidos.promociones_vigentes: foto de las promociones automáticas vigentes al crear el pedido
//   (como el precio congelado, ADR-002): si después se agrega una línea, se le aplican las mismas
//   reglas aunque la promoción ya haya vencido o el Administrador la haya cambiado.
// - pedidos.promociones_aplicadas: qué promoción rebajó cuánto (lo que ve el Cliente y el reporte).
// - pedidos.descuento_manual*: la regla del descuento manual (porcentaje o valor fijo), quién, cuándo
//   y por qué. Con descuento manual, las automáticas no cuentan (RN-M04-21).
// - pedido_detalles.descuento: la parte del descuento que le toca a cada línea, para que las ventas
//   por producto y por tipo de venta (M06/M08) sumen lo mismo que lo cobrado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->decimal('descuento', 10, 2)->default(0)->after('subtotal');
            $table->json('promociones_vigentes')->nullable()->after('descuento');
            $table->json('promociones_aplicadas')->nullable()->after('promociones_vigentes');
            $table->json('descuento_manual')->nullable()->after('promociones_aplicadas');
            $table->string('descuento_manual_motivo')->nullable()->after('descuento_manual');
            $table->foreignId('descuento_manual_por')->nullable()->after('descuento_manual_motivo')->constrained('users')->nullOnDelete();
            $table->dateTime('descuento_manual_at')->nullable()->after('descuento_manual_por');
        });

        Schema::table('pedido_detalles', function (Blueprint $table) {
            $table->decimal('descuento', 10, 2)->default(0)->after('precio_unitario_congelado');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_detalles', function (Blueprint $table) {
            $table->dropColumn('descuento');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('descuento_manual_por');
            $table->dropColumn(['descuento', 'promociones_vigentes', 'promociones_aplicadas', 'descuento_manual', 'descuento_manual_motivo', 'descuento_manual_at']);
        });
    }
};
