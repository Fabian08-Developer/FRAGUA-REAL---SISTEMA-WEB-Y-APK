<script setup lang="ts">
// Fase 6, tarea 1 (RF-M06-006, RF-M06-017, decisión D4: Wompi) — estado de la pasarela y los pagos
// en línea que el sistema no decidió solo: un monto distinto, un pago aprobado por Wompi que ya se
// había rechazado a mano o dado por vencido, un pedido cancelado ya pagado, una reserva que no se
// pudo confirmar o un reembolso que falló. El Administrador lo resuelve (reembolso en Caja o desde
// el panel de Wompi, aprobación a mano…) y aquí deja la nota de lo que hizo.
import { onMounted, ref } from 'vue'
import { AlertTriangle, CheckCircle2, Smartphone } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { pasarelaService, type EstadoPasarela, type TransaccionRevision } from '@/services/pasarela.service'
import { mensajeDeError } from '@/services/errors'
import { ESTADO_REEMBOLSO, ESTADO_TRANSACCION, metodoPasarela } from '@/utils/pasarela'

const estado = ref<EstadoPasarela | null>(null)
const lista = ref<TransaccionRevision[]>([])
const error = ref('')
const abierta = ref<number | null>(null)
const nota = ref('')
const procesando = ref<number | null>(null)

async function cargar() {
  error.value = ''
  try {
    const [e, l] = await Promise.all([pasarelaService.estado(), pasarelaService.porRevisar()])
    estado.value = e.data
    lista.value = l.data.data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar el estado de la pasarela.')
  }
}

async function marcarRevisada(tx: TransaccionRevision) {
  if (nota.value.trim().length < 5) {
    error.value = 'Escribe qué hiciste (al menos 5 caracteres).'
    return
  }
  procesando.value = tx.id
  error.value = ''
  try {
    await pasarelaService.marcarRevisada(tx.id, nota.value.trim())
    abierta.value = null
    nota.value = ''
    await cargar()
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos guardar la revisión.')
  } finally {
    procesando.value = null
  }
}

async function reintentarReembolso(tx: TransaccionRevision) {
  procesando.value = tx.id
  error.value = ''
  try {
    await pasarelaService.reintentarReembolso(tx.id)
    await cargar()
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos reintentar el reembolso.')
  } finally {
    procesando.value = null
  }
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

onMounted(cargar)
</script>

<template>
  <section v-if="estado" class="mt-7 card p-5">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="flex items-center gap-2 text-sm font-semibold text-ink">
        <span class="flex size-7 items-center justify-center rounded-lg bg-sky-50 text-sky-600"><Smartphone :size="15" /></span>
        Pagos en línea
      </h2>
      <span
        class="badge"
        :class="estado.activa ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20'"
      >
        {{ estado.activa ? `Wompi activo (${estado.ambiente === 'produccion' ? 'producción' : 'pruebas'})` : 'Aprobación manual' }}
      </span>
    </div>
    <p class="mt-1.5 text-xs text-gray-500">
      <template v-if="estado.activa">
        Los pagos se confirman solos con el aviso firmado de Wompi. URL de eventos para el panel de Wompi:
        <span class="break-all font-mono">{{ estado.url_eventos }}</span>
      </template>
      <template v-else-if="estado.configurada">
        Las llaves de Wompi están, pero PASARELA_PROVEEDOR no es "wompi": los pagos en línea los aprueba el personal.
      </template>
      <template v-else>
        Sin llaves de Wompi en el servidor: los pagos en línea quedan en revisión y los aprueba el Mesero o el Administrador.
      </template>
    </p>

    <p v-if="error" class="mt-3 alert-error">{{ error }}</p>

    <p v-if="!lista.length" class="mt-3 flex items-center gap-2 text-xs text-emerald-700">
      <CheckCircle2 :size="14" /> No hay pagos en línea por revisar.
    </p>
    <ul v-else class="mt-3 space-y-2">
      <li v-for="tx in lista" :key="tx.id" class="rounded-xl border border-amber-200 bg-amber-50/60 p-3">
        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
          <AlertTriangle :size="15" class="text-amber-600" />
          {{ tx.tipo === 'reserva' ? `Depósito de la reserva #${tx.reserva_id}` : `Pedido #${tx.pedido_id}` }}
          · {{ formatoPrecio(tx.monto) }}
          <span class="badge bg-white text-gray-600 ring-gray-300">{{ ESTADO_TRANSACCION[tx.estado] }}</span>
        </p>
        <p class="mt-1 text-xs text-amber-900">{{ tx.motivo_revision }}</p>
        <p class="mt-0.5 text-xs text-gray-500">
          <template v-if="tx.metodo_pago">{{ metodoPasarela(tx.metodo_pago) }} · </template>
          ref. <span class="font-mono">{{ tx.referencia }}</span>
          <template v-if="tx.transaccion_id"> · Wompi {{ tx.transaccion_id }}</template>
        </p>
        <p v-if="tx.reembolso_estado" class="mt-0.5 text-xs" :class="tx.reembolso_estado === 'fallido' ? 'text-red-700' : 'text-gray-600'">
          {{ ESTADO_REEMBOLSO[tx.reembolso_estado] }}<template v-if="tx.reembolso_mensaje">: {{ tx.reembolso_mensaje }}</template>
        </p>

        <div v-if="abierta === tx.id" class="mt-3 space-y-2">
          <textarea
            v-model="nota"
            rows="2"
            maxlength="255"
            class="field w-full text-sm"
            placeholder="Qué hiciste: lo reembolsé en Caja, lo devolví desde el panel de Wompi, lo aprobé a mano…"
          />
          <div class="flex gap-2">
            <BaseButton size="sm" variant="ghost" @click="abierta = null">Cancelar</BaseButton>
            <BaseButton size="sm" :disabled="procesando === tx.id" @click="marcarRevisada(tx)">Guardar revisión</BaseButton>
          </div>
        </div>
        <div v-else class="mt-2 flex flex-wrap gap-2">
          <BaseButton
            v-if="tx.reembolso_estado === 'fallido'"
            size="sm"
            variant="secondary"
            :disabled="procesando === tx.id"
            @click="reintentarReembolso(tx)"
          >
            Reintentar reembolso
          </BaseButton>
          <BaseButton size="sm" variant="secondary" @click="(abierta = tx.id), (nota = '')">Marcar como revisado</BaseButton>
        </div>
      </li>
    </ul>
  </section>
</template>
