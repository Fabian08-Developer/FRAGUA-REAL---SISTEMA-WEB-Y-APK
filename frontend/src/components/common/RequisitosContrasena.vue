<script setup lang="ts">
// Seguridad (21/10/2026, RN-USR-23): lo que debe tener una contraseña nueva, marcado a medida que
// se escribe. Las reglas vienen del servidor (GET /configuracion, campo `clave`), que es quien las
// hace cumplir; mientras llegan se usan las de siempre. En producción, el servidor además rechaza
// las contraseñas que aparecen en filtraciones conocidas.
import { computed, onMounted, ref } from 'vue'
import { Check, Circle } from 'lucide-vue-next'
import { configuracionService } from '@/services/configuracion.service'

const props = withDefaults(defineProps<{ clave: string; confirmacion?: string | null }>(), { confirmacion: null })

const reglas = ref({ minimo: 8, mezcla: true })
let pedido: Promise<void> | null = null

onMounted(() => {
  pedido ??= configuracionService
    .obtener()
    .then(({ data }) => {
      if (data.clave) reglas.value = data.clave
    })
    .catch(() => {
      // Sin conexión se muestran las reglas de siempre; el servidor valida igual.
    })
})

const requisitos = computed(() => {
  const c = props.clave
  const lista = [{ texto: `Al menos ${reglas.value.minimo} caracteres`, ok: c.length >= reglas.value.minimo }]
  if (reglas.value.mezcla) {
    lista.push(
      { texto: 'Una letra mayúscula', ok: /\p{Lu}/u.test(c) },
      { texto: 'Una letra minúscula', ok: /\p{Ll}/u.test(c) },
      { texto: 'Un número', ok: /\d/.test(c) },
    )
  }
  if (props.confirmacion !== null) {
    lista.push({ texto: 'Las dos contraseñas coinciden', ok: c.length > 0 && c === props.confirmacion })
  }
  return lista
})
</script>

<template>
  <ul class="grid gap-x-4 gap-y-1 rounded-xl bg-[#fbfaf7] px-3 py-2.5 text-xs sm:grid-cols-2" aria-label="Requisitos de la contraseña">
    <li
      v-for="r in requisitos"
      :key="r.texto"
      class="flex items-center gap-1.5 transition-colors"
      :class="r.ok ? 'text-emerald-700' : 'text-[#8a8379]'"
    >
      <Check v-if="r.ok" :size="13" class="shrink-0" aria-hidden="true" />
      <Circle v-else :size="11" class="mx-px shrink-0" aria-hidden="true" />
      <span>{{ r.texto }}<span class="sr-only">{{ r.ok ? ' (cumple)' : ' (falta)' }}</span></span>
    </li>
  </ul>
</template>
