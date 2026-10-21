<?php

namespace App\Support\Usuarios;

use App\Models\User;

/**
 * M09 (19/10/2026): quién gestiona qué cuenta desde Usuarios (RN-M09-02 a 05).
 *  - La cuenta del SuperAdmin no se gestiona desde la aplicación: se crea y se cambia en el servidor
 *    (`php artisan fragua:crear-superadmin`). Su dueño edita sus propios datos en Perfil.
 *  - Las cuentas de Administrador solo las gestiona el SuperAdmin.
 *  - Meseros y Clientes los gestiona cualquier Administrador (y el SuperAdmin).
 *  - M10 (20/10/2026): la cuenta de Soporte se crea y se cambia en el servidor
 *    (`php artisan fragua:crear-soporte`). En Usuarios solo la ve el SuperAdmin, que puede
 *    desactivarla, reactivarla y cerrar sus sesiones, pero no editarla (RN-M10-03/04).
 */
final class GestionCuentas
{
    public const SOLO_SUPERADMIN = 'Solo el SuperAdmin puede gestionar las cuentas de Administrador.';
    public const CUENTA_SUPERADMIN = 'La cuenta del SuperAdmin no se gestiona desde aquí; se cambia en el servidor.';
    public const CUENTA_SOPORTE = 'La cuenta de Soporte se crea y se cambia en el servidor; el SuperAdmin solo puede desactivarla, reactivarla y cerrar sus sesiones.';
    public const SOPORTE_SOLO_SUPERADMIN = 'Solo el SuperAdmin puede desactivar o reactivar la cuenta de Soporte.';

    /** Por qué $actor no puede gestionar a $objetivo, o null si puede. */
    public static function motivoNoGestiona(User $actor, User $objetivo): ?string
    {
        // Sus propios datos los corrige cada quien; cambiarse el rol, desactivarse o cerrarse las
        // sesiones desde Usuarios sigue bloqueado aparte (UsuarioController).
        if ($actor->is($objetivo)) {
            return null;
        }
        if ($objetivo->esSuperAdmin()) {
            return self::CUENTA_SUPERADMIN;
        }
        if ($objetivo->role === 'administrador' && ! $actor->esSuperAdmin()) {
            return self::SOLO_SUPERADMIN;
        }
        // M10: activar, desactivar y cerrar sesiones; editarla se bloquea aparte (motivoNoEdita).
        if ($objetivo->esSoporte() && ! $actor->esSuperAdmin()) {
            return self::SOPORTE_SOLO_SUPERADMIN;
        }

        return null;
    }

    /** Por qué $actor no puede editar los datos o el rol de $objetivo, o null si puede. */
    public static function motivoNoEdita(User $actor, User $objetivo): ?string
    {
        if ($objetivo->esSoporte()) {
            return self::CUENTA_SOPORTE;
        }

        return self::motivoNoGestiona($actor, $objetivo);
    }

    /** Por qué $actor no puede dar ese rol a una cuenta, o null si puede. */
    public static function motivoNoAsigna(User $actor, string $rol): ?string
    {
        if ($rol === 'superadmin') {
            return self::CUENTA_SUPERADMIN;
        }
        if ($rol === 'soporte') {
            return self::CUENTA_SOPORTE;
        }
        if ($rol === 'administrador' && ! $actor->esSuperAdmin()) {
            return self::SOLO_SUPERADMIN;
        }

        return null;
    }

    /** Lo que el listado de Usuarios le dice a la pantalla sobre cada cuenta. */
    public static function puedeGestionar(User $actor, User $objetivo): bool
    {
        return self::motivoNoGestiona($actor, $objetivo) === null;
    }
}
