import { AxiosError } from 'axios'

// Revisión por rol (13/10/2026): varios mensajes del servidor terminan con la regla en la que se
// apoyan, p. ej. "No hay un turno de caja abierto para registrar el cobro (RN-M06-03)". Sirve en
// las pruebas y en los registros, pero al Mesero o al Cliente no le dice nada: aquí se quita.
const CODIGO_REQUISITO = /\s*\((?:RN|RF|RNF|HU|CA)-[A-Z0-9-]+(?:[,/ ]+(?:(?:RN|RF|RNF|HU|CA)-)?[A-Z0-9-]+)*\)/g

export function sinCodigosDeRequisito(texto: string): string {
  return texto.replace(CODIGO_REQUISITO, '').replace(/\s+([.,;:])/g, '$1').trim()
}

// Laravel devuelve errores de validación (422) como
// { message: string, errors: { campo: string[] } }. Este helper los reduce
// a un mapa "campo -> primer mensaje", listo para mostrar bajo cada input.
export function erroresDeValidacion(error: unknown): Record<string, string> {
  if (error instanceof AxiosError && error.response?.status === 422) {
    const errores = error.response.data?.errors as Record<string, string[]> | undefined
    if (errores) {
      return Object.fromEntries(Object.entries(errores).map(([campo, msgs]) => [campo, sinCodigosDeRequisito(msgs[0])]))
    }
  }
  return {}
}

// Mensaje general para mostrar cuando el error no es de validación de campo
// (credenciales inválidas, token expirado, error de red, etc).
//
// Revisión por rol (13/10/2026): algunos mensajes los pone Laravel en inglés y no pasan por las
// traducciones (demasiados intentos, sin permiso, error del servidor); aquí se cambian por uno
// en español. Sin respuesta del servidor (sin internet), se dice eso en vez del genérico.
const MENSAJES_EN_INGLES: [RegExp, string][] = [
  [/^Too Many Attempts\.?$/i, 'Hiciste demasiados intentos seguidos. Espera un minuto e intenta de nuevo.'],
  [/^This action is unauthorized\.?$/i, 'No tienes permiso para hacer esto.'],
  [/^Unauthenticated\.?$/i, 'Tu sesión terminó. Vuelve a iniciar sesión.'],
]

export function mensajeDeError(error: unknown, fallback = 'Ocurrió un error. Intenta de nuevo.'): string {
  if (error instanceof AxiosError) {
    if (!error.response) {
      return error.code === 'ECONNABORTED' || error.code === 'ERR_NETWORK'
        ? 'No pudimos conectarnos. Revisa tu conexión a internet e intenta de nuevo.'
        : fallback
    }
    if (error.response.status === 429) return MENSAJES_EN_INGLES[0][1]
    const mensaje = error.response.data?.message
    if (typeof mensaje !== 'string' || !mensaje.trim()) return fallback
    // Error interno (500) sin depuración: "Server Error" no le sirve a nadie.
    if (error.response.status >= 500) return fallback
    const traducido = MENSAJES_EN_INGLES.find(([patron]) => patron.test(mensaje.trim()))
    return traducido ? traducido[1] : sinCodigosDeRequisito(mensaje)
  }
  return fallback
}
