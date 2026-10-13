<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    // Añadido al aplicar la arquitectura: AuthController::login()/register() llaman a
    // createToken(), que requiere este trait de Sanctum (autenticación por token, no por
    // cookie/SPA — ver Documento Técnico del Stack). El modelo original no lo traía.
    use HasApiTokens;

    // RF-M01-012/013: AuthController::solicitarRecuperacion() llama a $user->notify(...)
    // para el correo real de recuperación (RecuperarPasswordNotification) — requiere este
    // trait, que el modelo tampoco traía.
    use Notifiable;

    protected $fillable = ['role', 'nombre', 'email', 'username', 'telefono', 'password', 'activo'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // ---- Roles (RNF-TRANS-011) ----
    public function esAdministrador(): bool { return $this->role === 'administrador'; }
    public function esMesero(): bool { return $this->role === 'mesero'; }
    public function esCliente(): bool { return $this->role === 'cliente'; }

    // ---- Relaciones ----
    public function pedidosComoCliente(): HasMany
    {
        return $this->hasMany(Pedido::class, 'cliente_id');
    }

    public function pedidosConfirmados(): HasMany
    {
        return $this->hasMany(Pedido::class, 'confirmado_por');
    }

    public function pedidosFinalizados(): HasMany
    {
        return $this->hasMany(Pedido::class, 'finalizado_por');
    }

    public function pagosRegistrados(): HasMany
    {
        return $this->hasMany(Pago::class, 'registrado_por');
    }

    public function cajaTurnosAbiertos(): HasMany
    {
        return $this->hasMany(CajaTurno::class, 'abierto_por');
    }

    public function cajaTurnosCerrados(): HasMany
    {
        return $this->hasMany(CajaTurno::class, 'cerrado_por');
    }

    public function comprasGastos(): HasMany
    {
        return $this->hasMany(CompraGasto::class, 'registrado_por');
    }

    public function cierresDiarios(): HasMany
    {
        return $this->hasMany(CierreDiario::class, 'cerrado_por');
    }

    public function auditorias(): HasMany
    {
        return $this->hasMany(Auditoria::class);
    }
}
