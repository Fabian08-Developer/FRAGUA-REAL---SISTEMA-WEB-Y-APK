<script setup lang="ts">
// Mejora (19/10/2026): la marca del negocio en encabezados, menús y pie de página. Si el
// Administrador subió un logo en Configuración se muestra ese; si no, el círculo «FR» de siempre.
import { computed, onMounted, ref, watch } from 'vue'
import { useNegocioStore } from '@/stores/negocio'

const props = withDefaults(
  defineProps<{
    /** Clases del tamaño (por ejemplo «size-11»). */
    tamano?: string
    /** Clases del texto «FR» cuando no hay logo. */
    texto?: string
    /** Fondo del círculo «FR» (oscuro sobre fondo claro, translúcido sobre fondo oscuro). */
    fondo?: string
    /** Color del texto «FR». */
    color?: string
  }>(),
  { tamano: 'size-11', texto: 'text-lg', fondo: 'bg-ink', color: 'text-brand-500' },
)

const negocio = useNegocioStore()
onMounted(() => negocio.cargar())

const logo = computed(() => negocio.datos.logo_url)
const fallo = ref(false)
watch(logo, () => (fallo.value = false))
</script>

<template>
  <img
    v-if="logo && !fallo"
    :src="logo"
    :alt="negocio.datos.nombre"
    class="shrink-0 rounded-full object-contain"
    :class="props.tamano"
    @error="fallo = true"
  />
  <span
    v-else
    class="flex shrink-0 items-center justify-center rounded-full"
    :class="[props.tamano, props.fondo, props.color]"
    aria-hidden="true"
  >
    <span class="font-display" :class="props.texto">FR</span>
  </span>
</template>
