<script setup lang="ts">
// M02 — Pedidos del Mesero: listado de los 3 canales (Local/Domicilio/Recogida),
// tomar pedido Local desde el piso, confirmar, marcar preparando, marcar cada línea
// preparada y cancelar (RN-PED-10, motivo obligatorio). El cobro (M06) es la siguiente
// pantalla del cronograma (Vista Mesero > Caja) — no vive aquí.
import { computed, onMounted, reactive, ref } from 'vue'
import { Check, ChevronDown, Minus, Plus, ShoppingBasket, X } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import { pedidoService, type LineaPedidoPayload } from '@/services/pedido.service'
import { catalogoService } from '@/services/catalogo.service'
import { mesaService } from '@/services/mesa.service'
import { mensajeDeError } from '@/services/errors'
import type { Mesa, Pedido, Producto } from '@/types/models'

const pedidos = ref<Pedido[]>([])
const cargando = ref(true)
const error = ref('')
const abierto = ref<number | null>(null)
const procesando = ref<number | null>(null)

const filtroEstado = ref<'activos' | 'finalizados' | 'cancelados'>('activos')
const filtroTipo = ref<'todos' | Pedido['tipo']>('todos')

const OPCIONES_ESTADO: { v: typeof filtroEstado.value; t: string }[] = [
  { v: 'activos', t: 'Activos' },
  { v: 'finalizados', t: 'Finalizados' },
  { v: 'cancelados', t: 'Cancelados' },
]

const OPCIONES_TIPO: { v: typeof filtroTipo.value; t: string }[] = [
  { v: 'todos', t: 'Todos' },
  { v: 'local', t: 'Local' },
  { v: 'domicilio', t: 'Domicilio' },
  { v: 'recogida', t: 'Recogida' },
]

const ESTADOS: Record<Pedido['estado'], { texto: string; clase: string }> = {
  pendiente: { texto: 'Pendiente', clase: 'bg-amber-100 text-amber-800' },
  confirmado: { texto: 'Confirmado', clase: 'bg-blue-100 text-blue-800' },
  preparando: { texto: 'Preparando', clase: 'bg-orange-100 text-orange-800' },
  finalizado: { texto: 'Finalizado', clase: 'bg-green-100 text-green-800' },
  cancelado: { texto: 'Cancelado', clase: 'bg-red-100 text-red-800' },
}

const TIPOS: Record<Pedido['tipo'], string> = {
  local: 'Local',
  domicilio: 'Domicilio',
  recogida: 'Recogida',
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function formatoHora(valor: string) {
  return new Date(valor).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' })
}

function precioLinea(detalle: NonNullable<Pedido['detalles']>[number]) {
  const extras = (detalle.extras ?? []).reduce((acc, e) => acc + Number(e.pivot.precio_congelado), 0)
  return (Number(detalle.precio_unitario_congelado) + extras) * detalle.cantidad
}

const pedidosFiltrados = computed(() =>
  pedidos.value.filter((p) => {
    const pasaEstado =
      filtroEstado.value === 'activos'
        ? (['pendiente', 'confirmado', 'preparando'] as Pedido['estado'][]).includes(p.estado)
        : filtroEstado.value === 'finalizados'
          ? p.estado === 'finalizado'
          : p.estado === 'cancelado'
    const pasaTipo = filtroTipo.value === 'todos' || p.tipo === filtroTipo.value
    return pasaEstado && pasaTipo
  }),
)

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await pedidoService.index()
    pedidos.value = data.data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar los pedidos.')
  } finally {
    cargando.value = false
  }
}

function alternar(pedido: Pedido) {
  abierto.value = abierto.value === pedido.id ? null : pedido.id
}

function reemplazarPedido(actualizado: Pedido) {
  const i = pedidos.value.findIndex((p) => p.id === actualizado.id)
  if (i !== -1) pedidos.value[i] = { ...pedidos.value[i], ...actualizado }
}

async function confirmar(pedido: Pedido) {
  procesando.value = pedido.id
  error.value = ''
  try {
    const { data } = await pedidoService.confirmar(pedido.id)
    reemplazarPedido(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos confirmar el pedido.')
  } finally {
    procesando.value = null
  }
}

async function marcarPreparando(pedido: Pedido) {
  procesando.value = pedido.id
  error.value = ''
  try {
    const { data } = await pedidoService.marcarPreparando(pedido.id)
    reemplazarPedido(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos pasar el pedido a preparando.')
  } finally {
    procesando.value = null
  }
}

async function marcarLineaPreparada(pedido: Pedido, detalleId: number) {
  try {
    await pedidoService.marcarLineaPreparada(pedido.id, detalleId)
    const detalle = pedido.detalles?.find((d) => d.id === detalleId)
    if (detalle) detalle.preparado = true
  } catch {
    error.value = 'No pudimos marcar la línea como preparada.'
  }
}

// ---- Cancelar (motivo obligatorio — RN-PED-10, CancelarPedidoRequest) ----
const pedidoACancelar = ref<Pedido | null>(null)
const motivoCancelar = ref('')
const errorCancelar = ref('')
const cancelando = ref(false)

function abrirCancelar(pedido: Pedido) {
  pedidoACancelar.value = pedido
  motivoCancelar.value = ''
  errorCancelar.value = ''
}

async function confirmarCancelar() {
  if (!pedidoACancelar.value) return
  if (!motivoCancelar.value.trim()) {
    errorCancelar.value = 'Escribe el motivo de la cancelación.'
    return
  }
  cancelando.value = true
  try {
    const { data } = await pedidoService.cancelar(pedidoACancelar.value.id, motivoCancelar.value.trim())
    reemplazarPedido(data)
    pedidoACancelar.value = null
  } catch (e) {
    errorCancelar.value = mensajeDeError(e, 'No pudimos cancelar el pedido.')
  } finally {
    cancelando.value = false
  }
}

// ---- Nuevo pedido Local (desde el piso) ----
const mesas = ref<Mesa[]>([])
const productos = ref<Producto[]>([])
const mostrarNuevo = ref(false)
const cargandoCatalogo = ref(false)
const creando = ref(false)
const errorNuevo = ref('')

interface LineaCarrito {
  key: string
  producto: Producto
  variante_id: number | null
  precioUnitario: number
  extras_ids: number[]
  cantidad: number
}

const nuevoPedido = reactive({
  mesa_id: '' as number | '',
  lineas: [] as LineaCarrito[],
})

// RN-M04-08/RF-M04-018: solo se ofrece lo activo y disponible — Producto.puedeAgregarseAPedido()
// en el backend rechazaría la línea igual, pero es mejor no mostrárselo al Mesero.
const productosDisponibles = computed(() => productos.value.filter((p) => p.activo && p.disponible))

function precioExtrasLinea(linea: LineaCarrito) {
  return linea.extras_ids.reduce((acc, id) => {
    const extra = linea.producto.extras_especificos?.find((e) => e.id === id)
    return acc + (extra ? Number(extra.precio) : 0)
  }, 0)
}

const totalNuevoPedido = computed(() =>
  nuevoPedido.lineas.reduce((acc, l) => acc + (l.precioUnitario + precioExtrasLinea(l)) * l.cantidad, 0),
)

async function abrirNuevoPedido() {
  mostrarNuevo.value = true
  errorNuevo.value = ''
  nuevoPedido.mesa_id = ''
  nuevoPedido.lineas = []
  if (mesas.value.length && productos.value.length) return

  cargandoCatalogo.value = true
  try {
    const [{ data: listaMesas }, { data: listaProductos }] = await Promise.all([
      mesaService.index(),
      catalogoService.productos(),
    ])
    mesas.value = listaMesas
    productos.value = listaProductos
  } catch {
    errorNuevo.value = 'No pudimos cargar el catálogo. Cierra e intenta de nuevo.'
  } finally {
    cargandoCatalogo.value = false
  }
}

function agregarProducto(producto: Producto) {
  const primeraVariante = producto.variantes?.[0]
  nuevoPedido.lineas.push({
    key: `${producto.id}-${Date.now()}`,
    producto,
    variante_id: primeraVariante ? primeraVariante.id : null,
    precioUnitario: primeraVariante ? Number(primeraVariante.precio) : Number(producto.precio_base),
    extras_ids: [],
    cantidad: 1,
  })
}

function actualizarVariante(linea: LineaCarrito, varianteId: number) {
  const variante = linea.producto.variantes?.find((v) => v.id === varianteId)
  linea.variante_id = varianteId
  linea.precioUnitario = variante ? Number(variante.precio) : Number(linea.producto.precio_base)
}

function alternarExtra(linea: LineaCarrito, extraId: number) {
  const i = linea.extras_ids.indexOf(extraId)
  if (i === -1) linea.extras_ids.push(extraId)
  else linea.extras_ids.splice(i, 1)
}

function quitarLinea(linea: LineaCarrito) {
  nuevoPedido.lineas = nuevoPedido.lineas.filter((l) => l.key !== linea.key)
}

async function crearPedidoLocal() {
  errorNuevo.value = ''
  if (!nuevoPedido.mesa_id) {
    errorNuevo.value = 'Elige la mesa.'
    return
  }
  if (!nuevoPedido.lineas.length) {
    errorNuevo.value = 'Agrega al menos un producto.'
    return
  }

  const lineas: LineaPedidoPayload[] = nuevoPedido.lineas.map((l) => ({
    producto_id: l.producto.id,
    variante_id: l.variante_id ?? undefined,
    cantidad: l.cantidad,
    extras_ids: l.extras_ids.length ? l.extras_ids : undefined,
  }))

  creando.value = true
  try {
    const { data } = await pedidoService.crear({
      tipo: 'local',
      origen: 'mesero',
      mesa_id: Number(nuevoPedido.mesa_id),
      lineas,
    })
    pedidos.value = [data, ...pedidos.value]
    mostrarNuevo.value = false
    filtroEstado.value = 'activos'
  } catch (e) {
    errorNuevo.value = mensajeDeError(e, 'No pudimos crear el pedido.')
  } finally {
    creando.value = false
  }
}

onMounted(cargar)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Pedidos</h1>
        <p class="mt-1 text-sm text-gray-500">
          Local, domicilio y recogida — confirma, pasa a preparando y marca cada línea lista.
        </p>
      </div>
      <BaseButton @click="abrirNuevoPedido">
        <Plus :size="16" />
        Nuevo pedido local
      </BaseButton>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <div class="flex gap-1 rounded-lg bg-gray-100 p-1">
        <button
          v-for="opcion in OPCIONES_ESTADO"
          :key="opcion.v"
          type="button"
          class="rounded-md px-3 py-1.5 text-xs font-medium transition"
          :class="filtroEstado === opcion.v ? 'bg-white text-brand-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
          @click="filtroEstado = opcion.v"
        >
          {{ opcion.t }}
        </button>
      </div>
      <div class="flex gap-1 rounded-lg bg-gray-100 p-1">
        <button
          v-for="opcion in OPCIONES_TIPO"
          :key="opcion.v"
          type="button"
          class="rounded-md px-3 py-1.5 text-xs font-medium transition"
          :class="filtroTipo === opcion.v ? 'bg-white text-brand-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
          @click="filtroTipo = opcion.v"
        >
          {{ opcion.t }}
        </button>
      </div>
    </div>

    <p v-if="error" class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

    <div v-if="cargando" class="mt-10 text-center text-sm text-gray-500">Cargando pedidos…</div>

    <div v-else-if="pedidosFiltrados.length === 0" class="mt-10 rounded-xl border border-dashed border-gray-300 py-16 text-center">
      <ShoppingBasket :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm text-gray-500">No hay pedidos en este filtro.</p>
    </div>

    <ul v-else class="mt-6 space-y-3">
      <li v-for="pedido in pedidosFiltrados" :key="pedido.id" class="rounded-xl border border-gray-200 bg-white">
        <button type="button" class="flex w-full items-center justify-between gap-4 p-4 text-left" @click="alternar(pedido)">
          <div>
            <p class="text-sm font-semibold text-gray-900">
              Pedido #{{ pedido.id }} · {{ TIPOS[pedido.tipo] }}
              <span v-if="pedido.mesa"> · {{ pedido.mesa.nombre }}</span>
              <span v-else-if="pedido.cliente_invitado_nombre"> · {{ pedido.cliente_invitado_nombre }}</span>
            </p>
            <p class="mt-0.5 text-xs text-gray-500">
              {{ formatoHora(pedido.created_at) }} · {{ pedido.detalles?.length ?? 0 }} líneas
            </p>
          </div>
          <div class="flex items-center gap-3">
            <span class="rounded-full px-3 py-1 text-xs font-medium" :class="ESTADOS[pedido.estado].clase">
              {{ ESTADOS[pedido.estado].texto }}
            </span>
            <span class="hidden text-sm font-medium text-gray-900 sm:inline">{{ formatoPrecio(pedido.total) }}</span>
            <ChevronDown :size="18" class="text-gray-400 transition" :class="{ 'rotate-180': abierto === pedido.id }" />
          </div>
        </button>

        <div v-if="abierto === pedido.id" class="border-t border-gray-100 px-4 py-4">
          <ul class="space-y-2 text-sm">
            <li v-for="detalle in pedido.detalles" :key="detalle.id" class="flex items-center justify-between gap-3">
              <div class="flex items-center gap-2">
                <button
                  v-if="pedido.estado === 'preparando'"
                  type="button"
                  class="flex size-5 shrink-0 items-center justify-center rounded-full border transition"
                  :class="detalle.preparado ? 'border-green-500 bg-green-500 text-white' : 'border-gray-300 text-transparent hover:border-brand-500'"
                  :disabled="detalle.preparado"
                  @click="marcarLineaPreparada(pedido, detalle.id)"
                >
                  <Check :size="12" />
                </button>
                <span :class="detalle.preparado ? 'text-gray-400 line-through' : 'text-gray-700'">
                  {{ detalle.cantidad }}× {{ detalle.producto?.nombre ?? detalle.combo?.nombre }}
                  <span v-if="detalle.variante" class="text-xs text-gray-500">({{ detalle.variante.nombre }})</span>
                  <span v-if="detalle.extras?.length" class="block text-xs italic text-gray-500">
                    + {{ detalle.extras.map((e) => e.nombre).join(', ') }}
                  </span>
                  <span v-if="detalle.observaciones" class="block text-xs italic text-gray-500">"{{ detalle.observaciones }}"</span>
                </span>
              </div>
              <span class="shrink-0 text-gray-700">{{ formatoPrecio(precioLinea(detalle)) }}</span>
            </li>
          </ul>

          <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3 text-sm font-semibold text-gray-900">
            <span>Total</span>
            <span>{{ formatoPrecio(pedido.total) }}</span>
          </div>

          <div v-if="['pendiente', 'confirmado', 'preparando'].includes(pedido.estado)" class="mt-4 flex flex-wrap gap-2">
            <BaseButton
              v-if="pedido.estado === 'pendiente'"
              variant="primary"
              :disabled="procesando === pedido.id"
              @click="confirmar(pedido)"
            >
              Confirmar
            </BaseButton>
            <BaseButton
              v-if="pedido.estado === 'confirmado'"
              variant="primary"
              :disabled="procesando === pedido.id"
              @click="marcarPreparando(pedido)"
            >
              Marcar preparando
            </BaseButton>
            <BaseButton variant="ghost" :disabled="procesando === pedido.id" @click="abrirCancelar(pedido)">
              Cancelar pedido
            </BaseButton>
          </div>
        </div>
      </li>
    </ul>

    <!-- Nuevo pedido local -->
    <BaseModal v-model="mostrarNuevo" title="Nuevo pedido local">
      <div v-if="cargandoCatalogo" class="py-10 text-center text-sm text-gray-500">Cargando catálogo…</div>
      <template v-else>
        <label class="block">
          <span class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-gray-500">Mesa</span>
          <select
            v-model="nuevoPedido.mesa_id"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
          >
            <option value="" disabled>Elige una mesa</option>
            <option v-for="mesa in mesas" :key="mesa.id" :value="mesa.id">{{ mesa.nombre }} — {{ mesa.estado }}</option>
          </select>
        </label>

        <div class="mt-4 max-h-56 space-y-1 overflow-y-auto rounded-lg border border-gray-100 p-2">
          <div
            v-for="producto in productosDisponibles"
            :key="producto.id"
            class="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-gray-50"
          >
            <div>
              <p class="text-sm font-medium text-gray-900">{{ producto.nombre }}</p>
              <p class="text-xs text-gray-500">{{ formatoPrecio(producto.precio_base) }}</p>
            </div>
            <button
              type="button"
              class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:border-brand-500 hover:text-brand-700"
              @click="agregarProducto(producto)"
            >
              Agregar
            </button>
          </div>
        </div>

        <div v-if="nuevoPedido.lineas.length" class="mt-4 space-y-3">
          <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Líneas del pedido</p>
          <div v-for="linea in nuevoPedido.lineas" :key="linea.key" class="rounded-lg border border-gray-200 p-3">
            <div class="flex items-start justify-between gap-3">
              <p class="text-sm font-medium text-gray-900">{{ linea.producto.nombre }}</p>
              <button type="button" class="text-gray-400 hover:text-red-600" @click="quitarLinea(linea)">
                <X :size="16" />
              </button>
            </div>

            <select
              v-if="linea.producto.variantes?.length"
              class="mt-2 w-full rounded-lg border border-gray-300 px-2 py-1.5 text-xs"
              :value="linea.variante_id"
              @change="actualizarVariante(linea, Number(($event.target as HTMLSelectElement).value))"
            >
              <option v-for="variante in linea.producto.variantes" :key="variante.id" :value="variante.id">
                {{ variante.nombre }} — {{ formatoPrecio(variante.precio) }}
              </option>
            </select>

            <div v-if="linea.producto.extras_especificos?.length" class="mt-2 flex flex-wrap gap-2">
              <label
                v-for="extra in linea.producto.extras_especificos"
                :key="extra.id"
                class="flex items-center gap-1.5 rounded-full border border-gray-200 px-2.5 py-1 text-xs text-gray-600"
              >
                <input type="checkbox" :checked="linea.extras_ids.includes(extra.id)" @change="alternarExtra(linea, extra.id)" />
                {{ extra.nombre }} (+{{ formatoPrecio(extra.precio) }})
              </label>
            </div>

            <div class="mt-2 flex items-center justify-between">
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="flex size-7 items-center justify-center rounded-full border border-gray-300 text-gray-600 hover:border-brand-500"
                  @click="linea.cantidad = Math.max(1, linea.cantidad - 1)"
                >
                  <Minus :size="14" />
                </button>
                <span class="w-5 text-center text-sm">{{ linea.cantidad }}</span>
                <button
                  type="button"
                  class="flex size-7 items-center justify-center rounded-full border border-gray-300 text-gray-600 hover:border-brand-500"
                  @click="linea.cantidad += 1"
                >
                  <Plus :size="14" />
                </button>
              </div>
              <span class="text-sm font-medium text-gray-900">
                {{ formatoPrecio((linea.precioUnitario + precioExtrasLinea(linea)) * linea.cantidad) }}
              </span>
            </div>
          </div>

          <div class="flex items-center justify-between border-t border-gray-100 pt-3 text-sm font-semibold text-gray-900">
            <span>Total estimado</span>
            <span>{{ formatoPrecio(totalNuevoPedido) }}</span>
          </div>
        </div>

        <p v-if="errorNuevo" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{{ errorNuevo }}</p>

        <BaseButton class="mt-4 w-full" :disabled="creando" @click="crearPedidoLocal">
          {{ creando ? 'Creando…' : 'Crear pedido' }}
        </BaseButton>
      </template>
    </BaseModal>

    <!-- Cancelar pedido -->
    <BaseModal :model-value="!!pedidoACancelar" title="Cancelar pedido" @update:model-value="pedidoACancelar = null">
      <p class="text-sm text-gray-600">
        Vas a cancelar el pedido #{{ pedidoACancelar?.id }}. Escribe el motivo (obligatorio).
      </p>
      <textarea
        v-model="motivoCancelar"
        rows="3"
        class="mt-3 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
        placeholder="Ej: el cliente se fue / producto agotado / pedido duplicado"
      />
      <p v-if="errorCancelar" class="mt-2 text-xs text-red-600">{{ errorCancelar }}</p>
      <div class="mt-4 flex justify-end gap-2">
        <BaseButton variant="ghost" @click="pedidoACancelar = null">Volver</BaseButton>
        <BaseButton variant="danger" :disabled="cancelando" @click="confirmarCancelar">
          {{ cancelando ? 'Cancelando…' : 'Confirmar cancelación' }}
        </BaseButton>
      </div>
    </BaseModal>
  </div>
</template>
