// Fase 5: abre en otra pestaña un PDF que el backend entrega con la sesión (comprobantes, y más
// adelante el cierre diario y los reportes guardados), listo para imprimir o guardar.
import { AxiosError } from 'axios'

// Un error de una petición con responseType "blob" llega como Blob: se lee para mostrar su mensaje.
export async function mensajeDeBlob(e: unknown, fallback: string): Promise<string> {
  if (e instanceof AxiosError && e.response?.data instanceof Blob) {
    try {
      return JSON.parse(await e.response.data.text()).message || fallback
    } catch {
      return fallback
    }
  }
  return fallback
}

/** Descarga un archivo (Excel, PDF) con su nombre. */
export function guardarArchivo(data: Blob, nombre: string) {
  const url = URL.createObjectURL(data)
  const enlace = document.createElement('a')
  enlace.href = url
  enlace.download = nombre
  document.body.appendChild(enlace)
  enlace.click()
  document.body.removeChild(enlace)
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/**
 * Abre el PDF en otra pestaña. Lanza Error con un mensaje legible si no se pudo.
 * `ventana`: una pestaña ya abierta en el clic (Fase 6: la comanda al confirmar, que se pide después
 * de esperar la confirmación); si no viene, se abre aquí, antes de pedir el archivo.
 */
export async function abrirPdf(
  pedir: () => Promise<{ data: Blob }>,
  fallback = 'No pudimos abrir el documento. Intenta de nuevo.',
  ventana: Window | null = window.open('', '_blank'),
) {
  // La pestaña se abre antes de pedir el archivo para que el navegador no la bloquee.
  try {
    const { data } = await pedir()
    const url = URL.createObjectURL(new Blob([data], { type: 'application/pdf' }))
    if (ventana) ventana.location.href = url
    else window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) {
    ventana?.close()
    throw new Error(await mensajeDeBlob(e, fallback))
  }
}
