// M10 (20/10/2026): la cuenta de Soporte ve el panel del Administrador solo para consultar.
// `v-escritura` en un botón que cambia algo (crear, editar, cobrar, anular, guardar…) lo esconde
// para esa cuenta. Es solo comodidad: el servidor rechaza esas acciones de todos modos
// (SoporteSoloConsulta), así que un botón que se escape solo muestra «La cuenta de Soporte solo
// puede consultar.».
import type { Directive } from 'vue'
import { useAuthStore } from '@/stores/auth'

function aplicar(el: HTMLElement) {
  if (useAuthStore().esSoporte) el.style.setProperty('display', 'none', 'important')
}

export const vEscritura: Directive<HTMLElement> = {
  mounted: aplicar,
  updated: aplicar,
}

declare module 'vue' {
  interface GlobalDirectives {
    vEscritura: typeof vEscritura
  }
}
