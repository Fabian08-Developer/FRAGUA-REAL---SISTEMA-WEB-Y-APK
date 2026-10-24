// Formatos de los campos (22/10/2026): `v-formato="'celular'"` en un <input> o <textarea> (o en un
// componente cuyo primer campo sea ese) descarta mientras se escribe o se pega lo que no corresponde
// y fija el largo máximo y el teclado del teléfono (ver utils/formatos.ts). El modelo (v-model)
// recibe siempre el valor limpio. Un `maxlength` puesto en el campo manda sobre el del formato.
import type { Directive } from 'vue'
import { aplicarFormato, atributosDeFormato, type Formato } from '@/utils/formatos'

type Campo = HTMLInputElement | HTMLTextAreaElement
interface Estado {
  formato: Formato
  maximo?: number
  alEscribir: (e: Event) => void
}
const ESTADO = new WeakMap<Campo, Estado>()

function campoDe(el: HTMLElement): Campo | null {
  if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) return el
  return el.querySelector('input, textarea')
}

function preparar(campo: Campo, formato: Formato) {
  const propio = Number(campo.getAttribute('data-maxlength') ?? campo.getAttribute('maxlength') ?? 0) || undefined
  // Se recuerda el maxlength propio (0 = ninguno) para no confundirlo después con el del formato.
  if (!campo.hasAttribute('data-maxlength')) campo.setAttribute('data-maxlength', String(propio ?? 0))
  // El largo lo controla el formato (ver atributosDeFormato): así lo pegado se limpia antes de recortarse.
  campo.removeAttribute('maxlength')
  for (const [nombre, valor] of Object.entries(atributosDeFormato(formato))) {
    if (!campo.hasAttribute(nombre)) campo.setAttribute(nombre, valor)
  }
  return propio
}

export const vFormato: Directive<HTMLElement, Formato> = {
  mounted(el, binding) {
    const campo = campoDe(el)
    if (!campo || !binding.value) return
    const estado: Estado = {
      formato: binding.value,
      maximo: preparar(campo, binding.value),
      alEscribir: (e: Event) => {
        if ((e as InputEvent).isComposing) return
        const antes = campo.value
        aplicarFormato(campo, estado.formato, estado.maximo)
        // v-model ya leyó el valor sin limpiar: se le avisa del valor limpio.
        if (campo.value !== antes) campo.dispatchEvent(new Event('input', { bubbles: true }))
      },
    }
    ESTADO.set(campo, estado)
    campo.addEventListener('input', estado.alEscribir)
    campo.addEventListener('compositionend', estado.alEscribir)
  },
  updated(el, binding) {
    const campo = campoDe(el)
    const estado = campo && ESTADO.get(campo)
    if (!campo || !estado || binding.value === estado.formato) return
    estado.formato = binding.value
    estado.maximo = preparar(campo, binding.value)
  },
  beforeUnmount(el) {
    const campo = campoDe(el)
    const estado = campo && ESTADO.get(campo)
    if (!campo || !estado) return
    campo.removeEventListener('input', estado.alEscribir)
    campo.removeEventListener('compositionend', estado.alEscribir)
    ESTADO.delete(campo)
  },
}

declare module 'vue' {
  interface GlobalDirectives {
    vFormato: typeof vFormato
  }
}
