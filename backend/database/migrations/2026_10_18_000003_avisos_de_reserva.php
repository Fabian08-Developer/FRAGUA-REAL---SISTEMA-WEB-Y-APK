<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fase 4, tarea 4 (13/10/2026) — RF-M05-018, 023, 025, 027, RN-M05-09, HU-M05-008, 011, 014.
 *  - reservas.token_publico: enlace secreto que va en los correos para que quien reservó (aunque
 *    no tenga cuenta) vea su reserva y la cancele. Se genera también para las reservas que ya
 *    existían.
 *  - reserva_avisos: cada aviso al cliente (confirmación, recordatorio, cancelación, no-show),
 *    a qué correo, si se envió o falló, cuántos intentos y el error (RF-M05-027).
 * Aditiva: no cambia datos existentes salvo llenar el token nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->string('token_publico', 64)->nullable()->unique()->after('id');
        });

        DB::table('reservas')->whereNull('token_publico')->orderBy('id')->pluck('id')->each(function ($id) {
            DB::table('reservas')->where('id', $id)->update(['token_publico' => Str::random(48)]);
        });

        Schema::create('reserva_avisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            $table->string('tipo', 20); // confirmacion | recordatorio | cancelacion | no_show
            $table->string('canal', 10)->default('correo'); // D3: correo en V1; sms después
            $table->string('destino');
            $table->string('estado', 12)->default('pendiente'); // pendiente | enviado | fallido
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('enviado_at')->nullable();
            $table->timestamps();

            $table->index(['reserva_id', 'tipo']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserva_avisos');
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropUnique(['token_publico']);
            $table->dropColumn('token_publico');
        });
    }
};
