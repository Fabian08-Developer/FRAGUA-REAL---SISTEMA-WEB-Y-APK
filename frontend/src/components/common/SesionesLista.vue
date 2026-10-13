<script setup lang="ts">
// Fase 3, tarea 4 (RF-M01-010/024, HU-M01-010): lista de sesiones abiertas con su dispositivo,
// IP, último uso y cuándo vence. La usan Mi perfil (las propias) y Usuarios (las de una cuenta).
// Solo muestra y avisa: quien la usa decide qué hacer al pulsar "Cerrar".
import { computed } from 'vue'
import { Laptop, LogOut, Smartphone } from 'lucide-vue-next'
import BaseButton from './BaseButton.vue'
import type { PoliticaSesiones, SesionAbierta } from '@/types/models'

const props = withDefaults(
  defineProps<{
    sesiones: SesionAbierta[]
    politica?: PoliticaSesiones | null
    procesando?: number | null
    // false = no ofrecer "Cerrar" en la sesión actual (Mi perfil ya tiene "Cerrar sesión").
    permitirCerrarActual?: boolean
  }>(),
  { politica: null, procesando: null, permitirCerrarActual: false },
)

defineEmits<{ (e: 'cerrar', sesion: SesionAbierta): void }>()

function esMovil(sesion: SesionAbierta) {
  return /Android|iOS/.test(sesion.dispositivo)
}

function formato(valor: string | null) {
  return valor
    ? new Date(valor).toLocaleString('es-CO', { timeZone: 'America/Bogota', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
    : '—'
}

function duracion(minutos: number) {
  if (minutos % (60 * 24) === 0) {
    const dias = minutos / (60 * 24)
    return dias === 1 ? '1 día' : `${dias} días`
  }
  if (minutos % 60 === 0) {
    const horas = minutos / 60
    return horas === 1 ? '1 hora' : `${horas} horas`
  }
  return `${minutos} minutos`
}

const textoPolitica = computed(() =>
  props.politica
    ? `Una sesión se cierra sola tras ${duracion(props.politica.inactividad_minutos)} sin uso y, aunque se use, a los ${duracion(props.politica.duracion_maxima_minutos)} de abierta.`
    : '',
)
</script>

<template>
  <div>
    <p v-if="!sesiones.length" class="text-sm text-gray-500">No hay sesiones abiertas.</p>
    <ul v-else class="divide-y divide-gray-100">
      <li v-for="sesion in sesiones" :key="sesion.id" class="flex items-start justify-between gap-3 py-3">
        <div class="flex min-w-0 items-start gap-3">
          <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500" aria-hidden="true">
            <Smartphone v-if="esMovil(sesion)" :size="17" />
            <Laptop v-else :size="17" />
          </span>
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
              {{ sesion.dispositivo }}
              <span v-if="sesion.actual" class="badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">Esta sesión</span>
            </p>
            <p class="mt-0.5 text-xs text-gray-500">
              <template v-if="sesion.ip">IP {{ sesion.ip }} · </template>
              Último uso: {{ formato(sesion.ultimo_uso_at ?? sesion.creada_at) }}
            </p>
            <p class="text-xs text-gray-400">Abierta: {{ formato(sesion.creada_at) }} · vence: {{ formato(sesion.vence_at) }}</p>
          </div>
        </div>
        <BaseButton
          v-if="!sesion.actual || permitirCerrarActual"
          variant="ghost"
          size="sm"
          :disabled="procesando === sesion.id"
          @click="$emit('cerrar', sesion)"
        >
          <LogOut :size="14" />
          {{ procesando === sesion.id ? 'Cerrando…' : 'Cerrar' }}
        </BaseButton>
      </li>
    </ul>
    <p v-if="textoPolitica" class="mt-2 text-xs text-gray-500">{{ textoPolitica }}</p>
  </div>
</template>
