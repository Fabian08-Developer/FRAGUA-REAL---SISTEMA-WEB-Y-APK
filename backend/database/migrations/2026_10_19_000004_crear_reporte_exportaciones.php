<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5, tarea 3 (13/10/2026) — RF-M08-011, 012, 014, RNF-M08-006, HU-M08-009, 010: exportación
 * real de reportes. Cada archivo exportado (PDF o Excel .xlsx) se guarda en el almacenamiento
 * privado y queda registrado aquí, para volver a descargarlo tal cual, sin regenerarlo
 * (RNF-M08-006): qué reporte, qué periodo, si comparaba con el anterior, quién lo generó, el
 * archivo y cuántas veces se descargó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporte_exportaciones', function (Blueprint $table) {
            $table->id();
            $table->string('reporte', 40);
            $table->string('formato', 10); // pdf | excel
            $table->date('desde');
            $table->date('hasta');
            $table->boolean('parcial')->default(false);
            $table->boolean('comparar')->default(false);
            $table->string('archivo'); // ruta en el disco privado (local)
            $table->string('nombre_archivo');
            $table->unsignedInteger('bytes')->default(0);
            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('descargas')->default(0);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporte_exportaciones');
    }
};
