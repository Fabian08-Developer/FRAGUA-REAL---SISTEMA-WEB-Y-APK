import { AxiosError } from 'axios'

// Laravel devuelve errores de validación (422) como
// { message: string, errors: { campo: string[] } }. Este helper los reduce
// a un mapa "campo -> primer mensaje", listo para mostrar bajo cada input.
export function erroresDeValidacion(error: unknown): Record<string, string> {
  if (error instanceof AxiosError && error.response?.status === 422) {
    const errores = error.response.data?.errors as Record<string, string[]> | undefined
    if (errores) {
      return Object.fromEntries(Object.entries(errores).map(([campo, msgs]) => [campo, msgs[0]]))
    }
  }
  return {}
}

// Mensaje general para mostrar cuando el error no es de validación de campo
// (credenciales inválidas, token expirado, error de red, etc).
export function mensajeDeError(error: unknown, fallback = 'Ocurrió un error. Intenta de nuevo.'): string {
  if (error instanceof AxiosError) {
    return error.response?.data?.message ?? fallback
  }
  return fallback
}
