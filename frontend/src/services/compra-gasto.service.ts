// M03 — espeja CompraGastoController del backend (routes/api.php: GET/POST
// /compras-gastos, PUT /compras-gastos/{id}, POST /compras-gastos/{id}/anular,
// POST /cierres-diarios). Exclusivo Administrador.
import api from './api'
import type { CierreDiario, CompraGasto } from '@/types/models'

export interface CompraGastoPayload {
  tipo: 'compra' | 'gasto'
  // Fase 2, tarea 4: obligatoria y de su tipo (GET /compras-gastos/categorias).
  categoria: string
  descripcion: string
  // RF-M03-03/04, RN-M03-02: solo informativas, no generan inventario.
  cantidad?: number | null
  unidad?: string | null
  valor: number
  metodo_pago: 'efectivo' | 'transferencia' | 'tarjeta' | 'otro'
  proveedor?: string | null
  numero_factura?: string | null
  observaciones?: string | null
  fecha: string
}

// RF-M03-13 (Fase 2, tarea 4): filtros del historial.
export interface FiltrosCompraGasto {
  desde?: string
  hasta?: string
  tipo?: 'compra' | 'gasto'
  categoria?: string
  metodo_pago?: CompraGastoPayload['metodo_pago']
  proveedor?: string
  estado?: 'activo' | 'anulado'
  page?: number
}

export interface ComprasGastosPaginadas {
  data: CompraGasto[]
  current_page: number
  last_page: number
  total: number
  // Totales de lo filtrado, solo registros activos (RN-M03-04).
  totales: { total_compras: number; total_gastos: number; cantidad_activos: number }
}

function aFormData(payload: CompraGastoPayload, soporte: File): FormData {
  const datos = new FormData()
  Object.entries(payload).forEach(([clave, valor]) => {
    if (valor !== undefined && valor !== null && valor !== '') datos.append(clave, String(valor))
  })
  datos.append('soporte', soporte)
  return datos
}

export const compraGastoService = {
  index(params?: FiltrosCompraGasto) {
    return api.get<ComprasGastosPaginadas>('/compras-gastos', { params })
  },
  categorias() {
    return api.get<Record<'compra' | 'gasto', string[]>>('/compras-gastos/categorias')
  },
  // Con soporte se envía como multipart (RF-M03-07); sin él, como JSON.
  crear(payload: CompraGastoPayload, soporte?: File | null) {
    return soporte
      ? api.post<CompraGasto>('/compras-gastos', aFormData(payload, soporte))
      : api.post<CompraGasto>('/compras-gastos', payload)
  },
  editar(id: number, payload: Partial<CompraGastoPayload>) {
    return api.put<CompraGasto>(`/compras-gastos/${id}`, payload)
  },
  adjuntarSoporte(id: number, soporte: File) {
    const datos = new FormData()
    datos.append('soporte', soporte)
    return api.post<CompraGasto>(`/compras-gastos/${id}/soporte`, datos)
  },
  // RNF-M03-11: el soporte se pide con la sesión del Administrador (no hay enlace público).
  verSoporte(id: number) {
    return api.get<Blob>(`/compras-gastos/${id}/soporte`, { responseType: 'blob' })
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
