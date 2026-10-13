<script setup lang="ts">
// Fase 1, tarea 1 — Historial de un pedido (HU-M02-020, RN-PED-12, RF-M07-028): creación,
// cambios de estado, productos agregados/cambiados/quitados y pagos, con quién y cuándo.
import { ref, watch } from 'vue'
import { ChefHat, CircleDollarSign, Flag, PencilLine } from 'lucide-vue-next'
import BaseModal from '@/components/common/BaseModal.vue'
import { pedidoService, type EventoHistorialPedido } from '@/services/pedido.service'
import { mensajeDeError } from '@/services/errors'

const props = defineProps<{ pedidoId: number | null }>()
const emit = defineEmits<{ (e: 'cerrar'): void }>()

const eventos = ref<EventoHistorialPedido[]>([])
const cargando = ref(false)
const error = ref('')

const ICONOS = { estado: Flag, modificacion: PencilLine, pago: CircleDollarSign, comanda: ChefHat }

watch(
  () => props.pedidoId,
  async (id) => {
    eventos.value = []
    error.value = ''
    if (!id) return
    cargando.value = true
    try {
      const { data } = await pedidoService.historial(id)
      eventos.value = data.data
    } catch (e) {
      error.value = mensajeDeError(e, 'No pudimos cargar el historial.')
    } finally {
      cargando.value = false
    }
  },
  { immediate: true },
)

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function formatoFecha(iso: string) {
  return new Date(iso).toLocaleString('es-CO', {
    timeZone: 'America/Bogota',
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  })
}
</script>

<template>
  <BaseModal
    :model-value="!!pedidoId"
    :title="pedidoId ? `Historial del pedido #${pedidoId}` : 'Historial'"
    @update:model-value="emit('cerrar')"
  >
    <p v-if="error" class="alert-error">{{ error }}</p>
    <p v-else-if="cargando" class="text-sm text-gray-500">Cargando historial…</p>

    <ol v-else class="relative ml-3 border-l border-gray-200">
      <li v-for="(evento, i) in eventos" :key="i" class="mb-4 ml-5 last:mb-0">
        <span
          class="absolute -left-3.5 flex size-7 items-center justify-center rounded-full ring-4 ring-white"
          :class="{
            'bg-gray-100 text-gray-600': evento.tipo === 'estado',
            'bg-brand-50 text-brand-700': evento.tipo === 'modificacion',
            'bg-emerald-50 text-emerald-700': evento.tipo === 'pago',
            'bg-amber-50 text-amber-700': evento.tipo === 'comanda',
          }"
        >
          <component :is="ICONOS[evento.tipo]" :size="14" />
        </span>
        <p class="text-sm font-medium text-ink">{{ evento.descripcion }}</p>
        <p class="text-xs text-gray-500">
          {{ formatoFecha(evento.fecha) }}<template v-if="evento.quien"> · {{ evento.quien }}</template>
        </p>
        <p
          v-if="evento.tipo === 'modificacion' && evento.total_anterior !== evento.total_nuevo"
          class="mt-0.5 text-xs text-gray-600"
        >
          Total {{ formatoPrecio(evento.total_anterior ?? 0) }} → {{ formatoPrecio(evento.total_nuevo ?? 0) }}
        </p>
        <span
          v-if="evento.afecta_comanda"
          class="badge mt-1 bg-sky-50 text-sky-700 ring-sky-600/20"
          title="Cambio hecho con el pedido ya confirmado: fue a la cocina en una comanda de cambios"
        >
          Con el pedido confirmado
        </span>
      </li>
      <li v-if="!eventos.length" class="ml-5 text-sm text-gray-500">Sin eventos.</li>
    </ol>
  </BaseModal>
</template>
