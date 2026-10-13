<?php

namespace App\Services;

use App\Models\Combo;
use Illuminate\Support\Collection;

/**
 * M04 — Catálogo. RN-M04-11: un combo se oculta si algún componente está agotado.
 *
 * División de responsabilidad (corrige una inconsistencia detectada en la revisión del
 * Documento Técnico de Arquitectura): el cálculo puro de "¿algún componente agotado?" vive
 * en `Combo::disponible()` (ya entregado con el paquete de base de datos) — este Service NO
 * reimplementa esa lógica. Lo que el Service aporta es lo que el Model no puede resolver por
 * sí solo: evaluar vigencia (fecha/franja horaria/día de la semana) y orquestar la
 * recalculación cuando cambia la disponibilidad de un producto (README de la base de datos,
 * sección "Pendientes que SÍ debes resolver en el código").
 */
class ComboService
{
    public function vigente(Combo $combo, ?\DateTimeInterface $momento = null): bool
    {
        // Fase 2, tarea 1: la franja y el día se evalúan en la hora de Colombia, no en la del
        // servidor (UTC) — si no, un combo de 12:00 a 15:00 se veía desde las 7:00 a. m.
        $momento = PromocionService::enHoraDelNegocio($momento);

        if ($combo->vigencia_inicio && $momento->format('Y-m-d') < $combo->vigencia_inicio->format('Y-m-d')) {
            return false;
        }
        if ($combo->vigencia_fin && $momento->format('Y-m-d') > $combo->vigencia_fin->format('Y-m-d')) {
            return false;
        }

        if ($combo->franja_inicio && $combo->franja_fin) {
            $hora = $momento->format('H:i:s');
            if ($hora < $combo->franja_inicio || $hora > $combo->franja_fin) {
                return false;
            }
        }

        if (! empty($combo->dias_semana)) {
            $diaIso = (int) $momento->format('N'); // 1 (lunes) .. 7 (domingo)
            if (! in_array($diaIso, $combo->dias_semana, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Disponible para agregarse a un pedido nuevo: vigente en este momento Y sin
     * componentes agotados (Combo::disponible()).
     */
    public function disponibleParaPedido(Combo $combo): bool
    {
        return $this->vigente($combo) && $combo->disponible();
    }

    /**
     * Lista de combos que hoy pueden mostrarse en el catálogo del Cliente.
     */
    public function disponibles(): Collection
    {
        return Combo::with('productos')->where('activo', true)->get()
            ->filter(fn (Combo $combo) => $this->disponibleParaPedido($combo))
            ->values();
    }
}
