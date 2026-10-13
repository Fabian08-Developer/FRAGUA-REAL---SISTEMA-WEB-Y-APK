<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique(); // RN-M05-10: único en todo el sistema
            $table->enum('estado', ['libre', 'ocupada', 'reservada'])->default('libre');
            $table->string('qr_code')->unique()->nullable();
            $table->boolean('activa')->default(true); // baja lógica, RF-M05-004
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesas');
    }
};
