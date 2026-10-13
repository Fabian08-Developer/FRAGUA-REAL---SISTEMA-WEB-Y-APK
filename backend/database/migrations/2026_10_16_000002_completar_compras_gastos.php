<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Fase 2, tarea 4 del plan de implementación (12/10/2026) — M03, compras y gastos completos.
// - RF-M03-03/04, RN-M03-02: cantidad y unidad, solo informativas (no generan inventario).
// - Observaciones (modelo de datos de M03, sección 8).
// - RF-M03-07, RNF-M03-06/11, HU-M03-02: soporte (JPG, PNG o PDF) guardado en almacenamiento
//   privado; solo se descarga con sesión de Administrador. `soporte_url` se conserva para los
//   registros viejos que tenían un enlace.
// - RF-M03-19, RNF-M03-04: quién y cuándo editó o anuló cada registro.
// Migración aditiva: no cambia ni borra datos existentes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras_gastos', function (Blueprint $table) {
            $table->decimal('cantidad', 10, 2)->nullable()->after('descripcion');
            $table->string('unidad', 50)->nullable()->after('cantidad');
            $table->text('observaciones')->nullable()->after('numero_factura');
            $table->string('soporte_path')->nullable()->after('soporte_url');
            $table->string('soporte_nombre')->nullable()->after('soporte_path');
            $table->string('soporte_mime', 100)->nullable()->after('soporte_nombre');
            $table->foreignId('editado_por')->nullable()->after('registrado_por')->constrained('users')->nullOnDelete();
            $table->dateTime('editado_at')->nullable()->after('editado_por');
            $table->foreignId('anulado_por')->nullable()->after('anulado_motivo')->constrained('users')->nullOnDelete();
            $table->dateTime('anulado_at')->nullable()->after('anulado_por');
            $table->index(['fecha', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::table('compras_gastos', function (Blueprint $table) {
            $table->dropIndex(['fecha', 'tipo']);
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropConstrainedForeignId('editado_por');
            $table->dropColumn(['cantidad', 'unidad', 'observaciones', 'soporte_path', 'soporte_nombre', 'soporte_mime', 'editado_at', 'anulado_at']);
        });
    }
};
