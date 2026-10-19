<script setup lang="ts">
// Mejora de diseño (16/10/2026): en el Menú y en el pedido por QR, la barra fija de abajo («Mi pedido
// · 10 productos») ocupaba todo el ancho y tapaba la última tarjeta. Ahora es un botón redondo en la
// esquina con la cantidad, que da un pequeño salto cuando se agrega algo, y un aviso breve arriba
// («Agregado: Jugo en Agua · Ver pedido») para confirmar lo que hacía la barra.
//  - `oculto`: mientras el panel o la ventana del producto está abierto, para no taparle el botón.
//  - `soloMovil`: en escritorio no se muestra (el encabezado ya tiene «Mi pedido»).
import { onBeforeUnmount, ref, watch } from 'vue'
import { ShoppingBag } from 'lucide-vue-next'

const props = defineProps<{
  cantidad: number
  // Cada aviso nuevo trae un id distinto (por ejemplo Date.now()) para volver a mostrarse.
  aviso?: { id: number; texto: string } | null
  oculto?: boolean
  soloMovil?: boolean
}>()
const emit = defineEmits<{ (e: 'abrir'): void }>()

const saltando = ref(false)
const avisoVisible = ref<string | null>(null)
let temporizadorSalto: ReturnType<typeof setTimeout> | undefined
let temporizadorAviso: ReturnType<typeof setTimeout> | undefined

watch(
  () => props.cantidad,
  (nueva, anterior) => {
    if (nueva <= (anterior ?? 0)) return
    saltando.value = false
    clearTimeout(temporizadorSalto)
    // Una espera mínima para que la animación vuelva a empezar si se agrega dos veces seguidas.
    temporizadorSalto = setTimeout(() => {
      saltando.value = true
      temporizadorSalto = setTimeout(() => (saltando.value = false), 450)
    }, 30)
  },
)

watch(
  () => props.aviso?.id,
  () => {
    if (!props.aviso) return
    avisoVisible.value = props.aviso.texto
    clearTimeout(temporizadorAviso)
    temporizadorAviso = setTimeout(() => (avisoVisible.value = null), 2400)
  },
)

onBeforeUnmount(() => {
  clearTimeout(temporizadorSalto)
  clearTimeout(temporizadorAviso)
})

function abrir() {
  avisoVisible.value = null
  emit('abrir')
}
</script>

<template>
  <!-- Aviso breve al agregar -->
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="-translate-y-3 opacity-0"
    leave-active-class="transition duration-150 ease-in"
    leave-to-class="-translate-y-3 opacity-0"
  >
    <div
      v-if="avisoVisible"
      class="fixed inset-x-4 top-20 z-30 mx-auto flex max-w-sm items-center justify-between gap-3 rounded-2xl bg-ink px-4 py-3 text-sm text-white shadow-[0_12px_30px_rgba(23,21,16,0.35)]"
      :class="soloMovil ? 'lg:hidden' : ''"
      role="status"
      aria-live="polite"
    >
      <span class="min-w-0 truncate">{{ avisoVisible }}</span>
      <button type="button" class="shrink-0 font-medium text-brand-400 hover:text-brand-300" @click="abrir">Ver pedido</button>
    </div>
  </Transition>

  <!-- Botón flotante -->
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="scale-75 opacity-0"
    leave-active-class="transition duration-150 ease-in"
    leave-to-class="scale-75 opacity-0"
  >
    <button
      v-if="cantidad > 0 && !oculto"
      type="button"
      class="fixed bottom-[max(1.25rem,env(safe-area-inset-bottom))] right-4 z-20 flex size-14 items-center justify-center rounded-full bg-ink text-brand-400 shadow-[0_12px_28px_rgba(23,21,16,0.35)] transition hover:bg-ink-700 sm:right-6"
      :class="[soloMovil ? 'lg:hidden' : '', saltando ? 'animate-salto' : '']"
      :aria-label="`Ver mi pedido: ${cantidad} ${cantidad === 1 ? 'producto' : 'productos'}`"
      @click="abrir"
    >
      <ShoppingBag :size="24" />
      <span
        class="absolute -right-1 -top-1 flex h-6 min-w-6 items-center justify-center rounded-full bg-brand-500 px-1.5 text-xs font-semibold text-ink ring-2 ring-[#f8f5ee]"
      >
        {{ cantidad > 99 ? '99+' : cantidad }}
      </span>
    </button>
  </Transition>
</template>

<style scoped>
@keyframes salto {
  0%,
  100% {
    transform: scale(1);
  }
  40% {
    transform: scale(1.18);
  }
  70% {
    transform: scale(0.94);
  }
}
.animate-salto {
  animation: salto 0.45s ease-out;
}
@media (prefers-reduced-motion: reduce) {
  .animate-salto {
    animation: none;
  }
}
</style>
