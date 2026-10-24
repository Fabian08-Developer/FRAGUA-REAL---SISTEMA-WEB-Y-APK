<script setup lang="ts">
import { computed, ref, type Component } from 'vue'
import { Eye, EyeOff } from 'lucide-vue-next'
import { agruparMiles, aplicarFormato, atributosDeFormato, type Formato } from '@/utils/formatos'

const props = withDefaults(
  defineProps<{
    modelValue: string
    label?: string
    type?: string
    placeholder?: string
    error?: string
    disabled?: boolean
    /** Ícono (lucide-vue-next) que se muestra a la izquierda del campo. */
    icon?: Component
    /** Pista de autocompletado del navegador (p. ej. "username", "email", "current-password"). */
    autocomplete?: string
    /** Formato del campo (22/10/2026): qué se puede escribir y su largo; `pesos` muestra los miles. */
    formato?: Formato
    maxlength?: number
  }>(),
  { disabled: false },
)

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

// type="password" trae su propio botón de mostrar/ocultar (ojo) en vez de
// depender de la UI nativa del navegador, para que se vea igual en todos.
const esPassword = computed(() => props.type === 'password')
const mostrarPassword = ref(false)
const tipoReal = computed(() => (esPassword.value && mostrarPassword.value ? 'text' : (props.type ?? 'text')))

// Con formato: lo que no corresponde se descarta al escribir o pegar, y el modelo recibe el valor limpio
// (en pesos, solo los dígitos: la pantalla muestra «20.000» y el modelo es «20000»).
const valorMostrado = computed(() => (props.formato === 'pesos' ? agruparMiles(props.modelValue) : props.modelValue))
const atributos = computed(() =>
  props.formato ? atributosDeFormato(props.formato) : props.maxlength ? { maxlength: String(props.maxlength) } : {},
)

function alEscribir(evento: Event) {
  const campo = evento.target as HTMLInputElement
  if ((evento as InputEvent).isComposing) return
  emit('update:modelValue', props.formato ? aplicarFormato(campo, props.formato, props.maxlength) : campo.value)
}
</script>

<template>
  <label class="block">
    <span v-if="label" class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">
      {{ label }}
    </span>
    <span class="relative block">
      <span
        v-if="icon"
        class="pointer-events-none absolute inset-y-0 left-0 flex w-11 items-center justify-center text-[#a39c8f]"
        aria-hidden="true"
      >
        <component :is="icon" :size="17" />
      </span>
      <input
        :type="tipoReal"
        :value="valorMostrado"
        v-bind="atributos"
        :placeholder="placeholder"
        :disabled="disabled"
        :autocomplete="autocomplete"
        class="w-full rounded-xl border border-[#e3ded4] bg-[#fbfaf7] py-3 pr-4 text-base text-ink placeholder:text-[#a39c8f] transition focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-brand-500/15 disabled:cursor-not-allowed disabled:bg-[#f1efe9] disabled:text-[#8a8379] sm:text-sm"
        :class="[icon ? 'pl-11' : 'pl-4', esPassword ? 'pr-11' : 'pr-4', { '!border-red-400 !ring-red-100': error }]"
        @input="alEscribir"
        @compositionend="alEscribir"
      />
      <button
        v-if="esPassword"
        type="button"
        class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-[#a39c8f] transition hover:text-ink"
        :aria-label="mostrarPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
        @click="mostrarPassword = !mostrarPassword"
      >
        <EyeOff v-if="mostrarPassword" :size="17" />
        <Eye v-else :size="17" />
      </button>
    </span>
    <span v-if="error" class="mt-1.5 block text-xs text-red-600">{{ error }}</span>
  </label>
</template>
