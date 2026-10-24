<script setup lang="ts">
// Fase 1, tarea 5 — Disponibilidad del menú (M04): el Mesero y el Administrador marcan un producto
// o un tamaño como agotado y lo vuelven a habilitar cuando hay existencias (RF-M04-016/017,
// HU-M04-009/010). Lo agotado deja de ofrecerse en todos los canales al instante: mesa, QR,
// domicilio y recogida (RF-M04-019); los pedidos ya creados no cambian (RN-M04-08). Un combo con
// un componente agotado se oculta solo (RN-M04-11). Se busca por nombre y se filtra por
// categoría (RF-M04-025, HU-M04-013). Cada cambio queda en la auditoría.
// La misma pantalla sirve en /mesero/menu y /admin/disponibilidad.
import { computed, onMounted, ref } from 'vue'
import { Search, UtensilsCrossed } from 'lucide-vue-next'
import PageHeader from '@/components/common/PageHeader.vue'
import { catalogoService } from '@/services/catalogo.service'
import { mensajeDeError } from '@/services/errors'
import type { Categoria, Producto, Variante } from '@/types/models'

const productos = ref<Producto[]>([])
const categorias = ref<Categoria[]>([])
const cargando = ref(true)
const error = ref('')
const aviso = ref('')
const guardando = ref<string | null>(null)

const busqueda = ref('')
const categoriaId = ref<number | null>(null)
const soloAgotados = ref(false)

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const [{ data: lista }, { data: cats }] = await Promise.all([catalogoService.productos(), catalogoService.categorias()])
    // Solo lo que sigue en el catálogo: lo dado de baja lo maneja el Administrador en Catálogo.
    productos.value = lista.filter((p) => p.activo)
    categorias.value = cats.filter((c) => c.activo)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar el menú.')
  } finally {
    cargando.value = false
  }
}

const sinTildes = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

function variantesActivas(p: Producto): Variante[] {
  return (p.variantes ?? []).filter((v) => v.activa)
}

function tieneAgotado(p: Producto) {
  return !p.disponible || variantesActivas(p).some((v) => v.disponible === false)
}

const visibles = computed(() => {
  const q = sinTildes(busqueda.value.trim())
  return productos.value.filter(
    (p) =>
      (!q || sinTildes(p.nombre).includes(q)) &&
      (categoriaId.value === null || p.categoria_id === categoriaId.value) &&
      (!soloAgotados.value || tieneAgotado(p)),
  )
})

const totalAgotados = computed(() => productos.value.filter(tieneAgotado).length)

async function cambiarProducto(p: Producto) {
  guardando.value = `p${p.id}`
  error.value = ''
  aviso.value = ''
  try {
    const { data } = await catalogoService.toggleDisponible(p.id, !p.disponible)
    p.disponible = data.disponible
    aviso.value = `${p.nombre}: ${p.disponible ? 'disponible de nuevo' : 'marcado como agotado'}.`
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cambiar la disponibilidad.')
  } finally {
    guardando.value = null
  }
}

async function cambiarVariante(p: Producto, v: Variante) {
  guardando.value = `v${v.id}`
  error.value = ''
  aviso.value = ''
  const nuevo = v.disponible === false
  try {
    const { data } = await catalogoService.toggleDisponibleVariante(v.id, nuevo)
    v.disponible = data.disponible
    aviso.value = `${p.nombre} (${v.nombre}): ${nuevo ? 'disponible de nuevo' : 'marcado como agotado'}.`
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cambiar la disponibilidad.')
  } finally {
    guardando.value = null
  }
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

onMounted(cargar)
</script>

<template>
  <div>
    <PageHeader
      eyebrow="MENÚ"
      titulo="Disponibilidad"
      descripcion="Marca lo que se acabó y vuelve a habilitarlo cuando haya. Aplica de inmediato en mesa, QR y domicilio."
    />

    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center">
      <label class="relative block sm:w-72">
        <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
        <input v-formato="'busqueda'" v-model="busqueda" type="search" class="field pl-9" placeholder="Buscar producto" aria-label="Buscar producto" />
      </label>
      <label class="flex items-center gap-2 text-sm text-gray-600">
        <input v-model="soloAgotados" type="checkbox" class="size-[18px] accent-brand-600" />
        Solo agotados
        <span v-if="totalAgotados" class="badge bg-yellow-50 text-yellow-800 ring-yellow-600/20">{{ totalAgotados }}</span>
      </label>
    </div>

    <div class="no-scrollbar mt-3 flex gap-2 overflow-x-auto pb-1">
      <button
        type="button"
        class="shrink-0 rounded-full border px-3.5 py-2 text-xs font-semibold transition"
        :class="categoriaId === null ? 'border-ink bg-ink text-white' : 'border-gray-200 bg-white text-gray-600'"
        @click="categoriaId = null"
      >
        Todas
      </button>
      <button
        v-for="c in categorias"
        :key="c.id"
        type="button"
        class="shrink-0 rounded-full border px-3.5 py-2 text-xs font-semibold transition"
        :class="categoriaId === c.id ? 'border-ink bg-ink text-white' : 'border-gray-200 bg-white text-gray-600'"
        @click="categoriaId = c.id"
      >
        {{ c.nombre }}
      </button>
    </div>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>
    <p v-if="aviso" class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ aviso }}</p>

    <div v-if="cargando" class="loading-state">Cargando el menú…</div>

    <div v-else-if="!visibles.length" class="mt-8 empty-state">
      <UtensilsCrossed :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm text-gray-500">Nada coincide con la búsqueda.</p>
    </div>

    <ul v-else class="mt-5 space-y-2.5">
      <li v-for="p in visibles" :key="p.id" class="card p-3.5 sm:p-4">
        <div class="flex items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="truncate text-[15px] font-semibold" :class="p.disponible ? 'text-ink' : 'text-gray-400 line-through'">
              {{ p.nombre }}
            </p>
            <p class="text-xs text-gray-500">{{ p.categoria?.nombre }} · {{ formatoPrecio(p.precio_base) }}</p>
          </div>
          <button
            type="button"
            role="switch"
            :aria-checked="p.disponible"
            :aria-label="`${p.nombre}: ${p.disponible ? 'disponible' : 'agotado'}`"
            class="flex min-h-11 shrink-0 items-center gap-2 rounded-full px-1 text-xs font-semibold disabled:opacity-50"
            :class="p.disponible ? 'text-emerald-700' : 'text-yellow-800'"
            :disabled="guardando !== null"
            @click="cambiarProducto(p)"
          >
            {{ p.disponible ? 'Disponible' : 'Agotado' }}
            <span class="relative inline-flex h-7 w-12 items-center rounded-full transition" :class="p.disponible ? 'bg-emerald-500' : 'bg-gray-300'">
              <span class="absolute size-5 rounded-full bg-white shadow transition" :class="p.disponible ? 'left-6' : 'left-1'" />
            </span>
          </button>
        </div>

        <ul v-if="variantesActivas(p).length" class="mt-2.5 space-y-1.5 border-t border-gray-100 pt-2.5">
          <li v-for="v in variantesActivas(p)" :key="v.id" class="flex items-center justify-between gap-3 pl-3">
            <span class="text-sm" :class="v.disponible === false ? 'text-gray-400 line-through' : 'text-gray-700'">
              {{ v.nombre }} · {{ formatoPrecio(v.precio) }}
            </span>
            <button
              type="button"
              role="switch"
              :aria-checked="v.disponible !== false"
              :aria-label="`${p.nombre} ${v.nombre}: ${v.disponible === false ? 'agotado' : 'disponible'}`"
              class="flex min-h-10 shrink-0 items-center gap-2 rounded-full px-1 text-xs font-semibold disabled:opacity-50"
              :class="v.disponible === false ? 'text-yellow-800' : 'text-emerald-700'"
              :disabled="guardando !== null || !p.disponible"
              @click="cambiarVariante(p, v)"
            >
              {{ v.disponible === false ? 'Agotado' : 'Disponible' }}
              <span class="relative inline-flex h-6 w-10 items-center rounded-full transition" :class="v.disponible === false ? 'bg-gray-300' : 'bg-emerald-500'">
                <span class="absolute size-4 rounded-full bg-white shadow transition" :class="v.disponible === false ? 'left-1' : 'left-5'" />
              </span>
            </button>
          </li>
          <li v-if="!p.disponible" class="pl-3 text-xs text-gray-500">Con el producto agotado no se vende ningún tamaño.</li>
          <li
            v-else-if="variantesActivas(p).length && !variantesActivas(p).some((v) => v.disponible !== false)"
            class="pl-3 text-xs font-medium text-amber-700"
          >
            Con todos los tamaños agotados, el producto no se ofrece.
          </li>
        </ul>
      </li>
    </ul>

    <p class="mt-6 text-xs text-gray-500">
      Un combo con algún producto agotado se oculta solo y vuelve a aparecer cuando todos sus productos estén disponibles.
    </p>
  </div>
</template>
