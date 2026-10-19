// Fase 2, tarea 1 (RF-M04-035) — Precio rebajado que se muestra en el menú. Misma regla del
// backend (PromocionService::calcular, paso 1): cada promoción de producto o categoría se calcula
// sobre el precio y se suman, sin pasar del precio. Es solo para mostrar: el total real lo da la
// cotización del carrito y el pedido creado. Las promociones al total no se ven aquí.
//
// Mejora (16/10/2026): una promoción puede ser de una sola presentación (`variante_id`, la oferta de
// la "Familiar"); solo rebaja esa. Sin presentación (`varianteId` sin dar), cuentan las de todo el
// producto.
import type { Producto, PromocionVigente } from '@/types/models'

type ConPromociones = Pick<Producto, 'promociones_vigentes'>

function promocionesQueAplican(producto: ConPromociones, varianteId?: number | null): PromocionVigente[] {
  return (producto.promociones_vigentes ?? []).filter((p) => !p.variante_id || p.variante_id === varianteId)
}

export function rebajaPorUnidad(producto: ConPromociones, precio: number, varianteId?: number | null): number {
  const total = promocionesQueAplican(producto, varianteId).reduce(
    (acc, p) => acc + (p.tipo_valor === 'porcentaje' ? Math.round((precio * Number(p.valor)) / 100) : Math.round(Number(p.valor))),
    0,
  )
  return Math.min(precio, total)
}

export function precioConPromocion(producto: ConPromociones, precio: number, varianteId?: number | null): number {
  return precio - rebajaPorUnidad(producto, precio, varianteId)
}

// Etiqueta de la tarjeta. Sin `varianteId`, una oferta que es solo de una presentación se anuncia como
// "Oferta" (el precio rebajado se ve al elegir esa presentación).
export function etiquetaPromocion(producto: ConPromociones, varianteId?: number | null): string | null {
  const promos = promocionesQueAplican(producto, varianteId)
  if (!promos.length) {
    return varianteId === undefined && (producto.promociones_vigentes ?? []).length ? 'Oferta' : null
  }
  if (promos.length === 1) {
    const p = promos[0]
    return p.tipo_valor === 'porcentaje'
      ? `−${Number(p.valor)} %`
      : `−${Number(p.valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })}`
  }
  return 'Promo'
}
