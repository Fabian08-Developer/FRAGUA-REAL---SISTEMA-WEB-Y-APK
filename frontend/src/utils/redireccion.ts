// Revisión por rol (13/10/2026): a dónde volver después de iniciar sesión o crear la cuenta
// (?redirect=/checkout). Solo se aceptan rutas internas del sitio ("/algo"), nunca "//otro-sitio"
// ni una dirección completa, para que un enlace armado no saque al usuario del sitio.
export function destinoInterno(valor: unknown): string | null {
  if (typeof valor !== 'string') return null
  const v = valor.trim()
  if (!v.startsWith('/') || v.startsWith('//') || v.startsWith('/\\')) return null
  return v
}
