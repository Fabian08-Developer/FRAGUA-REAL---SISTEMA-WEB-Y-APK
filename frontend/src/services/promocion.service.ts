// M04 — Promociones (RN-M04-19 a 23). Espeja app/Http/Controllers/Api/PromocionController.php.
// Fechas especiales (Navidad, Amor y Amistad, etc.) NO tienen tratamiento propio: son una
// Promocion más con vigencia_inicio/vigencia_fin — el Admin las crea como cualquier otra.
import api from './api'
import type { Promocion } from '@/types/models'

export interface CrearPromocionPayload {
  nombre: string
  tipo: 'automatica' | 'manual'
  alcance: 'producto' | 'categoria' | 'total'
  tipo_valor: 'porcentaje' | 'fijo'
  valor: number
  vigencia_inicio?: string | null
  vigencia_fin?: string | null
  franja_inicio?: string | null
  franja_fin?: string | null
  dias_semana?: number[] | null
  activo?: boolean
  productos?: number[]
  categorias?: number[]
}

export const promocionService = {
  index() {
    return api.get<Promocion[]>('/promociones')
  },
  crear(payload: CrearPromocionPayload) {
    return api.post<Promocion>('/promociones', payload)
  },
  actualizar(id: number, payload: CrearPromocionPayload) {
    return api.put<Promocion>(`/promociones/${id}`, payload)
  },
  // Fase 2, tarea 1: promociones de tipo "manual" vigentes, para que el Mesero las aplique a un pedido.
  manuales() {
    return api.get<Promocion[]>('/promociones/manuales')
  },
}
