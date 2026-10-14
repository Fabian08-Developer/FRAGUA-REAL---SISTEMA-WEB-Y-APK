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

    protected $fillable = ['role', 'nombre', 'email', 'username', 'telefono', 'password', 'activo', 'puede_registrar_gastos'];

    protected $hidden = ['password', 'remember_token'];

    // Igual que el valor por defecto de la columna, para que una cuenta recién creada ya lo traiga.
    protected $attributes = ['puede_registrar_gastos' => false];

    protected $casts = [
        'activo' => 'boolean',
        'puede_registrar_gastos' => 'boolean',
    ];

    // ---- Roles (RNF-TRANS-011) ----
    public function esAdministrador(): bool { return $this->role === 'administrador'; }
    public function esMesero(): bool { return $this->role === 'mesero'; }
    public function esCliente(): bool { return $this->role === 'cliente'; }

    // Fase 7, tarea 2 (RNF-TRANS-012, RF-M03-32): el «usuario autorizado» de M03. Un Mesero con
    // el permiso registra compras y gastos, adjunta el soporte y ve lo que él registró; editar,
    // anular y el cierre diario siguen siendo del Administrador. No es un rol nuevo.
    public function puedeRegistrarComprasGastos(): bool
    {
        return $this->esAdministrador() || ($this->esMesero() && (bool) $this->puede_registrar_gastos);
    }

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
