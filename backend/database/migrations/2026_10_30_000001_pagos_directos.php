<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Pago directo (22/10/2026, RF-M06-030 a 035): mientras no haya cuenta de Wompi, el Cliente transfiere a
// las cuentas del negocio (Nequi, Bancolombia o Bre-B) y sube la captura del comprobante; el personal
// la revisa y aprueba o rechaza el pago con motivo. Los pagos y los depósitos guardan la captura, la
// referencia y el motivo del rechazo, y el medio acepta «breb».
return new class extends Migration
{
    private const CON = ['nequi', 'bancolombia', 'breb'];
    private const SIN = ['nequi', 'bancolombia'];

    public function up(): void
    {
        Schema::table('pagos', function (Blueprint $table) {
            $table->string('comprobante_path')->nullable();
            $table->string('comprobante_mime', 60)->nullable();
            $table->string('referencia_cliente', 60)->nullable();
            $table->string('rechazo_motivo', 255)->nullable();
        });
        Schema::table('reservas', function (Blueprint $table) {
            $table->string('deposito_comprobante_path')->nullable();
            $table->string('deposito_comprobante_mime', 60)->nullable();
            $table->string('deposito_referencia', 60)->nullable();
            $table->string('deposito_rechazo_motivo', 255)->nullable();
        });
        $this->medios(self::CON);
    }

    public function down(): void
    {
        DB::table('pagos')->where('pasarela', 'breb')->update(['pasarela' => null]);
        DB::table('reservas')->where('deposito_pasarela', 'breb')->update(['deposito_pasarela' => null]);
        $this->medios(self::SIN);
        Schema::table('pagos', function (Blueprint $table) {
            $table->dropColumn(['comprobante_path', 'comprobante_mime', 'referencia_cliente', 'rechazo_motivo']);
        });
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn(['deposito_comprobante_path', 'deposito_comprobante_mime', 'deposito_referencia', 'deposito_rechazo_motivo']);
        });
    }

    private function medios(array $medios): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Laravel crea el enum de PostgreSQL como varchar con una restricción CHECK.
            $lista = implode(', ', array_map(fn ($m) => "'{$m}'::character varying", $medios));
            foreach (['pagos' => 'pasarela', 'reservas' => 'deposito_pasarela'] as $tabla => $columna) {
                DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$tabla}_{$columna}_check");
                DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$tabla}_{$columna}_check CHECK ({$columna}::text = ANY (ARRAY[{$lista}]::text[]))");
            }

            return;
        }

        Schema::table('pagos', fn (Blueprint $t) => $t->enum('pasarela', $medios)->nullable()->change());
        Schema::table('reservas', fn (Blueprint $t) => $t->enum('deposito_pasarela', $medios)->nullable()->change());
    }
};
