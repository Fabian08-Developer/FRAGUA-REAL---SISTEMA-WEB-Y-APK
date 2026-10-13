<script setup lang="ts">
// Fase 6, tarea 1 (RF-M06-006, RF-M06-008, RF-M05-030, decisión D4: Wompi) — a donde vuelve el
// Cliente después de pagar en la pasarela (/pago/retorno/<referencia>?id=<transacción>). Pregunta
// al backend cómo quedó el pago; si sigue en proceso (Nequi espera que lo apruebes en la app),
// vuelve a preguntar cada pocos segundos. La confirmación de verdad llega por el evento firmado
// de la pasarela; esta página solo la muestra.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { CheckCircle2, Clock, XCircle } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { pasarelaService, type RetornoPasarela } from '@/services/pasarela.service'
import { mensajeDeError } from '@/services/errors'
import { metodoPasarela } from '@/utils/pasarela'

const route = useRoute()
const referencia = String(route.params.referencia ?? '')
const idPasarela = typeof route.query.id === 'string' ? route.query.id : undefined

const datos = ref<RetornoPasarela | null>(null)
const cargando = ref(true)
const noEncontrado = ref(false)
const error = ref('')
const intentos = ref(0)
const MAX_INTENTOS = 45 // ~3 minutos a 4 s
let temporizador: ReturnType<typeof setTimeout> | null = null

async function consultar() {
  error.value = ''
  try {
    const { data } = await pasarelaService.retorno(referencia, idPasarela)
    datos.value = data
    intentos.value++
    if (data.estado === 'pendiente' && intentos.value < MAX_INTENTOS) {
      temporizador = setTimeout(consultar, 4000)
    }
  } catch (e) {
    if ((e as { response?: { status?: number } }).response?.status === 404) noEncontrado.value = true
    else error.value = mensajeDeError(e, 'No pudimos consultar tu pago.')
  } finally {
    cargando.value = false
  }
}

function volverAConsultar() {
  intentos.value = 0
  cargando.value = true
  consultar()
}

const resultado = computed(() => {
  const d = datos.value
  if (!d) return null
  if (d.estado === 'aprobado') {
    if (d.en_revision)
      return { tono: 'pendiente', titulo: 'Recibimos tu pago', texto: 'Lo estamos revisando; te avisamos en cuanto quede listo.' }
    return d.tipo === 'reserva'
      ? { tono: 'ok', titulo: '¡Depósito aprobado!', texto: 'Tu reserva quedó confirmada. Te enviamos la confirmación por correo.' }
      : { tono: 'ok', titulo: '¡Pago aprobado!', texto: 'Ya confirmamos tu pago. En breve tu pedido entra a cocina.' }
  }
  if (d.estado === 'pendiente') {
    return {
      tono: 'pendiente',
      titulo: 'Tu pago está en proceso',
      texto:
        intentos.value >= MAX_INTENTOS
          ? 'La pasarela todavía no lo confirma. Si ya pagaste, en cuanto llegue la confirmación lo aplicamos solos.'
          : 'Si pagas con Nequi, aprueba la notificación en la app. Esta página se actualiza sola.',
    }
  }
  const textos: Record<string, string> = {
    rechazado: 'La pasarela no aprobó el pago. No se te cobró nada; puedes intentarlo de nuevo.',
    error: 'La pasarela tuvo un error con el pago. No se te cobró nada; puedes intentarlo de nuevo.',
    anulado: 'El pago se anuló.',
    abandonado: 'El pago no se completó. Puedes intentarlo de nuevo.',
  }
  return { tono: 'error', titulo: 'El pago no se completó', texto: textos[d.estado] ?? textos.error }
})

// A dónde volver: el seguimiento del invitado, Mis pedidos del Cliente o el enlace de la reserva.
const destino = computed(() => {
  const d = datos.value
  if (!d) return null
  if (d.tipo === 'reserva') {
    return d.reserva?.enlace ? { to: { name: 'reserva-enlace', params: { token: d.reserva.enlace } }, texto: 'Ver mi reserva' } : null
  }
  if (d.pedido?.seguimiento) return { to: { name: 'seguimiento-pedido', params: { token: d.pedido.seguimiento } }, texto: 'Ver mi pedido' }
  if (d.pedido?.de_cliente) return { to: { name: 'cliente-pedidos' }, texto: 'Ver mis pedidos' }
  return null
})

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

onMounted(consultar)
onBeforeUnmount(() => {
  if (temporizador) clearTimeout(temporizador)
})
</script>

<template>
  <div class="mx-auto max-w-lg px-4 py-12 sm:px-6">
    <p v-if="cargando && !datos" class="text-center text-sm text-[#6c655c]">Consultando tu pago…</p>

    <div v-else-if="noEncontrado" class="rounded-2xl border border-[#e2dbcf] bg-white p-8 text-center">
      <XCircle :size="40" class="mx-auto text-[#c8c1b2]" />
      <h1 class="mt-4 font-display text-2xl text-ink">No encontramos ese pago</h1>
      <p class="mt-2 text-sm text-[#6c655c]">Revisa que el enlace esté completo, o comunícate con el local.</p>
    </div>

    <div v-else-if="datos && resultado" class="rounded-2xl border border-[#e2dbcf] bg-white p-8 text-center">
      <CheckCircle2 v-if="resultado.tono === 'ok'" :size="48" class="mx-auto text-brand-600" />
      <Clock v-else-if="resultado.tono === 'pendiente'" :size="48" class="mx-auto animate-pulse text-amber-500" />
      <XCircle v-else :size="48" class="mx-auto text-red-500" />
      <h1 class="mt-4 font-display text-2xl text-ink">{{ resultado.titulo }}</h1>
      <p class="mt-2 text-sm text-[#6c655c]">{{ resultado.texto }}</p>

      <dl class="mx-auto mt-5 max-w-xs space-y-1 text-left text-sm">
        <div class="flex justify-between gap-4">
          <dt class="text-[#6c655c]">{{ datos.tipo === 'reserva' ? 'Depósito de la reserva' : `Pedido #${datos.pedido?.id ?? ''}` }}</dt>
          <dd class="font-medium text-ink">{{ formatoPrecio(datos.monto) }}</dd>
        </div>
        <div v-if="datos.metodo_pago" class="flex justify-between gap-4">
          <dt class="text-[#6c655c]">Medio</dt>
          <dd class="text-ink">{{ metodoPasarela(datos.metodo_pago) }}</dd>
        </div>
        <div class="flex justify-between gap-4">
          <dt class="text-[#6c655c]">Referencia</dt>
          <dd class="break-all text-right font-mono text-xs text-ink">{{ datos.referencia }}</dd>
        </div>
      </dl>

      <p v-if="error" class="mt-4 alert-error">{{ error }}</p>

      <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
        <BaseButton v-if="datos.estado === 'pendiente' && intentos >= 45" variant="ghost" @click="volverAConsultar">
          Volver a consultar
        </BaseButton>
        <router-link
          v-if="destino"
          :to="destino.to"
          class="inline-flex items-center justify-center rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink transition hover:bg-brand-400"
        >
          {{ resultado.tono === 'error' ? 'Intentar de nuevo' : destino.texto }}
        </router-link>
        <router-link
          :to="{ name: 'home' }"
          class="inline-flex items-center justify-center rounded-xl border border-[#e3ded4] px-6 py-3 text-sm font-medium text-ink transition hover:bg-[#fbfaf7]"
        >
          Volver al inicio
        </router-link>
      </div>
    </div>

    <p v-else-if="error" class="alert-error">{{ error }}</p>
  </div>
</template>
