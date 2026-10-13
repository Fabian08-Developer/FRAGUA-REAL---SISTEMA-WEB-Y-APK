// Fase 6, tarea 2 (RN-PED-06/09, RF-M02-T-006, D5): ayudas para las comandas impresas desde el navegador.
import type { Comanda } from '@/types/models'

export const TIPOS_COMANDA: Record<Comanda['tipo'], string> = {
  inicial: 'Comanda',
  actualizacion: 'Cambios',
  cancelacion: 'Cancelación',
}

export function porImprimir(c: Comanda): boolean {
  return c.estado_impresion === 'pendiente' || c.estado_impresion === 'fallida'
}

// Avisa a la franja de comandas pendientes que algo cambió (confirmar, editar o cancelar un pedido)
// para que vuelva a consultar sin esperar su siguiente vuelta.
export const EVENTO_COMANDAS = 'fragua:comandas'
export function avisarComandas() {
  window.dispatchEvent(new Event(EVENTO_COMANDAS))
}

// Preferencias de este equipo (no de la cuenta): en la cocina se dejan prendidas, en un celular no.
const CLAVE_AL_CONFIRMAR = 'fragua_comandas_al_confirmar'
const CLAVE_AUTOMATICO = 'fragua_comandas_automatico'

function leer(clave: string, porDefecto: boolean): boolean {
  try {
    const v = localStorage.getItem(clave)
    return v === null ? porDefecto : v === '1'
  } catch {
    return porDefecto
  }
}

function guardar(clave: string, valor: boolean) {
  try {
    localStorage.setItem(clave, valor ? '1' : '0')
  } catch {
    // Sin almacenamiento (navegación privada): la preferencia dura lo que dure la pantalla.
  }
}

/** RN-PED-06: al confirmar un pedido se abre su comanda para imprimir (prendido por defecto). */
export const imprimirAlConfirmar = {
  get: () => leer(CLAVE_AL_CONFIRMAR, true),
  set: (v: boolean) => guardar(CLAVE_AL_CONFIRMAR, v),
}

/** RF-M02-T-006: abrir solas las comandas nuevas que lleguen (apagado por defecto). */
export const imprimirAutomatico = {
  get: () => leer(CLAVE_AUTOMATICO, false),
  set: (v: boolean) => guardar(CLAVE_AUTOMATICO, v),
}
