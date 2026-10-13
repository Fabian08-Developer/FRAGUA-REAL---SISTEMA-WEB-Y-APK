// M01 — espeja UsuarioController del backend (routes/api.php: GET/POST /usuarios,
// POST /usuarios/{id}/activar|desactivar|revocar-sesiones). Exclusivo Administrador.
import api from './api'
import type { Rol, Usuario } from '@/types/models'

export interface CrearUsuarioPayload {
  role: Extract<Rol, 'mesero' | 'administrador'>
  nombre: string
  email: string
  telefono?: string
  password: string
  password_confirmation: string
}

export interface UsuariosPaginados {
  data: Usuario[]
  current_page: number
  last_page: number
  total: number
}

export const usuarioService = {
  index(params?: { rol?: Rol; busqueda?: string }) {
    return api.get<UsuariosPaginados>('/usuarios', { params })
  },
  crear(payload: CrearUsuarioPayload) {
    return api.post<Usuario>('/usuarios', payload)
  },
  activar(id: number) {
    return api.post<Usuario>(`/usuarios/${id}/activar`)
  },
  desactivar(id: number) {
    return api.post<Usuario>(`/usuarios/${id}/desactivar`)
  },
  revocarSesiones(id: number) {
    return api.post<{ message: string }>(`/usuarios/${id}/revocar-sesiones`)
  },
}
