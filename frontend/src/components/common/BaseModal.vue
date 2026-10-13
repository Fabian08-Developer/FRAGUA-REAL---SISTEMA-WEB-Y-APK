<script lang="ts">
// Compartido entre todas las instancias: hay vistas con varios modales a la vez (p. ej. el
// detalle de un turno de caja con "Anular pago" encima) — el scroll de la página solo se
// libera cuando ya no queda NINGUNO abierto.
let modalesAbiertos = 0
</script>

<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { X } from 'lucide-vue-next'

const props = defineProps<{ modelValue: boolean; title?: string }>()
const emit = defineEmits<{ (e: 'update:modelValue', value: boolean): void }>()

function cerrar() {
  emit('update:modelValue', false)
}

function alPresionarTecla(evento: KeyboardEvent) {
  if (evento.key === 'Escape') cerrar()
}

// Mientras el modal está abierto: Escape lo cierra y la página de atrás no se desplaza
// (en teléfono, hacer scroll dentro de la hoja arrastraba también el contenido de fondo).
let contado = false
function actualizarBloqueo(abierto: boolean) {
  if (abierto === contado) return
  contado = abierto
  modalesAbiertos += abierto ? 1 : -1
  document.body.style.overflow = modalesAbiertos > 0 ? 'hidden' : ''
  if (abierto) window.addEventListener('keydown', alPresionarTecla)
  else window.removeEventListener('keydown', alPresionarTecla)
}

watch(() => props.modelValue, actualizarBloqueo, { immediate: true })
onBeforeUnmount(() => actualizarBloqueo(false))
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <!-- RESPONSIVO: en teléfono (< sm) es una "hoja inferior" pegada al borde de abajo —
           el pulgar la alcanza sin estirarse y el botón de acción queda en la zona fácil;
           desde sm se vuelve un diálogo centrado de siempre. -->
      <div
        v-if="modelValue"
        class="modal-fondo fixed inset-0 z-50 flex items-end justify-center bg-ink/55 backdrop-blur-[2px] sm:items-center sm:p-4"
        @click.self="cerrar"
      >
        <!-- CORREGIDO: sin max-height/overflow, un modal más alto que el viewport (p. ej. el
             formulario de Compras y Gastos, con 9 campos) quedaba con la parte de abajo
             recortada fuera de pantalla y sin scroll posible — el botón de guardar quedaba
             inalcanzable. El encabezado se queda fijo arriba; solo el contenido (slot)
             hace scroll. dvh (no vh): en móvil vh incluye la barra del navegador y se pasa. -->
        <div
          class="modal-hoja flex max-h-[92dvh] w-full flex-col rounded-t-3xl bg-white shadow-pop sm:max-h-[90dvh] sm:max-w-lg sm:rounded-3xl"
          role="dialog"
          aria-modal="true"
        >
          <div class="mx-auto mt-2.5 h-1 w-10 shrink-0 rounded-full bg-gray-200 sm:hidden" aria-hidden="true" />
          <div class="flex shrink-0 items-center justify-between gap-4 px-5 pb-3 pt-3 sm:px-6 sm:pb-4 sm:pt-6">
            <h2 class="font-display text-xl font-medium tracking-tight text-ink">{{ title }}</h2>
            <button
              type="button"
              class="flex size-9 shrink-0 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-ink"
              aria-label="Cerrar"
              @click="cerrar"
            >
              <X :size="18" />
            </button>
          </div>
          <div class="overflow-y-auto px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] sm:px-6 sm:pb-6">
            <slot />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-active .modal-hoja,
.modal-leave-active .modal-hoja {
  transition:
    transform 0.25s cubic-bezier(0.22, 1, 0.36, 1),
    opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-from .modal-hoja,
.modal-leave-to .modal-hoja {
  transform: translateY(100%);
}
@media (min-width: 640px) {
  .modal-enter-from .modal-hoja,
  .modal-leave-to .modal-hoja {
    transform: translateY(12px) scale(0.97);
    opacity: 0;
  }
}
</style>
