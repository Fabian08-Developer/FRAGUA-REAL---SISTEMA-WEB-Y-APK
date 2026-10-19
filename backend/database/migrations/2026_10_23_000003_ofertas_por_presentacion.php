<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mejora (16/10/2026): atajo «Poner en oferta» en el Catálogo. Una oferta es una promoción automática
// de producto (RF-M04-033) creada desde el producto, marcada con `es_oferta`. Con `variante_id`, la
// promoción rebaja solo esa presentación (la "Familiar"); vacío, todas, como hasta hoy. Aditiva.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promociones', function (Blueprint $table) {
            $table->foreignId('variante_id')->nullable()->after('alcance')->constrained('variantes')->nullOnDelete();
            $table->boolean('es_oferta')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('promociones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variante_id');
            $table->dropColumn('es_oferta');
        });
    }
};
