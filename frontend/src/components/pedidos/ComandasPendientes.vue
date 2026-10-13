<script setup lang="ts">
// Fase 6, tarea 2 (RNF-M02-011, RF-M02-T-004/006, HU-M02-016, D5): franja con las comandas que la
// cocina todavía no tiene (pendientes o que no salieron), visible en todas las pantallas del Mesero.
// Se actualiza cada 15 segundos y cuando se confirma, cambia o cancela un pedido. Con «Abrir solas»
// prendido en este equipo, cada comanda nueva se abre sola para imprimir (en la cocina, con Chrome en
// modo de impresión automática, sale sin pasar por el cuadro de impresión).
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { AlertTriangle, ChefHat, ChevronDown } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { comandaService, type ComandaPendiente } from '@/services/comanda.service'
import { mensajeDeError } from '@/services/errors'
import { abrirPdf } from '@/utils/documentos'
import { EVENTO_COMANDAS, TIPOS_COMANDA, imprimirAlConfirmar, imprimirAutomatico } from '@/utils/comandas'

const lista = ref<ComandaPendiente[]>([])
const abierta = ref(false)
const error = ref('')
const imprimiendo = ref<number | null>(null)
// La última que se mandó a imprimir desde aquí, para poder decir «No salió».
const ultima = ref<ComandaPendiente | null>(null)
const automatico = ref(imprimirAutomatico.get())
const alConfirmar = ref(imprimirAlConfirmar.get())
const vistas = new Set<number>()
let primeraCarga = true
let temporizador: ReturnType<typeof setInterval> | null = null

const fallidas = computed(() => lista.value.filter((c) => c.estado_impresion === 'fallida').length)

function titulo(c: ComandaPendiente) {
  const donde = c.pedido.mesa ?? (c.pedido.tipo === 'domicilio' ? 'Domicilio' : c.pedido.tipo === 'recogida' ? 'Para recoger' : 'Local')
  return `${TIPOS_COMANDA[c.tipo]} ${c.pedido_id}-${c.numero} · ${donde}${c.pedido.cliente && !c.pedido.mesa ? ` · ${c.pedido.cliente}` : ''}`
}

function minutos(c: ComandaPendiente) {
  const m = Math.max(0, Math.round((Date.now() - new Date(c.created_at).getTime()) / 60000))
  return m === 0 ? 'ahora' : `hace ${m} min`
}

async function cargar() {
  if (document.hidden) return
  try {
    const { data } = await comandaService.pendientes()
    lista.value = data
    error.value = ''
    const nuevas = data.filter((c) => c.estado_impresion === 'pendiente' && !vistas.has(c.id))
    data.forEach((c) => vistas.add(c.id))
    // RF-M02-T-006: en la primera carga no se abre nada solo (podría ser una pila vieja).
    if (automatico.value && !primeraCarga && nuevas.length) {
      for (const c of nuevas) await imprimir(c, true)
    }
    primeraCarga = false
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos consultar las comandas pendientes.')
  }
}

async function imprimir(c: ComandaPendiente, sola = false) {
  imprimiendo.value = c.id
  try {
    const ventana = window.open('', '_blank')
    if (!ventana && sola) {
      error.value = 'El navegador bloqueó la comanda nueva: permite las ventanas emergentes de este sitio o imprímela aquí.'
      return
    }
    await abrirPdf(() => comandaService.ticket(c.id), 'No pudimos abrir la comanda. Intenta de nuevo.', ventana)
    ultima.value = c
    lista.value = lista.value.filter((x) => x.id !== c.id)
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No pudimos abrir la comanda.'
  } finally {
    imprimiendo.value = null
  }
}

async function noSalio() {
  if (!ultima.value) return
  try {
    await comandaService.noImpresa(ultima.value.id)
    ultima.value = null
    await cargar()
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos registrarlo.')
  }
}

function cambiarAutomatico() {
  automatico.value = !automatico.value
  imprimirAutomatico.set(automatico.value)
}
function cambiarAlConfirmar() {
  alConfirmar.value = !alConfirmar.value
  imprimirAlConfirmar.set(alConfirmar.value)
}

const alAvisar = () => cargar()
onMounted(() => {
  cargar()
  temporizador = setInterval(cargar, 15000)
  window.addEventListener(EVENTO_COMANDAS, alAvisar)
  document.addEventListener('visibilitychange', alAvisar)
})
onBeforeUnmount(() => {
  if (temporizador) clearInterval(temporizador)
  window.removeEventListener(EVENTO_COMANDAS, alAvisar)
  document.removeEventListener('visibilitychange', alAvisar)
})
</script>

<template>
  <div class="mb-5 space-y-2">
    <p v-if="ultima" class="flex flex-wrap items-center gap-2 rounded-xl bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
      {{ titulo(ultima) }} enviada a imprimir.
      <button type="button" class="font-medium text-red-700 underline" @click="noSalio">¿No salió?</button>
      <button type="button" class="ml-auto text-xs text-emerald-700 underline" @click="ultima = null">Cerrar</button>
    </p>

    <section v-if="lista.length" class="overflow-hidden rounded-2xl border border-amber-300 bg-amber-50">
      <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left" @click="abierta = !abierta">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
          <AlertTriangle v-if="fallidas" :size="16" />
          <ChefHat v-else :size="16" />
        </span>
        <span class="min-w-0 flex-1">
          <span class="block text-sm font-semibold text-amber-900">
            {{ lista.length === 1 ? '1 comanda por imprimir' : `${lista.length} comandas por imprimir` }}
          </span>
          <span class="block text-xs text-amber-800">
            <template v-if="fallidas">{{ fallidas === 1 ? '1 no salió' : `${fallidas} no salieron` }} · </template>
            La cocina todavía no las tiene. El pedido sigue igual mientras tanto.
          </span>
        </span>
        <ChevronDown :size="16" class="shrink-0 text-amber-700 transition" :class="{ 'rotate-180': abierta }" />
      </button>

      <div v-if="abierta" class="border-t border-amber-200 bg-white/70 px-4 py-3">
        <ul class="space-y-2">
          <li v-for="c in lista" :key="c.id" class="flex flex-col gap-2 rounded-xl border border-amber-100 bg-white p-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-ink">{{ titulo(c) }}</p>
              <p class="text-xs text-gray-500">
                {{ c.lineas }} {{ c.lineas === 1 ? 'línea' : 'líneas' }} · {{ minutos(c) }}
                <template v-if="c.estado_impresion === 'fallida'"> · <span class="text-red-700">No salió: {{ c.ultimo_error }}</span></template>
              </p>
            </div>
            <BaseButton size="sm" variant="primary" class="shrink-0" :disabled="imprimiendo === c.id" @click="imprimir(c)">
              <ChefHat :size="14" /> {{ imprimiendo === c.id ? 'Abriendo…' : 'Imprimir' }}
            </BaseButton>
          </li>
        </ul>
      </div>
    </section>

    <p v-if="error" class="alert-error">{{ error }}</p>

    <details class="text-xs text-gray-500">
      <summary class="cursor-pointer select-none">Impresión de comandas en este equipo</summary>
      <div class="mt-2 space-y-1.5 rounded-xl bg-white p-3">
        <label class="flex items-center gap-2">
          <input type="checkbox" :checked="alConfirmar" @change="cambiarAlConfirmar" />
          Abrir la comanda al confirmar un pedido
        </label>
        <label class="flex items-center gap-2">
          <input type="checkbox" :checked="automatico" @change="cambiarAutomatico" />
          Abrir solas las comandas nuevas (para el equipo de la cocina)
        </label>
        <p>
          Para que salgan sin el cuadro de impresión, abre Chrome con la opción de impresión automática
          (<code>--kiosk-printing</code>) y la impresora de 80 mm como predeterminada. Permite las ventanas
          emergentes de este sitio.
        </p>
      </div>
    </details>
  </div>
</template>
