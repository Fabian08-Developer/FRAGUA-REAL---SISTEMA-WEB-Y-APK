<script setup lang="ts">
// Fase 5, tarea 1 — RF-M06-009/010, RN-M06-07, HU-M02-019: abre el comprobante de pago de un
// pedido en otra pestaña, listo para imprimir (ticket de 80 mm en local) o guardar (PDF en
// domicilio y recogida). Con `token` lo pide por el enlace del invitado. Solo aparece cuando el
// pedido ya tiene comprobante (el pago está completo).
import { computed, ref } from 'vue'
import { FileText, Printer } from 'lucide-vue-next'
import { AxiosError } from 'axios'
import BaseButton from '@/components/common/BaseButton.vue'
import { pedidoService } from '@/services/pedido.service'
import type { Pedido } from '@/types/models'

const props = withDefaults(
  defineProps<{ pedido: Pedido; token?: string | null; variant?: 'primary' | 'secondary' | 'ghost'; size?: 'sm' | 'md' }>(),
  { token: null, variant: 'secondary', size: 'md' },
)

const abriendo = ref(false)
const error = ref('')
const esTicket = computed(() => props.pedido.comprobante?.tipo === 'impreso')

// El 404 de "aún no tiene comprobante" llega como Blob: se lee para mostrar el mensaje.
async function mensajeDe(e: unknown) {
  if (e instanceof AxiosError && e.response?.data instanceof Blob) {
    try {
      return JSON.parse(await e.response.data.text()).message ?? ''
    } catch {
      return ''
    }
  }
  return ''
}

async function abrir() {
  abriendo.value = true
  error.value = ''
  // La pestaña se abre antes de pedir el archivo para que el navegador no la bloquee.
  const ventana = window.open('', '_blank')
  try {
    const { data } = props.token
      ? await pedidoService.comprobantePorEnlace(props.token)
      : await pedidoService.comprobante(props.pedido.id)
    const url = URL.createObjectURL(new Blob([data], { type: 'application/pdf' }))
    if (ventana) ventana.location.href = url
    else window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) {
    ventana?.close()
    error.value = (await mensajeDe(e)) || 'No pudimos abrir el comprobante. Intenta de nuevo.'
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
      {{ abriendo ? 'Abriendo…' : esTicket ? `Imprimir comprobante ${pedido.comprobante.numero}` : `Comprobante ${pedido.comprobante.numero} (PDF)` }}
    </BaseButton>
    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
  </div>
</template>
