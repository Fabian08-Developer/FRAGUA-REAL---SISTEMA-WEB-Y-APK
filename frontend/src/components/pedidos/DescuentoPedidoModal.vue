<script setup lang="ts">
// Fase 2, tarea 1 — Descuento manual (RF-M04-036/037/041, RN-M04-21/22/23, HU-M04-015).
// El Mesero o el Administrador rebajan un pedido con un porcentaje, un valor fijo o una promoción
// de tipo "manual" ya preparada, siempre con un motivo. El descuento reemplaza a las promociones
// automáticas del pedido (no se acumulan). Quitarlo devuelve las automáticas.
import { computed, ref, watch } from 'vue'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import { pedidoService } from '@/services/pedido.service'
import { promocionService } from '@/services/promocion.service'
import { mensajeDeError } from '@/services/errors'
import type { Pedido, Promocion } from '@/types/models'

const props = defineProps<{ pedido: Pedido | null }>()
const emit = defineEmits<{ (e: 'cerrar'): void; (e: 'actualizado', pedido: Pedido): void }>()

const modo = ref<'porcentaje' | 'fijo' | 'promocion'>('porcentaje')
const valor = ref<number | null>(null)
const promocionId = ref<number | null>(null)
const motivo = ref('')
const manuales = ref<Promocion[]>([])
const guardando = ref(false)
const error = ref('')

watch(
  () => props.pedido?.id,
  async (id) => {
    error.value = ''
    valor.value = null
    motivo.value = ''
    promocionId.value = null
    modo.value = 'porcentaje'
    if (!id) return
    try {
      const { data } = await promocionService.manuales()
      manuales.value = data
    } catch {
      manuales.value = [] // sin promociones manuales se puede seguir con porcentaje o valor fijo
    }
  },
  { immediate: true },
)

const tieneAutomaticas = computed(() => !props.pedido?.descuento_manual && (props.pedido?.promociones_aplicadas?.length ?? 0) > 0)

function formatoPrecio(v: number) {
  return Number(v).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function describir(p: Promocion) {
  const cuanto = p.tipo_valor === 'porcentaje' ? `${Number(p.valor)} %` : formatoPrecio(p.valor)
  const aQue =
    p.alcance === 'total'
      ? 'al total'
      : p.alcance === 'producto'
        ? `a ${(p.productos ?? []).map((x) => x.nombre).join(', ') || 'productos'}`
        : `a ${(p.categorias ?? []).map((x) => x.nombre).join(', ') || 'categorías'}`
  return `${p.nombre} — ${cuanto} ${aQue}`
}

// Validación en pantalla (la definitiva es la del servidor): RN-M04-22/23.
function validar(): string {
  if (modo.value === 'promocion') {
    if (!promocionId.value) return 'Elige la promoción.'
  } else {
    const v = Number(valor.value)
    if (!v || v <= 0) return 'El descuento tiene que ser mayor a cero.'
    if (modo.value === 'porcentaje' && v > 100) return 'El porcentaje no puede ser mayor a 100.'
  }
  if (motivo.value.trim().length < 3) return 'Escribe el motivo del descuento.'
  return ''
}

async function aplicar() {
  if (!props.pedido) return
  error.value = validar()
  if (error.value) return
  guardando.value = true
  try {
    const payload =
      modo.value === 'promocion'
        ? { promocion_id: promocionId.value!, motivo: motivo.value.trim() }
        : { tipo_valor: modo.value, valor: Number(valor.value), motivo: motivo.value.trim() }
    const { data } = await pedidoService.aplicarDescuento(props.pedido.id, payload)
    emit('actualizado', data)
    emit('cerrar')
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos aplicar el descuento.')
  } finally {
    guardando.value = false
  }
}

async function quitar() {
  if (!props.pedido) return
  guardando.value = true
  error.value = ''
  try {
    const { data } = await pedidoService.quitarDescuento(props.pedido.id)
    emit('actualizado', data)
    emit('cerrar')
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos quitar el descuento.')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="!!pedido"
    :title="pedido ? `Descuento al pedido #${pedido.id}` : 'Descuento'"
    @update:model-value="emit('cerrar')"
  >
    <template v-if="pedido">
      <div v-if="pedido.descuento_manual" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        <p class="font-semibold">{{ pedido.descuento_manual.nombre }}: −{{ formatoPrecio(pedido.descuento ?? 0) }}</p>
        <p class="mt-0.5 text-xs">Motivo: {{ pedido.descuento_manual_motivo }}<template v-if="pedido.descuento_manual_por"> · {{ pedido.descuento_manual_por.nombre }}</template></p>
        <BaseButton class="mt-3" variant="secondary" :disabled="guardando" @click="quitar">Quitar descuento</BaseButton>
        <p class="mt-2 text-xs">Si aplicas otro, reemplaza a este.</p>
      </div>
      <p v-else-if="tieneAutomaticas" class="rounded-xl bg-amber-50 px-4 py-3 text-xs text-amber-800">
        Este pedido tiene promociones automáticas (−{{ formatoPrecio(pedido.descuento ?? 0) }}). Un descuento manual las reemplaza: no se suman.
      </p>

      <fieldset class="mt-4">
        <legend class="text-sm font-medium text-ink">Tipo de descuento</legend>
        <div class="mt-2 grid grid-cols-3 gap-2">
          <label v-for="op in [{ v: 'porcentaje', t: 'Porcentaje' }, { v: 'fijo', t: 'Valor fijo' }, { v: 'promocion', t: 'Promoción' }]" :key="op.v"
            class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border px-2 text-sm font-semibold"
            :class="[modo === op.v ? 'border-ink bg-ink text-white' : 'border-gray-200 bg-white text-gray-600', op.v === 'promocion' && !manuales.length ? 'pointer-events-none opacity-40' : '']"
          >
            <input v-model="modo" type="radio" class="sr-only" :value="op.v" :disabled="op.v === 'promocion' && !manuales.length" />
            {{ op.t }}
          </label>
        </div>
      </fieldset>

      <label v-if="modo !== 'promocion'" class="mt-4 block">
        <span class="text-sm font-medium text-ink">{{ modo === 'porcentaje' ? 'Porcentaje (1 a 100)' : 'Valor a rebajar (COP)' }}</span>
        <input v-model.number="valor" type="number" inputmode="decimal" :min="modo === 'porcentaje' ? 1 : 100" :max="modo === 'porcentaje' ? 100 : undefined" class="field mt-1.5" />
      </label>
      <label v-else class="mt-4 block">
        <span class="text-sm font-medium text-ink">Promoción</span>
        <select v-model="promocionId" class="field mt-1.5">
          <option :value="null" disabled>Elige una</option>
          <option v-for="p in manuales" :key="p.id" :value="p.id">{{ describir(p) }}</option>
        </select>
      </label>

      <label class="mt-4 block">
        <span class="text-sm font-medium text-ink">Motivo</span>
        <textarea v-model="motivo" rows="2" maxlength="255" class="field mt-1.5" placeholder="Ej.: demora en la cocina, cliente frecuente" />
      </label>

      <p v-if="error" class="mt-3 alert-error">{{ error }}</p>

      <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <BaseButton variant="ghost" @click="emit('cerrar')">Volver</BaseButton>
        <BaseButton :disabled="guardando" @click="aplicar">{{ guardando ? 'Guardando…' : 'Aplicar descuento' }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
