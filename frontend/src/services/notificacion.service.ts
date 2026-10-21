// M11 (21/10/2026): notificaciones del personal (la campana). Cada cuenta ve y marca solo las suyas.
//  · listar()      → GET /notificaciones (las 30 más recientes y cuántas faltan por leer)
//  · contador()    → GET /notificaciones/contador (lo que la campana pregunta cada 20 s)
//  · marcarLeida() → POST /notificaciones/{id}/leida
//  · marcarTodas() → POST /notificaciones/leidas
import api from './api'

export type CategoriaNotificacion = 'pedidos' | 'reservas' | 'caja' | 'operacion' | 'sistema'

export interface Notificacion {
  id: number
  tipo: string
  categoria: CategoriaNotificacion
  titulo: string
  texto: string
  enlace: string | null
  leida_at: string | null
  created_at: string
}

export const notificacionService = {
  listar() {
    return api.get<{ data: Notificacion[]; no_leidas: number }>('/notificaciones')
  },
  contador() {
    return api.get<{ no_leidas: number; ultima_id: number | null }>('/notificaciones/contador')
  },
  marcarLeida(id: number) {
    return api.post<{ notificacion: Notificacion; no_leidas: number }>(`/notificaciones/${id}/leida`)
  },
  marcarTodas() {
    return api.post<{ marcadas: number; no_leidas: number }>('/notificaciones/leidas')
  },
}
