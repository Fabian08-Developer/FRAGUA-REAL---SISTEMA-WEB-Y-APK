// Fase 2, tarea 3 (RF-M04-010/011): los extras que se ofrecen con un producto, iguales en el Menú,
// el QR de mesa y el pedido del Mesero. El backend los manda en `extras_disponibles` (específicos
// activos + generales que le aplican); si no vienen (respuesta vieja), se usan los específicos.
import type { Extra, Producto } from '@/types/models'

export function extrasDe(producto: Pick<Producto, 'extras_disponibles' | 'extras_especificos'> | null | undefined): Extra[] {
  if (!producto) return []
  return producto.extras_disponibles ?? (producto.extras_especificos ?? []).filter((e) => e.activo)
}
