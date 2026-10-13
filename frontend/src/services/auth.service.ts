import api from './api'
import type { Usuario } from '@/types/models'

// Espeja exactamente los Form Requests de AuthController (routes/api.php) —
// nombres de campo iguales a los del backend (email, no correo).
export interface LoginPayload {
  email: string
  password: string
}

export interface RegistroPayload {
  nombre: string
  email: string
  telefono?: string
  password: string
  password_confirmation: string
}

export interface AuthResponse {
  user: Usuario
  token: string
}

export interface ActualizarPerfilPayload {
  nombre: string
  telefono?: string
}

export interface CambiarPasswordPayload {
  password_actual: string
  password: string
  password_confirmation: string
}

export const authService = {
  login(payload: LoginPayload) {
    return api.post<AuthResponse>('/auth/login', payload)
  },
  // RF-M01-014: autorregistro, exclusivo para Cliente (Administrador/Mesero
  // los crea un Administrador por otro medio, ver README-BACKEND.md).
  registrar(payload: RegistroPayload) {
    return api.post<AuthResponse>('/auth/register', payload)
  },
  me() {
    return api.get<Usuario>('/auth/me')
  },
  logout() {
    return api.post('/auth/logout')
  },
  // RF-M01-010/023: cerrar sesión en todos los dispositivos.
  logoutTodos() {
    return api.post('/auth/logout-todos')
  },
  // RF-M01-012/013: recuperación de contraseña.
  solicitarRecuperacion(email: string) {
    return api.post<{ message: string }>('/auth/recuperar', { email })
  },
  restablecerContrasena(payload: {
    email: string
    token: string
    password: string
    password_confirmation: string
  }) {
    return api.post<{ message: string }>('/auth/restablecer', payload)
  },
  // RF-M01: editar el propio perfil / cambiar la propia contraseña.
  actualizarPerfil(payload: ActualizarPerfilPayload) {
    return api.put<Usuario>('/perfil', payload)
  },
  cambiarPassword(payload: CambiarPasswordPayload) {
    return api.post<{ message: string }>('/perfil/password', payload)
  },
}
