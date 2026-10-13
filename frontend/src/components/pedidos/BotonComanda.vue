<script setup lang="ts">
// Fase 6, tarea 2 (RN-PED-06/09/18/19, HU-M02-016/017, D5): la comanda de cocina de un pedido.
// Si hay una por imprimir (pendiente o que no salió) la imprime; si no, ofrece reimprimir la última,
// que sale marcada «REIMPRESIÓN» (regla de reimpresión). Tras imprimir, «No salió» la devuelve a
// pendientes. El estado de impresión no cambia el pedido (RN-PED-19).
import { computed, ref } from 'vue'
import { ChefHat } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { comandaService } from '@/services/comanda.service'
import { mensajeDeError } from '@/services/errors'
import { abrirPdf } from '@/utils/documentos'
import { TIPOS_COMANDA, avisarComandas, porImprimir } from '@/utils/comandas'
import type { Comanda, Pedido } from '@/types/models'

const props = withDefaults(defineProps<{ pedido: Pedido; size?: 'sm' | 'md' }>(), { size: 'sm' })

// Cambios hechos desde esta pantalla, encima de lo que trae el pedido.
const locales = ref<Record<number, Partial<Comanda>>>({})
const comandas = computed(() =>
  (props.pedido.comandas ?? []).map((c) => ({ ...c, ...(locales.value[c.id] ?? {}) })).filter((c) => c.estado_impresion !== 'descartada'),
)
const pendiente = computed(() => comandas.value.find(porImprimir) ?? null)
const ultimaImpresa = computed(() => [...comandas.value].reverse().find((c) => c.estado_impresion === 'impresa') ?? null)
const recienImpresa = ref<Comanda | null>(null)

const abriendo = ref(false)
const error = ref('')

function nombre(c: Comanda) {
  return `${TIPOS_COMANDA[c.tipo]} ${c.pedido_id}-${c.numero}`
}

async function imprimir(c: Comanda) {
  abriendo.value = true
  error.value = ''
  try {
    await abrirPdf(() => comandaService.ticket(c.id), 'No pudimos abrir la comanda. Intenta de nuevo.')
    const reimpresion = c.estado_impresion === 'impresa'
    locales.value[c.id] = { estado_impresion: 'impresa', impresiones: c.impresiones + 1, intentos: c.intentos + 1 }
    recienImpresa.value = reimpresion ? null : { ...c, estado_impresion: 'impresa' }
    avisarComandas()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No pudimos abrir la comanda.'
  } finally {
    abriendo.value = false
  }
}

async function noSalio(c: Comanda) {
  error.value = ''
  try {
    const { data } = await comandaService.noImpresa(c.id)
    locales.value[c.id] = data
    recienImpresa.value = null
    avisarComandas()
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos registrarlo.')
  }
}
</script>

<template>
  <div v-if="comandas.length" class="inline-flex flex-col gap-1">
    <template v-if="pendiente">
      <span class="badge w-fit bg-amber-50 text-amber-800 ring-amber-600/20">
        {{ pendiente.estado_impresion === 'fallida' ? 'La comanda no salió' : 'Comanda por imprimir' }}
      </span>
      <BaseButton variant="primary" :size="size" :disabled="abriendo" @click="imprimir(pendiente)">
        <ChefHat :size="15" />
        {{ abriendo ? 'Abriendo…' : `Imprimir ${nombre(pendiente).toLowerCase()}` }}
      </BaseButton>
    </template>
    <template v-else-if="ultimaImpresa">
      <BaseButton variant="secondary" :size="size" :disabled="abriendo" @click="imprimir(ultimaImpresa)">
        <ChefHat :size="15" />
        {{ abriendo ? 'Abriendo…' : `Reimprimir ${nombre(ultimaImpresa).toLowerCase()}` }}
      </BaseButton>
      <p class="text-xs text-gray-500">Ya salió: la copia dirá «REIMPRESIÓN».</p>
    </template>
    <p v-if="recienImpresa" class="text-xs text-gray-600">
      ¿No salió de la impresora?
      <button type="button" class="font-medium text-red-700 underline" @click="noSalio(recienImpresa)">Avisar</button>
    </p>
    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
  </div>
</template>
