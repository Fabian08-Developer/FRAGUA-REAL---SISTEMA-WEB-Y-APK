<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3, tarea 1 (12/10/2026) — RNF-TRANS-022, RN-USR-13, CA-M01-020.2: el historial de
 * auditoría no se puede modificar ni borrar.
 *
 * Desde la Fase 0 el modelo Auditoria rechaza update() y delete(), pero eso solo cubre a
 * Eloquent: una consulta directa (DB::table('auditorias')->update(...)) o un cliente SQL
 * podía cambiar el historial. Estos triggers lo impiden en la propia base de datos.
 *
 * Consecuencia a tener en cuenta: un usuario con registros de auditoría tampoco se puede
 * borrar de la base de datos (la llave foránea intentaría poner user_id en NULL y el
 * trigger lo rechaza). El sistema nunca borra usuarios: se desactivan (RN-USR-05/06).
 *
 * Aditiva: no cambia ni borra datos. También agrega un índice por fecha para la consulta.
 */
return new class extends Migration
{
    private const MENSAJE = 'El historial de auditoria no se puede modificar ni borrar (RNF-TRANS-022).';

    public function up(): void
    {
        Schema::table('auditorias', function (Blueprint $table) {
            $table->index('created_at');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared("
                CREATE OR REPLACE FUNCTION auditorias_inmutable() RETURNS trigger AS \$\$
                BEGIN
                    RAISE EXCEPTION '".self::MENSAJE."';
                END;
                \$\$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS auditorias_sin_cambios ON auditorias;
                CREATE TRIGGER auditorias_sin_cambios
                    BEFORE UPDATE OR DELETE ON auditorias
                    FOR EACH ROW EXECUTE FUNCTION auditorias_inmutable();

                DROP TRIGGER IF EXISTS auditorias_sin_vaciar ON auditorias;
                CREATE TRIGGER auditorias_sin_vaciar
                    BEFORE TRUNCATE ON auditorias
                    FOR EACH STATEMENT EXECUTE FUNCTION auditorias_inmutable();
            ");
        } elseif ($driver === 'sqlite') {
            // Las pruebas automáticas corren en SQLite: mismo comportamiento.
            DB::unprepared("
                CREATE TRIGGER IF NOT EXISTS auditorias_sin_update BEFORE UPDATE ON auditorias
                BEGIN SELECT RAISE(ABORT, '".self::MENSAJE."'); END;
            ");
            DB::unprepared("
                CREATE TRIGGER IF NOT EXISTS auditorias_sin_delete BEFORE DELETE ON auditorias
                BEGIN SELECT RAISE(ABORT, '".self::MENSAJE."'); END;
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS auditorias_sin_cambios ON auditorias;
                DROP TRIGGER IF EXISTS auditorias_sin_vaciar ON auditorias;
                DROP FUNCTION IF EXISTS auditorias_inmutable();
            ');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS auditorias_sin_update');
            DB::unprepared('DROP TRIGGER IF EXISTS auditorias_sin_delete');
        }

        Schema::table('auditorias', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
