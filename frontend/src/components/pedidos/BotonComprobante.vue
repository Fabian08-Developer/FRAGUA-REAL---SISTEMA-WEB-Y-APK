<script setup lang="ts">
// Fase 5, tarea 1 — RF-M06-009/010, RN-M06-07, HU-M02-019: abre el comprobante de pago de un
// pedido en otra pestaña, listo para imprimir (ticket de 80 mm en local) o guardar (PDF en
// domicilio y recogida). Con `token` lo pide por el enlace del invitado. Solo aparece cuando el
// pedido ya tiene comprobante (el pago está completo).
// Reimpresión controlada (13/10/2026): con `personal`, avisa cuántas veces ya se imprimió; desde la
// segunda el documento sale marcado "REIMPRESIÓN" y cada impresión queda en la auditoría (backend).
import { computed, ref } from 'vue'
import { FileText, Printer } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { pedidoService } from '@/services/pedido.service'
import { abrirPdf } from '@/utils/documentos'
import type { Pedido } from '@/types/models'

const props = withDefaults(
  defineProps<{ pedido: Pedido; token?: string | null; personal?: boolean; variant?: 'primary' | 'secondary' | 'ghost'; size?: 'sm' | 'md' }>(),
  { token: null, personal: false, variant: 'secondary', size: 'md' },
)

const abriendo = ref(false)
const error = ref('')
// Las que se hicieron desde esta pantalla, además de las que ya traía el pedido.
const impresasAqui = ref(0)
const esTicket = computed(() => props.pedido.comprobante?.tipo === 'impreso')
const impresiones = computed(() => (props.pedido.comprobante?.impresiones ?? 0) + impresasAqui.value)
const texto = computed(() => {
  const numero = props.pedido.comprobante?.numero ?? ''
  if (abriendo.value) return 'Abriendo…'
  if (props.personal && impresiones.value > 0) return `Reimprimir comprobante ${numero}`
  return esTicket.value ? `Imprimir comprobante ${numero}` : `Comprobante ${numero} (PDF)`
})

async function abrir() {
  abriendo.value = true
  error.value = ''
  try {
    await abrirPdf(
      () => (props.token ? pedidoService.comprobantePorEnlace(props.token) : pedidoService.comprobante(props.pedido.id)),
      'No pudimos abrir el comprobante. Intenta de nuevo.',
    )
    if (props.personal) impresasAqui.value++
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No pudimos abrir el comprobante.'
  } finally {
    abriendo.value = false
  }
}
</script>

<template>
  <div v-if="pedido.comprobante" class="inline-flex flex-col gap-1">
    <BaseButton :variant="variant" :size="size" :disabled="abriendo" @click="abrir">
      <Printer v-if="esTicket" :size="15" />
      <FileText v-else :size="15" />
      {{ texto }}
    </BaseButton>
    <p v-if="personal && impresiones > 0" class="text-xs text-gray-500">
      Ya se imprimió {{ impresiones === 1 ? '1 vez' : `${impresiones} veces` }}: saldrá marcado «REIMPRESIÓN».
    </p>
    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
  </div>
</template>
