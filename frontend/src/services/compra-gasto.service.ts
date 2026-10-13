// M03 — espeja CompraGastoController del backend (routes/api.php: GET/POST
// /compras-gastos, PUT /compras-gastos/{id}, POST /compras-gastos/{id}/anular,
// POST /cierres-diarios). Exclusivo Administrador.
import api from './api'
import type { CierreDiario, CompraGasto } from '@/types/models'

export interface CompraGastoPayload {
  tipo: 'compra' | 'gasto'
  categoria?: string
  descripcion: string
  valor: number
  metodo_pago: 'efectivo' | 'transferencia' | 'tarjeta' | 'otro'
  proveedor?: string
  numero_factura?: string
  soporte_url?: string
  fecha: string
}

export interface ComprasGastosPaginadas {
  data: CompraGasto[]
  current_page: number
  last_page: number
  total: number
}

export const compraGastoService = {
  index(params?: { tipo?: 'compra' | 'gasto'; page?: number }) {
    return api.get<ComprasGastosPaginadas>('/compras-gastos', { params })
  },
  crear(payload: CompraGastoPayload) {
    return api.post<CompraGasto>('/compras-gastos', payload)
  },
  editar(id: number, payload: Partial<CompraGastoPayload>) {
    return api.put<CompraGasto>(`/compras-gastos/${id}`, payload)
  },
  anular(id: number, motivo: string) {
    return api.post<CompraGasto>(`/compras-gastos/${id}/anular`, { motivo })
  },
  // RN-M03-04/09: no es un arqueo de caja — es la foto contable del día (ventas, compras,
  // gastos, resultado operativo), una sola vez por fecha.
  generarCierreDiario(fecha: string) {
    return api.post<CierreDiario>('/cierres-diarios', { fecha })
  },
}
