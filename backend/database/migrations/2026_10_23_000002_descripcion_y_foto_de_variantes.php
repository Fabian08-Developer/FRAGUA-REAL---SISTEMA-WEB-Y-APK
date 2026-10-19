<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mejora (16/10/2026): cada presentación de un producto (RF-M04-008) puede tener una descripción corta
// ("para 3 o 4 personas") y su propia foto. El cliente, al elegir "Familiar", ve lo que va a recibir;
// sin foto propia se sigue viendo la del producto. Misma forma que la foto del producto
// (2026_10_21_000002): `imagen_path` es el archivo privado y `imagen_url` la dirección pública. Aditiva.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variantes', function (Blueprint $table) {
            $table->string('descripcion', 160)->nullable()->after('nombre');
            $table->string('imagen_url')->nullable()->after('precio');
            $table->string('imagen_path')->nullable()->after('imagen_url');
        });
    }

    public function down(): void
    {
        Schema::table('variantes', function (Blueprint $table) {
            $table->dropColumn(['descripcion', 'imagen_url', 'imagen_path']);
        });
    }
};
