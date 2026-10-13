// M06 — acciones sobre un Pago ya existente (a diferencia de pedido.service.ts, que
// solo cubre "pagarEnLinea"/"cobrar", ambas autorizadas sobre el Pedido antes de que el
// Pago exista — ver el comentario en PagoPolicy). Espeja routes/api.php: POST
// /pagos/{pago}/confirmar-en-linea y /rechazar-en-linea, Mesero/Administrador únicamente.
//
// CORREGIDO (29/09/2026): sin esto, un pedido pagado "en línea" (Nequi/Bancolombia)
// quedaba en estado "pendiente" para siempre — PedidoService::confirmar() del backend
// exige que no tenga un pago en línea sin aprobar (RN-M06-05), y no había ninguna forma
// de aprobar o rechazar ese pago desde la app.
import api from './api'
import type { Pago } from '@/types/models'

export const pagoService = {
  confirmarEnLinea(pagoId: number) {
    return api.post<Pago>(`/pagos/${pagoId}/confirmar-en-linea`)
  },
  rechazarEnLinea(pagoId: number) {
    return api.post<Pago>(`/pagos/${pagoId}/rechazar-en-linea`)
  },
  // Anular un pago es control financiero exclusivo del Administrador (PagoPolicy::anular),
  // independiente de si estaba aprobado/pendiente/rechazado — ver CajaView.vue (admin).
  // RF-M06-017/019: tipo = anulación o reembolso; password_confirmacion solo hace falta si la
  // caja del día del pago ya cerró (el backend responde 422 pidiéndola).
  anular(pagoId: number, payload: { motivo: string; tipo?: 'anulacion' | 'reembolso'; password_confirmacion?: string }) {
    return api.post<Pago>(`/pagos/${pagoId}/anular`, payload)
  },
}
