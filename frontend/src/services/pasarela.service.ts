// Fase 6, tarea 1 (RF-M06-006, D4: Wompi) — espeja PasarelaController.php.
// retorno() es pública (la página a la que vuelve el Cliente desde la pasarela); el resto es del
// Administrador: estado de la pasarela y los pagos en línea que hay que revisar.
import api from './api'
import type { TransaccionPasarela } from '@/types/models'

export interface RetornoPasarela {
  referencia: string
  estado: TransaccionPasarela['estado']
  monto: number
  metodo_pago: string | null
  en_revision: boolean
  tipo: 'pedido' | 'reserva'
  pedido?: { id: number; tipo: string; estado: string; total: number; de_cliente: boolean; seguimiento: string | null } | null
  reserva?: { id: number; estado: string; fecha_hora: string | null; mesa: string | null; enlace: string | null }
}

export interface EstadoPasarela {
  proveedor: string
  activa: boolean
  configurada: boolean
  ambiente: string
  url_eventos: string
  por_revisar: number
}

export type TransaccionRevision = TransaccionPasarela & {
  monto: number
  tipo: 'pedido' | 'reserva'
  pedido_id: number | null
  reserva_id: number | null
  revisado_por: string | null
}

export const pasarelaService = {
  // `id` es el que la pasarela agrega a la URL de retorno; el backend solo lo acepta si es de esa referencia.
  retorno(referencia: string, id?: string) {
    return api.get<RetornoPasarela>(`/pasarela/transacciones/${encodeURIComponent(referencia)}`, { params: id ? { id } : {} })
  },
  estado() {
    return api.get<EstadoPasarela>('/pasarela/estado')
  },
  porRevisar() {
    return api.get<{ data: TransaccionRevision[] }>('/pasarela/transacciones', { params: { revision: 1 } })
  },
  marcarRevisada(id: number, nota: string) {
    return api.post<TransaccionPasarela>(`/pasarela/transacciones/${id}/revisada`, { nota })
  },
  reintentarReembolso(id: number) {
    return api.post<TransaccionPasarela>(`/pasarela/transacciones/${id}/reembolsar`)
  },
}
