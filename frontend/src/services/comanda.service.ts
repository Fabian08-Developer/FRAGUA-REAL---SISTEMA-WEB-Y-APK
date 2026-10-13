// Fase 6, tarea 2 (RN-PED-06/09/17 a 19, RF-M02-T-004 a 007, D5) — espeja ComandaController.php.
// Solo el personal (Mesero y Administrador).
import api from './api'
import type { Comanda } from '@/types/models'

export type ComandaPendiente = Comanda & {
  lineas: number
  pedido: { id: number; tipo: 'local' | 'domicilio' | 'recogida'; estado: string; mesa: string | null; cliente: string | null }
}

export const comandaService = {
  pendientes() {
    return api.get<ComandaPendiente[]>('/comandas/pendientes')
  },
  dePedido(pedidoId: number) {
    return api.get<(Comanda & { impresa_por: string | null })[]>(`/pedidos/${pedidoId}/comandas`)
  },
  // El ticket de 80 mm; abrirlo cuenta como impresión (desde la segunda, sale «REIMPRESIÓN»).
  ticket(id: number) {
    return api.get<Blob>(`/comandas/${id}/ticket`, { responseType: 'blob' })
  },
  // HU-M02-016: no salió de la impresora; vuelve a pendientes.
  noImpresa(id: number, motivo?: string) {
    return api.post<Comanda>(`/comandas/${id}/no-impresa`, { motivo: motivo || undefined })
  },
}
