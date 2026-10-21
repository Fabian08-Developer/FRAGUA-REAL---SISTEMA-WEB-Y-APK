<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Mejora (19/10/2026, M09): rol SuperAdmin, el dueño del sistema. `users.role` acepta ahora
// 'superadmin' además de 'administrador', 'mesero' y 'cliente'.
return new class extends Migration
{
    private const CON = ['superadmin', 'administrador', 'mesero', 'cliente'];
    private const SIN = ['administrador', 'mesero', 'cliente'];

    public function up(): void
    {
        $this->roles(self::CON);
    }

    public function down(): void
    {
        // Un SuperAdmin que exista vuelve a ser Administrador, para no perder la cuenta.
        DB::table('users')->where('role', 'superadmin')->update(['role' => 'administrador']);
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
