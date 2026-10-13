import { storeToRefs } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import type { Rol } from '@/types/models'

// Helper de conveniencia sobre el store de auth, para usar en vistas/guards
// sin repetir storeToRefs en cada componente.
export function useAuth() {
  const store = useAuthStore()
  const { usuario, estaAutenticado, rol } = storeToRefs(store)

  function tieneRol(...roles: Rol[]) {
    return !!rol.value && roles.includes(rol.value)
  }

  return {
    usuario,
    estaAutenticado,
    rol,
    tieneRol,
    login: store.login,
    registrar: store.registrar,
    logout: store.logout,
    cargarUsuarioActual: store.cargarUsuarioActual,
  }
}
