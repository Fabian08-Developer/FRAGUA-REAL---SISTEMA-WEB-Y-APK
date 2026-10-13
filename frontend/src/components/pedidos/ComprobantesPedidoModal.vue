<script setup lang="ts">
// Reimpresión controlada (13/10/2026, decisión del usuario) — solo Administrador: todos los
// comprobantes de un pedido, el vigente y los anulados (con fecha y motivo), y cuántas veces se
// imprimió cada uno. Cualquiera se puede abrir: un anulado sale con su sello ANULADO. Sirve para
// aclarar un reclamo ("aquí está mi comprobante") o explicar un número que no aparece vigente.
import { ref, watch } from 'vue'
import { FileText } from 'lucide-vue-next'
import BaseModal from '@/components/common/BaseModal.vue'
import BaseButton from '@/components/common/BaseButton.vue'
import { pedidoService } from '@/services/pedido.service'
import { mensajeDeError } from '@/services/errors'
import { abrirPdf } from '@/utils/documentos'
import type { Comprobante } from '@/types/models'

const props = defineProps<{ pedidoId: number | null }>()
const emit = defineEmits<{ (e: 'cerrar'): void }>()

const comprobantes = ref<Comprobante[]>([])
const cargando = ref(false)
const error = ref('')
const abriendo = ref<number | null>(null)

async function cargar(id: number) {
  cargando.value = true
  error.value = ''
  try {
    comprobantes.value = (await pedidoService.comprobantes(id)).data.data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar los comprobantes.')
  } finally {
    cargando.value = false
  }
}

watch(
  () => props.pedidoId,
  (id) => {
    comprobantes.value = []
    if (id) cargar(id)
  },
  { immediate: true },
)

async function abrir(c: Comprobante) {
  if (!props.pedidoId) return
  abriendo.value = c.id
  error.value = ''
  try {
    await abrirPdf(() => pedidoService.comprobanteDelPedido(props.pedidoId as number, c.id), 'No pudimos abrir el comprobante.')
    c.impresiones++
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No pudimos abrir el comprobante.'
  } finally {
    abriendo.value = null
  }
}

function fecha(valor: string | null) {
  return valor
    ? new Date(valor).toLocaleString('es-CO', { timeZone: 'America/Bogota', day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
    : ''
}
</script>

<template>
  <BaseModal :model-value="pedidoId !== null" :title="`Comprobantes del pedido #${pedidoId ?? ''}`" @update:model-value="emit('cerrar')">
    <p class="text-xs text-gray-500">
      Un comprobante nunca se borra: si el pedido dejó de estar pagado, se anuló y el pago siguiente sacó otro número.
      Abrir uno cuenta como impresión y queda en la auditoría.
    </p>
    <p v-if="error" class="mt-3 alert-error">{{ error }}</p>
    <p v-if="cargando" class="mt-4 text-sm text-gray-500">Cargando…</p>
    <p v-else-if="!comprobantes.length && !error" class="mt-4 text-sm text-gray-500">Este pedido todavía no tiene comprobantes.</p>
    <ul v-else class="mt-4 divide-y divide-gray-100">
      <li v-for="c in comprobantes" :key="c.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0 text-sm">
          <p class="flex flex-wrap items-center gap-2 font-semibold text-ink">
            {{ c.numero }}
            <span v-if="c.anulado_at" class="badge bg-red-50 text-red-700 ring-red-600/20">Anulado</span>
            <span v-else class="badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">Vigente</span>
            <span class="text-xs font-normal text-gray-500">{{ c.tipo === 'impreso' ? 'Ticket' : 'PDF' }}</span>
          </p>
          <p class="mt-0.5 text-xs text-gray-500">
            Generado {{ fecha(c.generado_at) }} · impreso {{ c.impresiones === 1 ? '1 vez' : `${c.impresiones} veces` }}
          </p>
          <p v-if="c.anulado_at" class="mt-0.5 text-xs text-red-700">Anulado {{ fecha(c.anulado_at) }}: {{ c.anulado_motivo }}</p>
        </div>
        <BaseButton variant="secondary" size="sm" :disabled="abriendo === c.id" @click="abrir(c)">
          <FileText :size="14" /> {{ abriendo === c.id ? 'Abriendo…' : 'Abrir' }}
        </BaseButton>
      </li>
    </ul>
  </BaseModal>
</template>
