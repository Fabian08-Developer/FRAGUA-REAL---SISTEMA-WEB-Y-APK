// Fase 3, tarea 1 (RF-M01-025, HU-M01-020, RNF-TRANS-023) — espeja AuditoriaController del
// backend: GET /auditorias (con filtros, paginado) y GET /auditorias/opciones. Solo lectura y
// exclusivo del Administrador; no existe ninguna ruta para editar o borrar el historial.
import api from './api'
import type { OpcionesAuditoria, RegistroAuditoria } from '@/types/models'

export interface FiltrosAuditoria {
  desde?: string
  hasta?: string
  user_id?: number
  categoria?: string
  accion?: string
  entidad?: string
  entidad_id?: number
  ip?: string
  busqueda?: string
  page?: number
}

export interface AuditoriaPaginada {
  data: RegistroAuditoria[]
  current_page: number
  last_page: number
  total: number
}

export const auditoriaService = {
  index(filtros: FiltrosAuditoria = {}) {
    const params = Object.fromEntries(
      Object.entries(filtros).filter(([, valor]) => valor !== undefined && valor !== null && valor !== ''),
    )
    return api.get<AuditoriaPaginada>('/auditorias', { params })
  },
  opciones() {
    return api.get<OpcionesAuditoria>('/auditorias/opciones')
  },
}
