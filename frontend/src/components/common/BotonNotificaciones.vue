<script setup lang="ts">
// M11 (21/10/2026): la campana del personal, con el contador de notificaciones sin leer.
//  · barra   → ícono solo, en la barra de arriba del teléfono.
//  · lateral → como una entrada más del menú lateral (tableta y computador).
// El panel con la lista es PanelNotificaciones (uno solo para las dos campanas).
import { Bell } from 'lucide-vue-next'
import { useNotificacionesStore } from '@/stores/notificaciones'

defineProps<{ variante: 'barra' | 'lateral' }>()

const notificaciones = useNotificacionesStore()

function texto(n: number) {
  return n > 9 ? '9+' : String(n)
}
</script>

<template>
  <button
    v-if="variante === 'barra'"
    type="button"
    class="relative flex size-10 shrink-0 items-center justify-center rounded-xl text-white/80 transition hover:bg-white/10 active:bg-white/15"
    :class="notificaciones.abierto ? 'bg-white/10 text-white' : ''"
    :aria-label="notificaciones.noLeidas ? `Notificaciones: ${notificaciones.noLeidas} sin leer` : 'Notificaciones'"
    :aria-expanded="notificaciones.abierto"
    @click="notificaciones.alternar()"
  >
    <Bell :size="20" />
    <span
      v-if="notificaciones.noLeidas"
      class="absolute right-1 top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-ink-900"
    >
      {{ texto(notificaciones.noLeidas) }}
    </span>
  </button>

  <button
    v-else
    type="button"
    class="group relative flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition md:flex-col md:gap-1 md:px-1 lg:flex-row lg:gap-3 lg:px-3"
    :class="notificaciones.abierto ? 'bg-white/10 text-white' : 'text-white/55 hover:bg-white/5 hover:text-white'"
    :aria-label="notificaciones.noLeidas ? `Notificaciones: ${notificaciones.noLeidas} sin leer` : 'Notificaciones'"
    :aria-expanded="notificaciones.abierto"
    title="Notificaciones"
    @click="notificaciones.alternar()"
  >
    <span class="relative">
      <Bell :size="19" class="shrink-0" />
      <span
        v-if="notificaciones.noLeidas"
        class="absolute -right-2 -top-2 flex h-[17px] min-w-[17px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-ink-900"
      >
        {{ texto(notificaciones.noLeidas) }}
      </span>
    </span>
    <span class="md:text-[10px] md:font-semibold lg:text-sm lg:font-medium">Notificaciones</span>
  </button>
</template>
