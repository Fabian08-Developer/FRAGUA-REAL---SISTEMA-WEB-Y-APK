<script setup lang="ts">
// M02 — Pedidos del Mesero: listado de los 3 canales (Local/Domicilio/Recogida),
// tomar pedido Local desde el piso, confirmar, marcar preparando, marcar cada línea
// preparada y cancelar (RN-PED-10, motivo obligatorio). También confirma la entrega de un
// domicilio y la recogida en el local, y finaliza el pedido cuando ya cumple todo
// (RN-PED-32, RF-M07-025, RF-M07-R-013). El cobro (M06) vive en Mesero > Cobrar.
// Fase 1: edita un pedido PENDIENTE o lo no preparado de uno CONFIRMADO (RN-PED-07/08) y
// muestra su historial (HU-M02-020). Fase 2: descuento manual con motivo (RF-M04-036, HU-M04-015).
// Fase 4, tarea 5: «Listo para recoger» avisa al cliente por correo (RF-M07-R-012).
import { computed, onMounted, ref, watch } from 'vue'
import { Check, ChevronDown, History, Pencil, Percent, Plus, ShoppingBasket } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import DatosEntregaPedido from '@/components/pedidos/DatosEntregaPedido.vue'
import DescuentoPedidoModal from '@/components/pedidos/DescuentoPedidoModal.vue'
import EditarPedidoModal from '@/components/pedidos/EditarPedidoModal.vue'
import HistorialPedidoModal from '@/components/pedidos/HistorialPedidoModal.vue'
import ListoParaRecoger from '@/components/pedidos/ListoParaRecoger.vue'
import TotalesPedido from '@/components/pedidos/TotalesPedido.vue'
import { TIPOS_PEDIDO_UI, faltaParaFinalizar, formatoHoraPedido, saldoPendiente, titularPedido } from '@/utils/pedidoUi'
import { pedidoService } from '@/services/pedido.service'
import { mensajeDeError } from '@/services/errors'
import type { Pedido, PedidoAviso } from '@/types/models'

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

// "acento" = franja de color en el borde izquierdo de la tarjeta: el estado se lee de un
// vistazo al recorrer la lista, sin tener que leer cada etiqueta.
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

// Total de activos según el servidor (la lista cargada puede ser de otra pestaña).
const conteoActivos = ref(0)

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
    // Cada pestaña pide al servidor lo suyo: antes se traían solo los 20 pedidos más recientes
    // y los activos más viejos podían quedar fuera de la lista.
    const params =
      filtroEstado.value === 'activos'
        ? { activos: true, per_page: 100 }
        : { estado: filtroEstado.value === 'finalizados' ? ('finalizado' as const) : ('cancelado' as const), per_page: 50 }
    const { data } = await pedidoService.index(params)
    pedidos.value = data.data
    if (filtroEstado.value === 'activos') conteoActivos.value = data.total
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar los pedidos.')
  } finally {
    cargando.value = false
  }
}

watch(filtroEstado, () => {
  abierto.value = null
  cargar()
})

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

// ---- Entrega, recogida y finalización (RN-PED-32) ----
// Si la acción completa lo último que faltaba, el backend devuelve el pedido ya FINALIZADO
// y sale de la pestaña Activos.
async function ejecutar(pedido: Pedido, accion: (id: number) => Promise<{ data: Pedido }>, mensajeFallo: string) {
  procesando.value = pedido.id
  error.value = ''
  try {
    const { data } = await accion(pedido.id)
    reemplazarPedido(data)
    if (data.estado === 'finalizado') conteoActivos.value = Math.max(0, conteoActivos.value - 1)
  } catch (e) {
    error.value = mensajeDeError(e, mensajeFallo)
  } finally {
    procesando.value = null
  }
}

const confirmarEntrega = (pedido: Pedido) =>
  ejecutar(pedido, pedidoService.confirmarEntrega, 'No pudimos confirmar la entrega.')
const confirmarRecogida = (pedido: Pedido) =>
  ejecutar(pedido, pedidoService.confirmarRecogida, 'No pudimos confirmar la recogida.')
const finalizar = (pedido: Pedido) => ejecutar(pedido, pedidoService.finalizar, 'No pudimos finalizar el pedido.')
// Fase 4, tarea 5 (RF-M07-R-012): la recogida está lista; el backend avisa al cliente.
const marcarListo = (pedido: Pedido) =>
  ejecutar(pedido, pedidoService.marcarListo, 'No pudimos marcar el pedido como listo para recoger.')
const reintentarAviso = (pedido: Pedido, aviso: PedidoAviso) =>
  ejecutar(pedido, (id) => pedidoService.reintentarAviso(id, aviso.id), 'No pudimos reintentar el aviso.')

// Lo que le falta a un pedido en preparación, en una línea para el Mesero.
function pendientesDe(pedido: Pedido): string[] {
  const pendientes: string[] = []
  // En domicilio la entrega va primero: el pago contra entrega se cobra después (RF-M06-004).
  if (pedido.tipo === 'domicilio' && !pedido.entregado_at) pendientes.push('confirmar la entrega')
  if (saldoPendiente(pedido) > 0) pendientes.push(`cobrar ${formatoPrecio(saldoPendiente(pedido))} en Cobrar`)
  if (pedido.tipo === 'recogida' && !pedido.recogido_at) pendientes.push('confirmar la recogida')
  return pendientes
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

// ---- Editar e historial (Fase 1, tarea 1) ----
// El modal lee el pedido desde la lista, así cada cambio guardado se ve también en la tarjeta.
const editandoId = ref<number | null>(null)
const pedidoEnEdicion = computed(() => pedidos.value.find((p) => p.id === editandoId.value) ?? null)
const historialId = ref<number | null>(null)
// Fase 2, tarea 1 (RF-M04-036, HU-M04-015): descuento manual con motivo.
const descuentoId = ref<number | null>(null)
const pedidoEnDescuento = computed(() => pedidos.value.find((p) => p.id === descuentoId.value) ?? null)

// ---- Cancelar (motivo obligatorio — RN-PED-10, CancelarPedidoRequest) ----
const pedidoACancelar = ref<Pedido | null>(null)
const motivoCancelar = ref('')
const errorCancelar = ref('')
const cancelando = ref(false)
// Fase 1, tarea 6 (RN-M07-05): lo que ya está confirmado o en preparación se cancela con la
// contraseña de un Administrador; queda en la auditoría quién autorizó.
const passwordAdministrador = ref('')
const requiereAutorizacion = computed(() => !!pedidoACancelar.value && pedidoACancelar.value.estado !== 'pendiente')

function abrirCancelar(pedido: Pedido) {
  pedidoACancelar.value = pedido
  motivoCancelar.value = ''
  errorCancelar.value = ''
  passwordAdministrador.value = ''
}

async function confirmarCancelar() {
  if (!pedidoACancelar.value) return
  if (!motivoCancelar.value.trim()) {
    errorCancelar.value = 'Escribe el motivo de la cancelación.'
    return
  }
  if (requiereAutorizacion.value && !passwordAdministrador.value) {
    errorCancelar.value = 'Pide a un Administrador que escriba su contraseña.'
    return
  }
  cancelando.value = true
  try {
    const { data } = await pedidoService.cancelar(
      pedidoACancelar.value.id,
      motivoCancelar.value.trim(),
      requiereAutorizacion.value ? passwordAdministrador.value : undefined,
    )
    reemplazarPedido(data)
    conteoActivos.value = Math.max(0, conteoActivos.value - 1)
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
      eyebrow="SERVICIO"
      titulo="Pedidos"
      descripcion="Local, domicilio y recogida — confirma, pasa a preparando y marca cada línea lista."
    >
      <template #actions>
        <!-- Pantalla completa, no modal (RN de diseño: el Mesero usa teléfono/tablet) —
             ver views/mesero/NuevoPedidoView.vue. Ancho completo y alto en teléfono para
             que sea fácil de tocar. Desde la Fase 1 también registra domicilios y recogidas
             pedidos por teléfono (se elige el canal en el primer paso). -->
        <router-link
          :to="{ name: 'mesero-nuevo-pedido' }"
          class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-brand-500 px-5 py-3 text-sm font-semibold text-ink shadow-gold transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400 active:scale-[0.98] sm:min-h-11"
        >
          <Plus :size="18" />
          Nuevo pedido
        </router-link>
      </template>
    </PageHeader>

    <div class="mt-6 flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3">
      <div class="segmented">
        <button
          v-for="opcion in OPCIONES_ESTADO"
          :key="opcion.v"
          type="button"
          class="segmented-item"
          :class="filtroEstado === opcion.v ? 'is-active' : ''"
          @click="filtroEstado = opcion.v"
        >
          {{ opcion.t }}
          <span
            v-if="opcion.v === 'activos' && conteoActivos"
            class="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-brand-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-ink"
          >
            {{ conteoActivos }}
          </span>
        </button>
      </div>
      <div class="segmented">
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

    <div v-else-if="pedidosFiltrados.length === 0" class="mt-8 empty-state">
      <ShoppingBasket :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm font-medium text-ink">No hay pedidos en este filtro</p>
      <p class="mt-1 text-xs text-gray-500">Cuando entre uno nuevo aparecerá aquí.</p>
    </div>

    <ul v-else class="mt-6 space-y-3">
      <li
        v-for="pedido in pedidosFiltrados"
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

          <!-- El Mesero piensa en "la mesa 3" o en "el domicilio de Juan", no en el número de
               pedido — por eso el título es quién/dónde y el #id pasa al renglón de detalle. -->
          <div class="min-w-0 flex-1">
            <p class="truncate text-[15px] font-semibold text-ink">
              {{ titularPedido(pedido) }}
            </p>
            <p class="mt-0.5 truncate text-xs text-gray-500">
              #{{ pedido.id }} · {{ TIPOS_PEDIDO_UI[pedido.tipo].texto }} · {{ formatoHoraPedido(pedido.created_at) }}
            </p>
            <p class="mt-0.5 text-xs text-gray-400">
              {{ pedido.detalles?.length ?? 0 }} {{ pedido.detalles?.length === 1 ? 'línea' : 'líneas' }}
            </p>
          </div>

          <div class="flex shrink-0 flex-col items-end gap-1.5">
            <span class="badge" :class="ESTADOS[pedido.estado].clase">
              {{ ESTADOS[pedido.estado].texto }}
            </span>
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
              <div class="flex min-w-0 items-start gap-3">
                <!-- Zona táctil de 32px: marcar líneas con el dedo en plena cocina no puede exigir puntería. -->
                <button
                  v-if="pedido.estado === 'preparando'"
                  type="button"
                  class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full border-2 transition active:scale-90"
                  :class="
                    detalle.preparado
                      ? 'border-emerald-500 bg-emerald-500 text-white'
                      : 'border-gray-300 bg-white text-transparent hover:border-brand-500'
                  "
                  :disabled="detalle.preparado"
                  :aria-label="detalle.preparado ? 'Línea preparada' : 'Marcar línea como preparada'"
                  @click="marcarLineaPreparada(pedido, detalle.id)"
                >
                  <Check :size="16" :stroke-width="3" />
                </button>
                <span class="min-w-0" :class="detalle.preparado ? 'text-gray-400 line-through' : 'text-ink'">
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
              </div>
              <span class="shrink-0 font-medium text-gray-600">{{ formatoPrecio(precioLinea(detalle)) }}</span>
            </li>
          </ul>

          <div class="mt-4 border-t border-gray-200 pt-3">
            <TotalesPedido :pedido="pedido" />
          </div>

          <p
            v-if="pedido.estado === 'preparando' && pendientesDe(pedido).length"
            class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800"
          >
            Para finalizar falta: {{ pendientesDe(pedido).join(' · ') }}.
          </p>

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
              v-if="pedido.estado === 'preparando' && pedido.tipo === 'domicilio' && !pedido.entregado_at"
              variant="primary"
              :disabled="procesando === pedido.id"
              @click="confirmarEntrega(pedido)"
            >
              Confirmar entrega
            </BaseButton>
            <BaseButton
              v-if="pedido.estado === 'preparando' && pedido.tipo === 'recogida' && !pedido.recogido_at"
              variant="primary"
              :disabled="procesando === pedido.id"
              @click="confirmarRecogida(pedido)"
            >
              Confirmar recogida
            </BaseButton>
            <BaseButton
              v-if="faltaParaFinalizar(pedido) === null"
              variant="primary"
              :disabled="procesando === pedido.id"
              @click="finalizar(pedido)"
            >
              Finalizar pedido
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

    <EditarPedidoModal :pedido="pedidoEnEdicion" @cerrar="editandoId = null" @actualizado="reemplazarPedido" />
    <HistorialPedidoModal :pedido-id="historialId" @cerrar="historialId = null" />
    <DescuentoPedidoModal :pedido="pedidoEnDescuento" @cerrar="descuentoId = null" @actualizado="reemplazarPedido" />

    <!-- Cancelar pedido -->
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
      <template v-if="requiereAutorizacion">
        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
          El pedido ya está {{ pedidoACancelar?.estado === 'preparando' ? 'en preparación' : 'confirmado' }}: para cancelarlo
          hace falta la autorización de un Administrador.
        </p>
        <label class="mt-3 block">
          <span class="field-label">Contraseña del Administrador</span>
          <input v-model="passwordAdministrador" type="password" autocomplete="off" class="field" />
        </label>
      </template>
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
