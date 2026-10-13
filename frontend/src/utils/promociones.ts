// Fase 2, tarea 1 (RF-M04-035) — Precio rebajado que se muestra en el menú. Misma regla del
// backend (PromocionService::calcular, paso 1): cada promoción de producto o categoría se calcula
// sobre el precio y se suman, sin pasar del precio. Es solo para mostrar: el total real lo da la
// cotización del carrito y el pedido creado. Las promociones al total no se ven aquí.
import type { Producto } from '@/types/models'

export function rebajaPorUnidad(producto: Pick<Producto, 'promociones_vigentes'>, precio: number): number {
  const total = (producto.promociones_vigentes ?? []).reduce(
    (acc, p) => acc + (p.tipo_valor === 'porcentaje' ? Math.round((precio * Number(p.valor)) / 100) : Math.round(Number(p.valor))),
    0,
  )
  return Math.min(precio, total)
}

export function precioConPromocion(producto: Pick<Producto, 'promociones_vigentes'>, precio: number): number {
  return precio - rebajaPorUnidad(producto, precio)
}

export function etiquetaPromocion(producto: Pick<Producto, 'promociones_vigentes'>): string | null {
  const promos = producto.promociones_vigentes ?? []
  if (!promos.length) return null
  if (promos.length === 1) {
    const p = promos[0]
    return p.tipo_valor === 'porcentaje'
      ? `−${Number(p.valor)} %`
      : `−${Number(p.valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })}`
  }
  return 'Promo'
}
