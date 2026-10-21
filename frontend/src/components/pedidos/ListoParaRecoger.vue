<script setup lang="ts">
// Fase 4, tarea 5 — RF-M07-R-012, RN-M07-R-03, HU-M07-012: «listo para recoger» de una recogida
// en preparación, para el Mesero y el Administrador. Antes de marcarlo dice a qué correo irá el
// aviso (o que hay que llamar, si el pedido no tiene correo); después muestra desde qué hora está
// listo y si el correo salió, sigue en cola o falló (con "Reintentar aviso").
// Solo muestra y avisa: la vista que lo usa llama al backend cuando se emite "marcar" o "reintentar".
import { computed } from 'vue'
import { BellRing, CheckCircle2, RotateCcw } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import type { Pedido, PedidoAviso } from '@/types/models'

const props = defineProps<{ pedido: Pedido; procesando?: boolean }>()
defineEmits<{ (e: 'marcar'): void; (e: 'reintentar', aviso: PedidoAviso): void }>()

const visible = computed(
  () => props.pedido.tipo === 'recogida' && props.pedido.estado === 'preparando' && !props.pedido.recogido_at,
)
const correo = computed(() => props.pedido.cliente_invitado_correo ?? props.pedido.cliente?.email ?? null)
const aviso = computed(() => props.pedido.avisos?.find((a) => a.tipo === 'listo_para_recoger') ?? null)

function hora(valor: string | null | undefined) {
  return valor ? new Date(valor).toLocaleTimeString('es-CO', { timeZone: 'America/Bogota', hour: 'numeric', minute: '2-digit' }) : ''
}
</script>

<template>
  <div v-if="visible" class="mt-3">
    <div v-if="!pedido.listo_at" class="flex flex-col gap-2 rounded-lg bg-violet-50 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
      <p class="text-xs text-violet-900">
        <template v-if="correo">¿Ya está listo? Al marcarlo le avisamos por correo a <strong>{{ correo }}</strong>.</template>
        <template v-else>Este pedido no tiene correo: después de marcarlo, llama al cliente para avisarle.</template>
      </p>
      <BaseButton v-escritura variant="primary" size="sm" :disabled="procesando" @click="$emit('marcar')">
        <BellRing :size="15" /> {{ procesando ? 'Marcando…' : 'Listo para recoger' }}
      </BaseButton>
    </div>

    <div v-else class="space-y-1.5 rounded-lg bg-emerald-50 px-3 py-2.5 text-xs text-emerald-900">
      <p class="flex items-center gap-1.5 font-semibold">
        <CheckCircle2 :size="15" class="shrink-0" /> Listo para recoger desde las {{ hora(pedido.listo_at) }}
      </p>
      <p v-if="!aviso" class="text-amber-800">Sin correo: avísale al cliente por teléfono.</p>
      <p v-else-if="aviso.estado === 'enviado'">Aviso enviado a {{ aviso.destino }} a las {{ hora(aviso.enviado_at) }}.</p>
      <p v-else-if="aviso.estado === 'pendiente'">Aviso en cola para {{ aviso.destino }}…</p>
      <div v-else class="flex flex-col gap-1.5 text-red-800 sm:flex-row sm:items-center sm:justify-between">
        <span>No se pudo enviar el aviso a {{ aviso.destino }}<template v-if="aviso.error">: {{ aviso.error }}</template>. Llama al cliente o reinténtalo.</span>
        <BaseButton v-escritura variant="secondary" size="sm" :disabled="procesando" @click="$emit('reintentar', aviso)">
          <RotateCcw :size="14" /> Reintentar aviso
        </BaseButton>
      </div>
    </div>
  </div>
</template>
