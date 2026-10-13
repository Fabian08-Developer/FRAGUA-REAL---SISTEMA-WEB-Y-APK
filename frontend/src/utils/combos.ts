// Revisión por rol (13/10/2026, RF-M04-020): lo que trae un combo, para mostrarlo igual en el
// Menú, el QR de mesa y el pedido del Mesero ("1 × Hamburguesa Clásica · 1 × Papas").
// GET /combos ya trae `productos` con la cantidad en el pivote y solo los combos que hoy se
// pueden pedir (vigencia, franja, día y componentes disponibles: RN-M04-11/16).
import type { Combo } from '@/types/models'

export function componentesCombo(combo: Pick<Combo, 'productos'> | null | undefined): string {
  return (combo?.productos ?? [])
    .map((p) => `${p.pivot?.cantidad ?? 1} × ${p.nombre}`)
    .join(' · ')
}
