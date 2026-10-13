// Fase 2, tarea 1 (RF-M04-035) — Cotización en vivo de un carrito: el backend devuelve el
// subtotal, lo que rebajan las promociones vigentes y el total, con la misma cuenta que usará
// al crear el pedido. Se vuelve a pedir (con una pequeña espera) cada vez que cambia el carrito.
// Si la cotización falla (sin conexión, algo agotado), la vista sigue mostrando su total local.
import { onBeforeUnmount, ref, watch } from 'vue'
import { pedidoService, type Cotizacion, type LineaPedidoPayload } from '@/services/pedido.service'
import type { TipoPedido } from '@/types/models'

export function useCotizacion(tipo: () => TipoPedido, lineas: () => LineaPedidoPayload[]) {
  const cotizacion = ref<Cotizacion | null>(null)
  const cotizando = ref(false)
  let temporizador: ReturnType<typeof setTimeout> | undefined
  let turno = 0

  async function cotizar() {
    const actuales = lineas()
    if (!actuales.length) {
      cotizacion.value = null
      return
    }
    const miTurno = ++turno
    cotizando.value = true
    try {
      const { data } = await pedidoService.cotizar(tipo(), actuales)
      if (miTurno === turno) cotizacion.value = data
    } catch {
      if (miTurno === turno) cotizacion.value = null
    } finally {
      if (miTurno === turno) cotizando.value = false
    }
  }

  watch(
    [tipo, () => JSON.stringify(lineas())],
    () => {
      clearTimeout(temporizador)
      temporizador = setTimeout(cotizar, 350)
    },
    { immediate: true },
  )
  onBeforeUnmount(() => clearTimeout(temporizador))

  return { cotizacion, cotizando, cotizar }
}
