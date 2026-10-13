// M01 — espeja UsuarioController del backend (routes/api.php: GET/POST /usuarios,
// PUT /usuarios/{id}, POST /usuarios/{id}/activar|desactivar|revocar-sesiones). Exclusivo Administrador.
import api from './api'
import type { Rol, Usuario } from '@/types/models'

export interface CrearUsuarioPayload {
  role: Extract<Rol, 'mesero' | 'administrador'>
  nombre: string
  email: string
  username?: string
  telefono?: string
  password: string
  password_confirmation: string
}

// Fase 3, tarea 2 (RF-M01-003/005): datos editables de una cuenta. La contraseña no va aquí.
export interface EditarUsuarioPayload {
  nombre: string
  email: string
  // Fase 3, tarea 3: vacío = sin nombre de usuario (entra solo con el correo).
  username: string
  telefono: string | null
  role: Rol
}

export interface UsuarioEditado {
  usuario: Usuario
  // true si cambió el rol: el backend cerró todas sus sesiones.
  sesiones_cerradas: boolean
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
  editar(id: number, payload: EditarUsuarioPayload) {
    return api.put<UsuarioEditado>(`/usuarios/${id}`, payload)
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
