import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { LineaPedidoPayload } from '@/services/pedido.service'
import type { Combo, Extra, Producto, Variante } from '@/types/models'

// M02: carrito del Cliente antes de confirmar el pedido (Local vía QR,
// Domicilio o Recogida). Precio se congela al confirmar el pedido en el
// backend (ADR-002), no aquí — este store solo arma la selección.
//
// Cada línea es una selección concreta: producto + variante elegida (si
// tiene, RN-M04) + extras marcados. Dos líneas del mismo producto con
// distinta variante/extras NO se combinan — son pedidos distintos.
//
// Revisión por rol (13/10/2026, RF-M04-020/031): una línea también puede ser un combo
// (combo con precio propio, sin variante ni extras; sí con nota para cocina). Antes el Menú y
// el QR de mesa no ofrecían combos: solo el Mesero podía venderlos.
export interface LineaCarrito {
  id: string
  producto: Producto | null
  combo?: Combo | null
  variante: Variante | null
  extras: Extra[]
  cantidad: number
  notas?: string
}

type SeleccionPrecio = Pick<LineaCarrito, 'producto' | 'variante' | 'extras'> & { combo?: Combo | null }

function precioUnitario(linea: SeleccionPrecio): number {
  if (linea.combo) return Number(linea.combo.precio)
  if (!linea.producto) return 0
  const base = linea.variante ? Number(linea.variante.precio) : Number(linea.producto.precio_base)
  const extras = linea.extras.reduce((acc, e) => acc + Number(e.precio), 0)
  return base + extras
}

function nombreLinea(linea: Pick<LineaCarrito, 'producto' | 'combo'>): string {
  return linea.combo?.nombre ?? linea.producto?.nombre ?? ''
}

export const useCarritoStore = defineStore('carrito', () => {
  const lineas = ref<LineaCarrito[]>([])

  const totalProductos = computed(() => lineas.value.reduce((acc, l) => acc + l.cantidad, 0))

  const total = computed(() =>
    lineas.value.reduce((acc, l) => acc + precioUnitario(l) * l.cantidad, 0),
  )

  function agregar(linea: Omit<LineaCarrito, 'id'>) {
    lineas.value.push({ ...linea, id: crypto.randomUUID() })
  }

  function actualizarCantidad(id: string, cantidad: number) {
    const linea = lineas.value.find((l) => l.id === id)
    if (linea) {
      linea.cantidad = Math.max(1, cantidad)
    }
  }

  function quitar(id: string) {
    lineas.value = lineas.value.filter((l) => l.id !== id)
  }

  function vaciar() {
    lineas.value = []
  }

  // CORREGIDO (30/09/2026): CheckoutView.vue (Domicilio/Recogida) y MesaQrView.vue (Local
  // vía QR) necesitan el carrito en la forma que espera StorePedidoRequest (producto_id/
  // variante_id/extras_ids/observaciones) — este método no existía, así que confirmar
  // CUALQUIER pedido fallaba con un TypeError antes de llegar siquiera al backend.
  function aLineasPedido(): LineaPedidoPayload[] {
    return lineas.value.map((l) =>
      l.combo
        ? { combo_id: l.combo.id, cantidad: l.cantidad, observaciones: l.notas || undefined }
        : {
            producto_id: l.producto?.id,
            variante_id: l.variante?.id ?? undefined,
            cantidad: l.cantidad,
            extras_ids: l.extras.length ? l.extras.map((e) => e.id) : undefined,
            observaciones: l.notas || undefined,
          },
    )
  }

  return {
    lineas,
    totalProductos,
    total,
    agregar,
    actualizarCantidad,
    quitar,
    vaciar,
    precioUnitario,
    nombreLinea,
    aLineasPedido,
  }
})
