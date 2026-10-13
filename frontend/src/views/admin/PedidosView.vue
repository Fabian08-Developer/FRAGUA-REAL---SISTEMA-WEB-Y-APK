<script setup lang="ts">
// M02/M07 — Administrador > Pedidos: vista de supervisión de los 3 canales (Local/Domicilio/
// Recogida). M02 no define un panel propio del Administrador ("Administrador: configura e
// interviene según permisos de M01"), así que esta pantalla solo expone lo que el backend ya
// le permite: ver todos los pedidos con filtros e historial paginado, confirmar, marcar
// preparando y cancelar con motivo (PedidoPolicy). El COBRO NO vive aquí: lo registra siempre
// el Mesero (RF-M06-003/004, RNF-M06-004); el Administrador solo ve cuánto lleva pagado.
// Fase 1: también edita un pedido PENDIENTE o lo no preparado de uno CONFIRMADO (RN-PED-07/08)
// y consulta el historial de cualquier pedido (HU-M02-020).
// Fase 2, tarea 1: aplica o quita un descuento manual con motivo (RF-M04-036, HU-M04-015).
// Fase 4, tarea 5: marca una recogida «Listo para recoger» y reintenta el aviso si falló (RF-M07-R-012).
import { computed, onMounted, ref, watch } from 'vue'
import { ChevronDown, History, Pencil, Percent, RefreshCw, ShoppingBasket } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import DatosEntregaPedido from '@/components/pedidos/DatosEntregaPedido.vue'
import DescuentoPedidoModal from '@/components/pedidos/DescuentoPedidoModal.vue'
import EditarPedidoModal from '@/components/pedidos/EditarPedidoModal.vue'
import HistorialPedidoModal from '@/components/pedidos/HistorialPedidoModal.vue'
import ListoParaRecoger from '@/components/pedidos/ListoParaRecoger.vue'
import TotalesPedido from '@/components/pedidos/TotalesPedido.vue'
import { TIPOS_PEDIDO_UI, formatoHoraPedido, titularPedido } from '@/utils/pedidoUi'
import { pedidoService } from '@/services/pedido.service'
import { mensajeDeError } from '@/services/errors'
import type { Pedido, PedidoAviso } from '@/types/models'

const pedidos = ref<Pedido[]>([])
const cargando = ref(true)
const error = ref('')
const abierto = ref<number | null>(null)
const procesando = ref<number | null>(null)

const pagina = ref(1)
const ultimaPagina = ref(1)
const total = ref(0)

const filtroEstado = ref<'todos' | Pedido['estado']>('todos')
const filtroTipo = ref<'todos' | Pedido['tipo']>('todos')

const OPCIONES_ESTADO: { v: typeof filtroEstado.value; t: string }[] = [
  { v: 'todos', t: 'Todos' },
  { v: 'pendiente', t: 'Pendientes' },
  { v: 'confirmado', t: 'Confirmados' },
  { v: 'preparando', t: 'Preparando' },
  { v: 'finalizado', t: 'Finalizados' },
  { v: 'cancelado', t: 'Cancelados' },
]

const OPCIONES_TIPO: { v: typeof filtroTipo.value; t: string }[] = [
  { v: 'todos', t: 'Todos los canales' },
  { v: 'local', t: 'Local' },
  { v: 'domicilio', t: 'Domicilio' },
  { v: 'recogida', t: 'Recogida' },
]

const ESTADOS: Record<Pedido['estado'], { texto: string; clase: string; acento: string }> = {
  pendiente: { texto: 'Pendiente', clase: 'bg-amber-50 text-amber-700 ring-amber-600/20', acento: 'border-l-amber-400' },
  confirmado: { texto: 'Confirmado', clase: 'bg-sky-50 text-sky-700 ring-sky-600/20', acento: 'border-l-sky-400' },
  preparando: { texto: 'Preparando', clase: 'bg-orange-50 text-orange-700 ring-orange-600/20', acento: 'border-l-orange-400' },
  finalizado: { texto: 'Finalizado', clase: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', acento: 'border-l-emerald-400' },
  cancelado: { texto: 'Cancelado', clase: 'bg-red-50 text-red-700 ring-red-600/20', acento: 'border-l-red-300' },
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function precioLinea(detalle: NonNullable<Pedido['detalles']>[number]) {
  const extras = (detalle.extras ?? []).reduce((acc, e) => acc + Number(e.pivot.precio_congelado), 0)
  return (Number(detalle.precio_unitario_congelado) + extras) * detalle.cantidad
}

// Solo cuentan los pagos aprobados y no anulados (mismo criterio que RF-M06-011).
function pagado(pedido: Pedido) {
  return (pedido.pagos ?? [])
    .filter((p) => !p.anulado && p.estado === 'aprobado')
    .reduce((acc, p) => acc + Number(p.monto), 0)
}

const hayFiltros = computed(() => filtroEstado.value !== 'todos' || filtroTipo.value !== 'todos')

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await pedidoService.index({
      estado: filtroEstado.value === 'todos' ? undefined : filtroEstado.value,
      tipo: filtroTipo.value === 'todos' ? undefined : filtroTipo.value,
      page: pagina.value,
    })
    pedidos.value = data.data
    ultimaPagina.value = data.last_page
    total.value = data.total
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar los pedidos.')
  } finally {
    cargando.value = false
  }
}

// Cambiar un filtro vuelve a la página 1; cambiar de página recarga sin tocar los filtros.
watch([filtroEstado, filtroTipo], () => {
  abierto.value = null
  if (pagina.value !== 1) pagina.value = 1
  else cargar()
})
watch(pagina, cargar)

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

// Fase 4, tarea 5 (RF-M07-R-012): la recogida está lista; el backend avisa al cliente por correo.
async function avisar(pedido: Pedido, accion: () => Promise<{ data: Pedido }>, mensajeFallo: string) {
  procesando.value = pedido.id
  error.value = ''
  try {
    const { data } = await accion()
    reemplazarPedido(data)
  } catch (e) {
    error.value = mensajeDeError(e, mensajeFallo)
  } finally {
    procesando.value = null
  }
}
const marcarListo = (pedido: Pedido) =>
  avisar(pedido, () => pedidoService.marcarListo(pedido.id), 'No pudimos marcar el pedido como listo para recoger.')
const reintentarAviso = (pedido: Pedido, aviso: PedidoAviso) =>
  avisar(pedido, () => pedidoService.reintentarAviso(pedido.id, aviso.id), 'No pudimos reintentar el aviso.')

// ---- Cancelar (motivo obligatorio, CancelarPedidoRequest) ----
// ---- Editar e historial (Fase 1, tarea 1) ----
const editandoId = ref<number | null>(null)
const pedidoEnEdicion = computed(() => pedidos.value.find((p) => p.id === editandoId.value) ?? null)
const historialId = ref<number | null>(null)
const descuentoId = ref<number | null>(null)
const pedidoEnDescuento = computed(() => pedidos.value.find((p) => p.id === descuentoId.value) ?? null)

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

onMounted(cargar)
</script>

<template>
  <div>
    <PageHeader
      eyebrow="OPERACIÓN"
      titulo="Pedidos"
      descripcion="Supervisa los pedidos de local, domicilio y recogida. El cobro lo registra el Mesero."
    >
      <template #actions>
        <BaseButton variant="ghost" :disabled="cargando" @click="cargar">
          <RefreshCw :size="16" class="mr-1.5" />
          Actualizar
        </BaseButton>
      </template>
    </PageHeader>

    <div class="mt-6 flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3">
      <div class="segmented flex-wrap">
        <button
          v-for="opcion in OPCIONES_ESTADO"
          :key="opcion.v"
          type="button"
          class="segmented-item"
          :class="filtroEstado === opcion.v ? 'is-active' : ''"
          @click="filtroEstado = opcion.v"
        >
          {{ opcion.t }}
        </button>
      </div>
      <div class="segmented flex-wrap">
        <button
          v-for="opcion in OPCIONES_TIPO"
          :key="opcion.v"
          type="button"
          class="segmented-item"
          :class="filtroTipo === opcion.v ? 'is-active' : ''"
          @click="filtroTipo = opcion.v"
        >
          {{ opcion.t }}
        </button>
      </div>
    </div>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>

    <div v-if="cargando" class="loading-state">Cargando pedidos…</div>

    <div v-else-if="pedidos.length === 0" class="mt-8 empty-state">
      <ShoppingBasket :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm font-medium text-ink">
        {{ hayFiltros ? 'No hay pedidos con estos filtros' : 'Todavía no hay pedidos' }}
      </p>
      <p class="mt-1 text-xs text-gray-500">Cuando entre uno nuevo aparecerá aquí.</p>
    </div>

    <template v-else>
      <p class="mt-4 text-xs text-gray-500">
        {{ total }} {{ total === 1 ? 'pedido' : 'pedidos' }} · página {{ pagina }} de {{ ultimaPagina }}
      </p>

      <ul class="mt-3 space-y-3">
        <li
          v-for="pedido in pedidos"
          :key="pedido.id"
          class="card overflow-hidden border-l-4 transition-shadow"
          :class="[ESTADOS[pedido.estado].acento, { 'shadow-card-hover': abierto === pedido.id }]"
        >
          <button
            type="button"
            class="flex w-full items-center gap-3 p-3.5 text-left sm:gap-4 sm:p-4"
            :aria-expanded="abierto === pedido.id"
            @click="alternar(pedido)"
          >
            <span
              class="flex size-11 shrink-0 items-center justify-center rounded-xl"
              :class="TIPOS_PEDIDO_UI[pedido.tipo].clase"
            >
              <component :is="TIPOS_PEDIDO_UI[pedido.tipo].icono" :size="21" />
            </span>

            <div class="min-w-0 flex-1">
              <p class="truncate text-[15px] font-semibold text-ink">{{ titularPedido(pedido) }}</p>
              <p class="mt-0.5 truncate text-xs text-gray-500">
                #{{ pedido.id }} · {{ TIPOS_PEDIDO_UI[pedido.tipo].texto }} · {{ formatoHoraPedido(pedido.created_at) }}
              </p>
              <p class="mt-0.5 text-xs text-gray-400">
                {{ pedido.detalles?.length ?? 0 }} {{ pedido.detalles?.length === 1 ? 'línea' : 'líneas' }}
              </p>
            </div>

            <div class="flex shrink-0 flex-col items-end gap-1.5">
              <span class="badge" :class="ESTADOS[pedido.estado].clase">{{ ESTADOS[pedido.estado].texto }}</span>
              <span class="text-sm font-bold text-ink">{{ formatoPrecio(pedido.total) }}</span>
            </div>

            <ChevronDown
              :size="18"
              class="shrink-0 text-gray-400 transition-transform duration-200"
              :class="{ 'rotate-180': abierto === pedido.id }"
            />
          </button>

          <div v-if="abierto === pedido.id" class="border-t border-gray-200 bg-gray-50/70 px-3.5 py-4 sm:px-4">
            <DatosEntregaPedido :pedido="pedido" />

            <ul class="divide-y divide-gray-200/70 text-sm">
              <li
                v-for="detalle in pedido.detalles"
                :key="detalle.id"
                class="flex items-start justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
              >
                <span class="min-w-0" :class="detalle.preparado ? 'text-gray-400' : 'text-ink'">
                  <span class="font-semibold">{{ detalle.cantidad }}×</span>
                  {{ detalle.producto?.nombre ?? detalle.combo?.nombre }}
                  <span v-if="detalle.variante" class="text-xs text-gray-500">({{ detalle.variante.nombre }})</span>
                  <span v-if="detalle.extras?.length" class="block text-xs text-gray-500">
                    + {{ detalle.extras.map((e) => e.nombre).join(', ') }}
                  </span>
                  <span v-if="detalle.observaciones" class="mt-0.5 block text-xs italic text-brand-700">
                    “{{ detalle.observaciones }}”
                  </span>
                </span>
                <span class="shrink-0 font-medium text-gray-600">{{ formatoPrecio(precioLinea(detalle)) }}</span>
              </li>
            </ul>

            <div class="mt-4 space-y-1.5 border-t border-gray-200 pt-3 text-sm">
              <TotalesPedido :pedido="pedido" />
              <div class="flex items-center justify-between text-xs text-gray-500">
                <span>Pagado</span>
                <span class="font-semibold" :class="pagado(pedido) >= Number(pedido.total) ? 'text-emerald-600' : 'text-gray-700'">
                  {{ formatoPrecio(pagado(pedido)) }}
                </span>
              </div>
            </div>

            <ListoParaRecoger
              :pedido="pedido"
              :procesando="procesando === pedido.id"
              @marcar="marcarListo(pedido)"
              @reintentar="(aviso) => reintentarAviso(pedido, aviso)"
            />

            <div
              v-if="['pendiente', 'confirmado', 'preparando'].includes(pedido.estado)"
              class="mt-4 grid grid-cols-1 gap-2 sm:flex sm:flex-wrap"
            >
              <BaseButton
                v-if="pedido.estado === 'pendiente'"
                variant="primary"
                :disabled="procesando === pedido.id"
                @click="confirmar(pedido)"
              >
                Confirmar pedido
              </BaseButton>
              <BaseButton
                v-if="pedido.estado === 'confirmado'"
                variant="primary"
                :disabled="procesando === pedido.id"
                @click="marcarPreparando(pedido)"
              >
                Marcar preparando
              </BaseButton>
              <BaseButton
                v-if="pedido.estado === 'pendiente' || pedido.estado === 'confirmado'"
                variant="secondary"
                :disabled="procesando === pedido.id"
                @click="editandoId = pedido.id"
              >
                <Pencil :size="15" /> Editar pedido
              </BaseButton>
              <BaseButton variant="secondary" :disabled="procesando === pedido.id" @click="descuentoId = pedido.id">
                <Percent :size="15" /> {{ pedido.descuento_manual ? 'Ver descuento' : 'Descuento' }}
              </BaseButton>
              <BaseButton variant="ghost" :disabled="procesando === pedido.id" @click="abrirCancelar(pedido)">
                Cancelar pedido
              </BaseButton>
            </div>

            <button
              type="button"
              class="mt-3 inline-flex min-h-10 items-center gap-1.5 text-xs font-semibold text-gray-600 hover:text-ink"
              @click="historialId = pedido.id"
            >
              <History :size="15" /> Ver historial
            </button>
          </div>
        </li>
      </ul>

      <div v-if="ultimaPagina > 1" class="mt-6 flex items-center justify-center gap-3">
        <BaseButton variant="ghost" :disabled="pagina <= 1 || cargando" @click="pagina--">Anterior</BaseButton>
        <span class="text-xs text-gray-500">{{ pagina }} / {{ ultimaPagina }}</span>
        <BaseButton variant="ghost" :disabled="pagina >= ultimaPagina || cargando" @click="pagina++">Siguiente</BaseButton>
      </div>
    </template>

    <EditarPedidoModal :pedido="pedidoEnEdicion" @cerrar="editandoId = null" @actualizado="reemplazarPedido" />
    <HistorialPedidoModal :pedido-id="historialId" @cerrar="historialId = null" />
    <DescuentoPedidoModal :pedido="pedidoEnDescuento" @cerrar="descuentoId = null" @actualizado="reemplazarPedido" />

    <BaseModal :model-value="!!pedidoACancelar" title="Cancelar pedido" @update:model-value="pedidoACancelar = null">
      <p class="text-sm text-gray-600">
        Vas a cancelar el pedido <strong class="text-ink">#{{ pedidoACancelar?.id }}</strong
        >. Escribe el motivo (obligatorio).
      </p>
      <textarea
        v-model="motivoCancelar"
        rows="3"
        class="field mt-3"
        placeholder="Ej: el cliente se fue / producto agotado / pedido duplicado"
      />
      <p v-if="errorCancelar" class="mt-2 text-xs text-red-600">{{ errorCancelar }}</p>
      <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <BaseButton variant="ghost" @click="pedidoACancelar = null">Volver</BaseButton>
        <BaseButton variant="danger" :disabled="cancelando" @click="confirmarCancelar">
          {{ cancelando ? 'Cancelando…' : 'Confirmar cancelación' }}
        </BaseButton>
      </div>
    </BaseModal>
  </div>
</template>
