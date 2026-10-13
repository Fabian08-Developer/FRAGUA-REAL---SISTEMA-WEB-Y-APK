<script setup lang="ts">
// M04 — catálogo público (también vía QR de mesa, M02). Calcado de Menu V2
// del Figma (nodo 63:225): tabs de categoría, grilla de productos y un panel
// de personalización a la derecha. Datos reales desde GET /categorias y
// GET /productos (CatalogoController), que ya vienen filtrados a
// activo+disponible para Cliente (RN-M04-08).
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { X } from 'lucide-vue-next'
import { catalogoService } from '@/services/catalogo.service'
import { extrasDe } from '@/utils/extras'
import { useCarritoStore } from '@/stores/carrito'
import { iconoPorNombre as iconoPara } from '@/utils/iconoCategoria'
import { etiquetaPromocion, precioConPromocion } from '@/utils/promociones'
import type { Categoria, Extra, Producto, Variante } from '@/types/models'

const categorias = ref<Categoria[]>([])
const productos = ref<Producto[]>([])
const cargando = ref(true)
const error = ref('')

const categoriaActivaId = ref<number | null>(null)
const productoSeleccionado = ref<Producto | null>(null)
const varianteSeleccionada = ref<Variante | null>(null)
const extrasSeleccionados = ref<Extra[]>([])
const cantidad = ref(1)
const notas = ref('')

// RESPONSIVO: en teléfono/tablet (< lg) el configurador vive como un panel
// deslizable desde abajo ("bottom sheet"), no siempre visible — antes quedaba
// pegado en el flujo normal, debajo de TODA la grilla de productos, así que
// elegir el primer producto de una categoría larga obligaba a bajar hasta el
// final de la página para encontrar el botón "Agregar". Solo se abre cuando
// el cliente toca una tarjeta a propósito (elegirProducto), no con la
// preselección automática de seleccionarPrimeroDeCategoria — si no, cambiar
// de categoría abriría el panel solo. En lg+ sigue siendo el panel fijo de
// siempre (comportamiento sin cambios).
const mostrarConfiguradorMovil = ref(false)

const carrito = useCarritoStore()
const route = useRoute()

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const [resCategorias, resProductos] = await Promise.all([
      catalogoService.categorias(),
      catalogoService.productos(),
    ])
    categorias.value = resCategorias.data
    productos.value = resProductos.data

    // Permite llegar con una categoría preseleccionada, p. ej. desde los
    // accesos rápidos del Home (/menu?categoria=hamburguesas). Coincidencia
    // parcial e insensible a mayúsculas; si no hay match, cae a la primera.
    const categoriaQuery = typeof route.query.categoria === 'string' ? route.query.categoria.toLowerCase() : ''
    const categoriaPreseleccionada = categoriaQuery
      ? categorias.value.find((c) => c.nombre.toLowerCase().includes(categoriaQuery))
      : null
    categoriaActivaId.value = categoriaPreseleccionada?.id ?? categorias.value[0]?.id ?? null
    seleccionarPrimeroDeCategoria()
  } catch {
    error.value = 'No se pudo cargar el menú. Intenta de nuevo.'
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)

const productosDeCategoria = computed(() =>
  productos.value.filter((p) => p.categoria_id === categoriaActivaId.value),
)

function seleccionarCategoria(id: number) {
  categoriaActivaId.value = id
  mostrarConfiguradorMovil.value = false
  seleccionarPrimeroDeCategoria()
}

function seleccionarPrimeroDeCategoria() {
  const primero = productosDeCategoria.value[0] ?? null
  seleccionarProducto(primero)
}

function seleccionarProducto(producto: Producto | null) {
  productoSeleccionado.value = producto
  varianteSeleccionada.value = producto?.variantes?.find((v) => v.activa) ?? null
  extrasSeleccionados.value = []
  cantidad.value = 1
  notas.value = ''
}

// Tocar una tarjeta a propósito (a diferencia de la preselección automática
// de arriba): además de elegir el producto, abre el panel en móvil/tablet.
function elegirProducto(producto: Producto) {
  seleccionarProducto(producto)
  mostrarConfiguradorMovil.value = true
}

function cerrarConfiguradorMovil() {
  mostrarConfiguradorMovil.value = false
}

function alternarExtra(extra: Extra) {
  const idx = extrasSeleccionados.value.findIndex((e) => e.id === extra.id)
  if (idx >= 0) {
    extrasSeleccionados.value.splice(idx, 1)
  } else {
    extrasSeleccionados.value.push(extra)
  }
}

const precioSeleccion = computed(() => {
  if (!productoSeleccionado.value) return 0
  return carrito.precioUnitario({
    producto: productoSeleccionado.value,
    variante: varianteSeleccionada.value,
    extras: extrasSeleccionados.value,
  })
})

function agregarAlPedido() {
  if (!productoSeleccionado.value) return
  carrito.agregar({
    producto: productoSeleccionado.value,
    variante: varianteSeleccionada.value,
    extras: [...extrasSeleccionados.value],
    cantidad: cantidad.value,
    notas: notas.value || undefined,
  })
  cantidad.value = 1
  extrasSeleccionados.value = []
  mostrarConfiguradorMovil.value = false
}

function formatoPrecio(valor: number) {
  return valor.toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 pb-32 pt-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">NUESTRA CARTA</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink sm:text-5xl">Tu próximo antojo.</h1>
    <p class="mt-3 text-[15px] text-[#6c655c]">Elige tu favorito, personalízalo y agrégalo a tu pedido.</p>

    <p v-if="error" class="mt-6 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>
    <p v-else-if="cargando" class="mt-10 text-sm text-[#6c655c]">Cargando el menú…</p>

    <template v-else>
      <nav class="mt-8 flex gap-2 overflow-x-auto border-b border-[#e2dbcf] pb-3">
        <button
          v-for="categoria in categorias"
          :key="categoria.id"
          type="button"
          class="flex shrink-0 items-center gap-2 rounded-full border px-5 py-2.5 text-sm font-medium transition"
          :class="
            categoria.id === categoriaActivaId
              ? 'border-ink bg-ink text-white'
              : 'border-[#dfd8cd] bg-white text-[#59534c] hover:border-brand-500'
          "
          @click="seleccionarCategoria(categoria.id)"
        >
          <component :is="iconoPara(categoria.nombre)" :size="15" />
          {{ categoria.nombre }}
        </button>
      </nav>

      <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]">
        <section>
          <div class="mb-4 flex items-baseline justify-between">
            <h2 class="font-display text-2xl text-ink">
              {{ categorias.find((c) => c.id === categoriaActivaId)?.nombre }}
            </h2>
            <span class="text-xs text-[#797269]">{{ productosDeCategoria.length }} productos</span>
          </div>

          <p v-if="productosDeCategoria.length === 0" class="text-sm text-[#6c655c]">
            No hay productos disponibles en esta categoría por ahora.
          </p>

          <div class="grid gap-4 sm:grid-cols-2">
            <button
              v-for="producto in productosDeCategoria"
              :key="producto.id"
              type="button"
              class="rounded-2xl border bg-white p-4 text-left transition"
              :class="
                productoSeleccionado?.id === producto.id
                  ? 'border-brand-500 shadow-[0_0_0_2px_rgba(214,164,58,0.14)]'
                  : 'border-[#e2dbcf] hover:border-brand-300'
              "
              @click="elegirProducto(producto)"
            >
              <div
                class="mb-3 flex aspect-[4/3] items-center justify-center rounded-xl bg-gradient-to-br from-[#efe8db] to-[#e0d6c2] text-brand-700/60"
              >
                <component :is="iconoPara(producto.nombre)" :size="36" :stroke-width="1.5" />
              </div>
              <p class="font-display text-lg text-ink">
                {{ producto.nombre }}
                <!-- Fase 2, tarea 1 (RF-M04-035): promoción vigente del producto o su categoría. -->
                <span v-if="etiquetaPromocion(producto)" class="ml-1 align-middle rounded-full bg-emerald-50 px-2 py-0.5 font-sans text-[11px] font-semibold text-emerald-700">{{ etiquetaPromocion(producto) }}</span>
              </p>
              <p v-if="producto.descripcion" class="mt-1 line-clamp-2 text-xs text-[#6f685f]">
                {{ producto.descripcion }}
              </p>
              <div class="mt-3 flex items-baseline justify-between">
                <div>
                  <p class="text-[10px] text-[#8a8379]">Desde</p>
                  <p class="text-base text-ink">
                    <template v-if="etiquetaPromocion(producto)">
                      <span class="mr-1 text-xs text-[#a59d90] line-through">{{ formatoPrecio(Number(producto.precio_base)) }}</span>{{ formatoPrecio(precioConPromocion(producto, Number(producto.precio_base))) }}
                    </template>
                    <template v-else>{{ formatoPrecio(Number(producto.precio_base)) }}</template>
                  </p>
                </div>
                <span class="border-b border-brand-700 pb-0.5 text-xs font-medium text-brand-700">Elegir</span>
              </div>
            </button>
          </div>
        </section>

        <!-- Fondo oscuro del panel deslizable (solo móvil/tablet, solo si está abierto) -->
        <Transition
          enter-active-class="transition-opacity duration-200"
          leave-active-class="transition-opacity duration-150"
          enter-from-class="opacity-0"
          leave-to-class="opacity-0"
        >
          <div
            v-if="productoSeleccionado && mostrarConfiguradorMovil"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            @click="cerrarConfiguradorMovil"
          />
        </Transition>

        <aside
          v-if="productoSeleccionado"
          class="fixed inset-x-0 bottom-0 z-40 flex max-h-[85vh] flex-col rounded-t-3xl border-t border-[#ded7cc] bg-[#fffdf9] shadow-[0_-18px_40px_rgba(53,42,20,0.18)] transition-transform duration-300 ease-out lg:sticky lg:top-24 lg:inset-x-auto lg:bottom-auto lg:z-auto lg:h-fit lg:max-h-none lg:translate-y-0 lg:rounded-2xl lg:border lg:shadow-[0_18px_22.5px_rgba(53,42,20,0.08)]"
          :class="mostrarConfiguradorMovil ? 'translate-y-0' : 'translate-y-full'"
        >
          <!-- Encabezado: fijo (no se desplaza con el contenido), para que el botón de
               cerrar y el drag handle siempre queden a la mano en móvil. -->
          <div class="shrink-0 px-5 pt-5 lg:p-5 lg:pb-0">
            <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-[#e5ded2] lg:hidden" aria-hidden="true" />
            <div class="flex items-center justify-between gap-3">
              <span class="text-[10px] font-medium tracking-[1.8px] text-brand-700">TU ELECCIÓN</span>
              <span class="hidden text-[11px] text-[#7d756b] lg:inline">Personaliza aquí</span>
              <button
                type="button"
                class="flex size-8 shrink-0 items-center justify-center rounded-full text-[#7d756b] hover:bg-black/5 lg:hidden"
                aria-label="Cerrar"
                @click="cerrarConfiguradorMovil"
              >
                <X :size="18" />
              </button>
            </div>
          </div>

          <!-- Cuerpo: lo único que se desplaza en móvil cuando el producto tiene
               muchas variantes/extras — el botón "Agregar" de abajo nunca se mueve. -->
          <div class="min-h-0 flex-1 overflow-y-auto px-5 lg:overflow-visible">
            <div
              class="flex aspect-[4/3] items-center justify-center rounded-xl bg-gradient-to-br from-[#efe8db] to-[#e0d6c2] text-brand-700/60"
            >
              <component :is="iconoPara(productoSeleccionado.nombre)" :size="56" :stroke-width="1.5" />
            </div>
            <p class="mt-4 font-display text-2xl text-ink">{{ productoSeleccionado.nombre }}</p>
            <p v-if="productoSeleccionado.descripcion" class="mt-2 text-[13px] leading-relaxed text-[#696158]">
              {{ productoSeleccionado.descripcion }}
            </p>

            <template v-if="productoSeleccionado.variantes?.length">
              <p class="mt-5 text-xs text-ink">Elige la presentación</p>
              <div class="mt-2 space-y-2">
                <label
                  v-for="variante in (productoSeleccionado.variantes ?? []).filter((v) => v.activa)"
                  :key="variante.id"
                  class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-xs"
                  :class="
                    varianteSeleccionada?.id === variante.id
                      ? 'border-brand-500 bg-[#fff7e5]'
                      : 'border-[#dfd8cd] bg-white'
                  "
                >
                  <span class="flex items-center gap-3">
                    <input
                      type="radio"
                      :name="'variante-' + productoSeleccionado.id"
                      class="accent-brand-600"
                      :checked="varianteSeleccionada?.id === variante.id"
                      @change="varianteSeleccionada = variante"
                    />
                    {{ variante.nombre }}
                  </span>
                  <span class="text-brand-800">{{ formatoPrecio(Number(variante.precio)) }}</span>
                </label>
              </div>
            </template>

            <template v-if="extrasDe(productoSeleccionado).length">
              <p class="mt-5 text-xs text-ink">Extras</p>
              <div class="mt-2 space-y-2">
                <label
                  v-for="extra in extrasDe(productoSeleccionado)"
                  :key="extra.id"
                  class="flex items-center justify-between gap-3 rounded-xl border border-[#dfd8cd] bg-white px-4 py-3 text-xs"
                >
                  <span class="flex items-center gap-3">
                    <input
                      type="checkbox"
                      class="accent-brand-600"
                      :checked="extrasSeleccionados.some((e) => e.id === extra.id)"
                      @change="alternarExtra(extra)"
                    />
                    {{ extra.nombre }}
                  </span>
                  <span class="text-brand-800">+{{ formatoPrecio(Number(extra.precio)) }}</span>
                </label>
              </div>
            </template>

            <label class="mt-5 block pb-5 text-xs text-ink">
              ¿Quieres quitar algún ingrediente?
              <input
                v-model="notas"
                type="text"
                placeholder="Indicación opcional para cocina"
                class="mt-2 w-full rounded-lg border border-[#dfd8cd] px-3 py-2 text-xs focus:border-brand-500 focus:outline-none"
              />
            </label>
          </div>

          <!-- Pie: fijo, siempre visible — nunca hace falta bajar para encontrar "Agregar". -->
          <div class="flex shrink-0 items-center gap-3 border-t border-[#e5ded2] px-5 py-5">
            <div class="flex items-center gap-3 rounded-xl border border-[#ddd5c9] px-3 py-3">
              <button
                type="button"
                class="text-lg text-ink disabled:opacity-30"
                :disabled="cantidad <= 1"
                @click="cantidad--"
              >
                −
              </button>
              <span class="w-4 text-center text-sm">{{ cantidad }}</span>
              <button type="button" class="text-lg text-ink" @click="cantidad++">+</button>
            </div>
            <button
              type="button"
              class="flex flex-1 items-center justify-between rounded-xl bg-brand-500 px-4 py-3 text-xs font-medium text-ink transition hover:bg-brand-400"
              @click="agregarAlPedido"
            >
              <span>Agregar a mi pedido</span>
              <span>{{ formatoPrecio(precioSeleccion * cantidad) }}</span>
            </button>
          </div>
        </aside>
      </div>
    </template>

    <div
      v-if="carrito.lineas.length > 0"
      class="fixed inset-x-0 bottom-0 z-20 border-t border-white/10 bg-ink px-4 py-4 sm:px-6"
    >
      <div class="mx-auto flex max-w-6xl items-center justify-between gap-4">
        <div>
          <p class="font-display text-lg text-white">Mi pedido · {{ carrito.totalProductos }} productos</p>
          <p class="text-[11px] text-[#bfb7aa]">Revisa tu pedido antes de continuar</p>
        </div>
        <div class="flex items-center gap-4">
          <span class="hidden text-lg text-white sm:inline">{{ formatoPrecio(carrito.total) }}</span>
          <router-link
            to="/carrito"
            class="rounded-xl bg-[#393630] px-5 py-3 text-sm font-medium text-brand-500 transition hover:bg-[#454239]"
          >
            Ver mi pedido →
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>
