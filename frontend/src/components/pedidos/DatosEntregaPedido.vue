<script setup lang="ts">
// Fase 1, tarea 2 — Datos de contacto y entrega de un domicilio o una recogida, para el
// Mesero y el Administrador (RF-M07-003 a 007, RF-M07-010, RF-M07-R-003). El teléfono es un
// enlace para llamar directo desde el celular. Un pedido local no muestra nada.
import { computed } from 'vue'
import { Mail, MapPin, MessageSquareText, Phone, User, Wallet } from 'lucide-vue-next'
import type { Pedido } from '@/types/models'

const props = defineProps<{ pedido: Pedido }>()

const nombre = computed(() => props.pedido.cliente_invitado_nombre ?? props.pedido.cliente?.nombre ?? null)
const telefono = computed(
  () => props.pedido.telefono_contacto ?? props.pedido.cliente_invitado_telefono ?? props.pedido.cliente?.telefono ?? null,
)
// RF-M07-013/015: lo que el cliente dijo que iba a usar para pagar.
const formaDePago = computed(() => {
  const p = props.pedido
  if (p.metodo_pago_preferido === 'en_linea') {
    return `En línea${p.pasarela_preferida ? ` (${p.pasarela_preferida === 'nequi' ? 'Nequi' : 'Bancolombia'})` : ''}`
  }
  if (p.metodo_pago_preferido === 'contra_entrega') return p.tipo === 'domicilio' ? 'Contra entrega' : 'Al recoger'
  return null
})
// Fase 4, tarea 5: a dónde llega el aviso «listo para recoger» (el del invitado o el de la cuenta).
const correo = computed(() => props.pedido.cliente_invitado_correo ?? props.pedido.cliente?.email ?? null)
const enlaceTelefono = computed(() => (telefono.value ? `tel:${telefono.value.replace(/[^\d+]/g, '')}` : ''))
</script>

<template>
  <div v-if="pedido.tipo !== 'local'" class="mb-4 space-y-1.5 rounded-xl border border-gray-200 bg-white px-3.5 py-3 text-sm">
    <p v-if="nombre" class="flex items-center gap-2 text-ink">
      <User :size="15" class="shrink-0 text-gray-400" />
      <span class="font-semibold">{{ nombre }}</span>
    </p>
    <p v-if="telefono" class="flex items-center gap-2">
      <Phone :size="15" class="shrink-0 text-gray-400" />
      <a :href="enlaceTelefono" class="font-medium text-brand-700 underline-offset-2 hover:underline">{{ telefono }}</a>
    </p>
    <p v-if="correo" class="flex items-center gap-2 text-gray-700">
      <Mail :size="15" class="shrink-0 text-gray-400" />
      <span class="truncate">{{ correo }}</span>
    </p>
    <p v-if="pedido.tipo === 'domicilio'" class="flex items-start gap-2 text-ink">
      <MapPin :size="15" class="mt-0.5 shrink-0 text-gray-400" />
      <span>
        {{ pedido.direccion_entrega }}<template v-if="pedido.barrio"> · {{ pedido.barrio }}</template>
        <span v-if="pedido.referencia" class="block text-xs text-gray-500">{{ pedido.referencia }}</span>
      </span>
    </p>
    <p v-if="formaDePago" class="flex items-center gap-2 text-gray-700">
      <Wallet :size="15" class="shrink-0 text-gray-400" />
      <span>Paga: {{ formaDePago }}</span>
    </p>
    <p v-if="pedido.observaciones_entrega" class="flex items-start gap-2 text-gray-700">
      <MessageSquareText :size="15" class="mt-0.5 shrink-0 text-gray-400" />
      <span class="italic">“{{ pedido.observaciones_entrega }}”</span>
    </p>
  </div>
</template>
