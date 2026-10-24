// Fase 6, tarea 1 (RF-M06-006, D4: Wompi): ayudas para el pago en línea con la pasarela.
import type { CheckoutPasarela, TransaccionPasarela } from '@/types/models'

// Manda el navegador al checkout de la pasarela. Devuelve false si no hay checkout (modo manual:
// el pago queda en revisión y lo aprueba el personal).
export function irAlCheckout(checkout: CheckoutPasarela | null | undefined): boolean {
  if (!checkout?.url) return false
  window.location.assign(checkout.url)
  return true
}

// Pago directo (22/10/2026): nombre del medio con el que se pagó en línea (Bre-B solo sin Wompi).
const MEDIOS: Record<string, string> = { nequi: 'Nequi', bancolombia: 'Bancolombia', breb: 'Bre-B' }
export function nombreMedio(medio: string | null | undefined): string {
  return medio ? (MEDIOS[medio] ?? medio) : ''
}

const METODOS: Record<string, string> = {
  NEQUI: 'Nequi',
  BANCOLOMBIA_TRANSFER: 'Botón Bancolombia',
  BANCOLOMBIA_QR: 'QR Bancolombia',
  PSE: 'PSE',
  CARD: 'Tarjeta',
}

export function metodoPasarela(metodo: string | null | undefined): string {
  return metodo ? (METODOS[metodo] ?? metodo) : ''
}

export const ESTADO_TRANSACCION: Record<TransaccionPasarela['estado'], string> = {
  pendiente: 'En proceso',
  aprobado: 'Aprobado',
  rechazado: 'Rechazado',
  anulado: 'Anulado',
  error: 'Con error',
  abandonado: 'Sin pagar (vencido)',
}

export const ESTADO_REEMBOLSO: Record<NonNullable<TransaccionPasarela['reembolso_estado']>, string> = {
  solicitado: 'Reembolso solicitado a la pasarela',
  aprobado: 'Reembolso aprobado por la pasarela',
  no_soportado: 'Devolverlo desde el panel de la pasarela',
  fallido: 'El reembolso falló',
}
