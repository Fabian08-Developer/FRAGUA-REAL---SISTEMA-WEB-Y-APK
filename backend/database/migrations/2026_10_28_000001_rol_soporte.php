<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// M10 (20/10/2026): rol Soporte, la cuenta técnica del desarrollador. `users.role` acepta ahora
// 'soporte' además de 'superadmin', 'administrador', 'mesero' y 'cliente'.
return new class extends Migration
{
    private const CON = ['superadmin', 'administrador', 'soporte', 'mesero', 'cliente'];
    private const SIN = ['superadmin', 'administrador', 'mesero', 'cliente'];

    public function up(): void
    {
        $this->roles(self::CON);
    }

    public function down(): void
    {
        // Una cuenta de Soporte no se vuelve Administrador (tendría más permisos de los que tenía):
        // queda como Cliente desactivada y sin sesiones.
        $ids = DB::table('users')->where('role', 'soporte')->pluck('id');
        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $ids)->delete();
        DB::table('users')->whereIn('id', $ids)->update(['role' => 'cliente', 'activo' => false]);
        $this->roles(self::SIN);
    }

    private function roles(array $roles): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Laravel crea el enum de PostgreSQL como varchar con una restricción CHECK.
            $lista = implode(', ', array_map(fn ($r) => "'{$r}'::character varying", $roles));
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role::text = ANY (ARRAY[{$lista}]::text[]))");

            return;
        }

        Schema::table('users', function (Blueprint $table) use ($roles) {
            $table->enum('role', $roles)->change();
        });
    }
};
