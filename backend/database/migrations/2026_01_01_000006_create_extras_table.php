<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->decimal('precio', 10, 2);
            // RN-M04-05: 'especifico' exige producto_id; 'general' lo deja null (aplica a todo el menú)
            $table->enum('alcance', ['general', 'especifico']);
            $table->foreignId('producto_id')->nullable()->constrained('productos')->cascadeOnDelete();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extras');
    }
};
