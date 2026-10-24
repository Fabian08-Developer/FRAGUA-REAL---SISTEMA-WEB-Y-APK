<script setup lang="ts">
// Campo de dinero (22/10/2026): solo acepta números enteros de pesos y los muestra con punto de miles
// («20.000») mientras se escribe. El modelo es un número, o '' si está vacío (como un <input
// type="number">). Las clases (field, field-sm…) y los demás atributos pasan al <input>.
import { computed } from 'vue'
import { agruparMiles, aplicarFormato, atributosDeFormato } from '@/utils/formatos'

defineOptions({ inheritAttrs: false })
const props = withDefaults(defineProps<{ modelValue: number | string | null | undefined; maximo?: number }>(), { maximo: 99_999_999 })
const emit = defineEmits<{ (e: 'update:modelValue', valor: number | ''): void }>()

const mostrado = computed(() => agruparMiles(props.modelValue))
const atributos = atributosDeFormato('pesos')

function alEscribir(evento: Event) {
  if ((evento as InputEvent).isComposing) return
  const digitos = aplicarFormato(evento.target as HTMLInputElement, 'pesos', String(props.maximo).length)
  emit('update:modelValue', digitos === '' ? '' : Math.min(Number(digitos), props.maximo))
}
</script>

<template>
  <input
    type="text"
    autocomplete="off"
    v-bind="{ ...atributos, ...$attrs }"
    :value="mostrado"
    @input="alEscribir"
    @compositionend="alEscribir"
  />
</template>
