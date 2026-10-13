<script setup lang="ts">
// M02 — armar el pedido antes de confirmar. Lee directo del store de
// carrito (variante + extras + notas por línea, ver stores/carrito.ts);
// "Continuar" pasa a Checkout (M02/M07), que sigue pendiente de construir.
import { Minus, Plus, Trash2 } from 'lucide-vue-next'
import mascota from '@/assets/img/mascota-fragua-real.png'
import { computed } from 'vue'
import { useCarritoStore } from '@/stores/carrito'
import { componentesCombo } from '@/utils/combos'
import { useCotizacion } from '@/composables/useCotizacion'

const carrito = useCarritoStore()

// Fase 2, tarea 1 (RF-M04-035): promociones vigentes del carrito (el domicilio se suma en el checkout).
const { cotizacion } = useCotizacion(() => 'recogida', () => carrito.aLineasPedido())
const promociones = computed(() => cotizacion.value?.promociones ?? [])
const totalConPromociones = computed(() => carrito.total - (cotizacion.value?.descuento ?? 0))

function formatoPrecio(valor: number) {
  return valor.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}
</script>

<template>
  <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">MI PEDIDO</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink">Tu carrito</h1>

    <div v-if="carrito.lineas.length === 0" class="mt-16 text-center">
      <img
        :src="mascota"
        alt="Mascota de Fragua Real Burger invitando a pedir"
        class="mx-auto w-44 drop-shadow-[0_20px_30px_rgba(0,0,0,0.12)]"
      />
      <p class="mt-6 font-display text-xl text-ink">Aún no has agregado nada</p>
      <p class="mt-2 text-sm text-[#6c655c]">Explora el menú y arma tu próximo antojo.</p>
      <router-link
        to="/menu"
        class="mt-6 inline-flex items-center justify-center rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
      >
        Ver el menú
      </router-link>
    </div>

    <template v-else>
      <ul class="mt-8 space-y-4">
        <li
          v-for="linea in carrito.lineas"
          :key="linea.id"
          class="flex items-start gap-4 rounded-2xl border border-[#e2dbcf] bg-white p-4"
        >
          <div class="min-w-0 flex-1">
            <p class="font-display text-lg text-ink">{{ carrito.nombreLinea(linea) }}</p>
            <p v-if="linea.combo && componentesCombo(linea.combo)" class="mt-0.5 text-xs text-[#6c655c]">
              Incluye {{ componentesCombo(linea.combo) }}
            </p>
            <p v-if="linea.variante" class="mt-0.5 text-xs text-[#6c655c]">{{ linea.variante.nombre }}</p>
            <p v-if="linea.extras.length" class="mt-0.5 text-xs text-[#6c655c]">
              + {{ linea.extras.map((e) => e.nombre).join(', ') }}
            </p>
            <p v-if="linea.notas" class="mt-0.5 text-xs italic text-[#8a8379]">"{{ linea.notas }}"</p>

            <div class="mt-3 flex items-center gap-3">
              <div class="flex items-center gap-3 rounded-lg border border-[#ddd5c9] px-2 py-1">
                <button
                  type="button"
                  class="text-ink disabled:opacity-30"
                  :disabled="linea.cantidad <= 1"
                  @click="carrito.actualizarCantidad(linea.id, linea.cantidad - 1)"
                >
                  <Minus :size="14" />
                </button>
                <span class="w-4 text-center text-sm">{{ linea.cantidad }}</span>
                <button
                  type="button"
                  class="text-ink"
                  @click="carrito.actualizarCantidad(linea.id, linea.cantidad + 1)"
                >
                  <Plus :size="14" />
                </button>
              </div>
              <button
                type="button"
                class="flex items-center gap-1 text-xs text-[#a35b4a] hover:text-red-600"
                @click="carrito.quitar(linea.id)"
              >
                <Trash2 :size="14" />
                Quitar
              </button>
            </div>
          </div>

          <p class="shrink-0 text-base text-ink">
            {{ formatoPrecio(carrito.precioUnitario(linea) * linea.cantidad) }}
          </p>
        </li>
      </ul>

      <div class="mt-8 border-t border-[#e2dbcf] pt-6">
        <div v-for="(p, i) in promociones" :key="i" class="mb-2 flex justify-between gap-3 text-sm text-emerald-700">
          <span>{{ p.nombre }}</span>
          <span class="shrink-0">−{{ formatoPrecio(p.monto) }}</span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-sm text-[#6c655c]">Total</span>
          <span class="font-display text-2xl text-ink">{{ formatoPrecio(totalConPromociones) }}</span>
        </div>
      </div>

      <router-link
        to="/checkout"
        class="mt-6 flex w-full items-center justify-center rounded-xl bg-brand-500 px-6 py-3.5 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
      >
        Continuar
      </router-link>
    </template>
  </div>
</template>
