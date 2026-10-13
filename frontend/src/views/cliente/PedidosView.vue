<script setup lang="ts">
// M02/M07/M05 — "Mis pedidos y reservas". GET /pedidos ya viene filtrado a "los míos"
// cuando el usuario autenticado es Cliente (PedidoController::index); GET /mis-reservas
// identifica las reservas propias por correo de contacto, ya que Reserva no tiene
// cliente_id (RN-M05-12 — ver ReservaController::misReservas()).
import { computed, onMounted, ref } from 'vue'
import { CalendarClock, ChevronDown, PackageSearch, XCircle } from 'lucide-vue-next'
import mascota from '@/assets/img/mascota-fragua-real.png'
import TotalesPedido from '@/components/pedidos/TotalesPedido.vue'
import { pedidoService } from '@/services/pedido.service'
import { reservaService } from '@/services/reserva.service'
import { mensajeDeError } from '@/services/errors'
import type { EstadoReserva, Pedido, Reserva } from '@/types/models'

const tab = ref<'pedidos' | 'reservas'>('pedidos')

// ---- Pedidos ----
const pedidos = ref<Pedido[]>([])
const cargando = ref(true)
const error = ref('')
const abierto = ref<number | null>(null)
const detalleCache = ref<Record<number, Pedido>>({})
const cargandoDetalle = ref<number | null>(null)

const pedidoACancelar = ref<Pedido | null>(null)
const motivoCancelacion = ref('')
const cancelandoPedido = ref(false)
const errorCancelarPedido = ref('')

const ESTADOS: Record<Pedido['estado'], { texto: string; clase: string }> = {
  pendiente: { texto: 'Pendiente', clase: 'bg-amber-100 text-amber-800' },
  confirmado: { texto: 'Confirmado', clase: 'bg-blue-100 text-blue-800' },
  preparando: { texto: 'Preparando', clase: 'bg-orange-100 text-orange-800' },
  finalizado: { texto: 'Finalizado', clase: 'bg-green-100 text-green-800' },
  cancelado: { texto: 'Cancelado', clase: 'bg-red-100 text-red-800' },
}

const TIPOS: Record<Pedido['tipo'], string> = {
  local: 'En el local',
  domicilio: 'Domicilio',
  recogida: 'Recogida',
}

// RN-M07-04, RF-M07-016 y RF-M07-R-009 (Fase 1): el Cliente cancela su domicilio o su recogida
// mientras esté PENDIENTE; ver PedidoPolicy::cancelar().
function puedeCancelarPedido(pedido: Pedido) {
  return (pedido.tipo === 'domicilio' || pedido.tipo === 'recogida') && pedido.estado === 'pendiente'
}

// RF-M06-008 (Fase 0, tarea 4): si el último pago en línea fue rechazado y el pedido sigue
// pendiente, el Cliente puede reintentarlo aquí sobre el mismo pedido.
function puedeReintentarPago(pedido: Pedido) {
  const enLinea = (pedido.pagos ?? []).filter((p) => p.metodo === 'en_linea' && !p.anulado)
  return (
    pedido.estado === 'pendiente' &&
    pedido.tipo !== 'local' &&
    enLinea.length > 0 &&
    enLinea.every((p) => p.estado === 'rechazado')
  )
}

const reintentando = ref<number | null>(null)
const errorReintento = ref('')

async function reintentarPago(pedido: Pedido, pasarela: 'nequi' | 'bancolombia') {
  reintentando.value = pedido.id
  errorReintento.value = ''
  try {
    await pedidoService.pagarEnLinea(pedido.id, { pasarela, monto: Number(pedido.total) })
    await cargarPedidos()
  } catch (e) {
    errorReintento.value = mensajeDeError(e, 'No pudimos registrar el pago.')
  } finally {
    reintentando.value = null
  }
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function formatoFecha(valor: string) {
  return new Date(valor).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' })
}

async function cargarPedidos() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await pedidoService.index()
    pedidos.value = data.data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar tus pedidos.')
  } finally {
    cargando.value = false
  }
}

async function alternar(pedido: Pedido) {
  if (abierto.value === pedido.id) {
    abierto.value = null
    return
  }
  abierto.value = pedido.id

  if (!detalleCache.value[pedido.id]) {
    cargandoDetalle.value = pedido.id
    try {
      const { data } = await pedidoService.show(pedido.id)
      detalleCache.value[pedido.id] = data
    } catch {
      // Si falla, se deja el resumen básico que ya se tiene en la lista.
    } finally {
      cargandoDetalle.value = null
    }
  }
}

function abrirCancelarPedido(pedido: Pedido) {
  pedidoACancelar.value = pedido
  motivoCancelacion.value = ''
  errorCancelarPedido.value = ''
}

function cerrarCancelarPedido() {
  pedidoACancelar.value = null
}

async function confirmarCancelarPedido() {
  if (!pedidoACancelar.value) return
  if (!motivoCancelacion.value.trim()) {
    errorCancelarPedido.value = 'Contanos brevemente el motivo.'
    return
  }

  cancelandoPedido.value = true
  errorCancelarPedido.value = ''
  try {
    const { data } = await pedidoService.cancelar(pedidoACancelar.value.id, motivoCancelacion.value.trim())
    const idx = pedidos.value.findIndex((p) => p.id === data.id)
    if (idx !== -1) pedidos.value[idx] = data
    delete detalleCache.value[data.id]
    pedidoACancelar.value = null
  } catch (e) {
    errorCancelarPedido.value = mensajeDeError(e, 'No pudimos cancelar el pedido.')
  } finally {
    cancelandoPedido.value = false
  }
}

// ---- Reservas ----
const reservas = ref<Reserva[]>([])
const cargandoReservas = ref(false)
const reservasCargadas = ref(false)
const errorReservas = ref('')

const reservaACancelar = ref<Reserva | null>(null)
const cancelandoReserva = ref(false)
const errorCancelarReserva = ref('')

const ESTADOS_RESERVA: Record<EstadoReserva, { texto: string; clase: string }> = {
  pendiente_pago: { texto: 'Pendiente de pago', clase: 'bg-amber-100 text-amber-800' },
  confirmada: { texto: 'Confirmada', clase: 'bg-blue-100 text-blue-800' },
  cancelada: { texto: 'Cancelada', clase: 'bg-red-100 text-red-800' },
  no_show: { texto: 'No asistió', clase: 'bg-neutral-200 text-neutral-700' },
  completada: { texto: 'Completada', clase: 'bg-green-100 text-green-800' },
}

// RN-M05-09: "Una reserva puede cancelarse antes de su hora por quien la creó, o por el
// Mesero/Administrador." (ver ReservaPolicy::cancelar() — la autorización fina real vive
// en el backend; esto solo decide si se muestra el botón).
function puedeCancelarReserva(reserva: Reserva) {
  return ['pendiente_pago', 'confirmada'].includes(reserva.estado) && new Date(reserva.fecha_hora) > new Date()
}

async function cargarReservas() {
  cargandoReservas.value = true
  errorReservas.value = ''
  try {
    const { data } = await reservaService.misReservas()
    reservas.value = data.data
  } catch (e) {
    errorReservas.value = mensajeDeError(e, 'No pudimos cargar tus reservas.')
  } finally {
    cargandoReservas.value = false
    reservasCargadas.value = true
  }
}

function abrirCancelarReserva(reserva: Reserva) {
  reservaACancelar.value = reserva
  errorCancelarReserva.value = ''
}

function cerrarCancelarReserva() {
  reservaACancelar.value = null
}

async function confirmarCancelarReserva() {
  if (!reservaACancelar.value) return

  cancelandoReserva.value = true
  errorCancelarReserva.value = ''
  try {
    const { data } = await reservaService.cancelar(reservaACancelar.value.id)
    const idx = reservas.value.findIndex((r) => r.id === data.id)
    if (idx !== -1) reservas.value[idx] = data
    reservaACancelar.value = null
  } catch (e) {
    errorCancelarReserva.value = mensajeDeError(e, 'No pudimos cancelar la reserva.')
  } finally {
    cancelandoReserva.value = false
  }
}

function seleccionarTab(nuevo: 'pedidos' | 'reservas') {
  tab.value = nuevo
  if (nuevo === 'reservas' && !reservasCargadas.value) {
    cargarReservas()
  }
}

const sinNada = computed(() => tab.value === 'pedidos' ? pedidos.value.length === 0 : reservas.value.length === 0)

onMounted(cargarPedidos)
</script>

<template>
  <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">TU HISTORIAL</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink">Mis pedidos y reservas</h1>

    <div class="mt-6 flex gap-2 border-b border-[#e2dbcf]">
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium transition"
        :class="tab === 'pedidos' ? 'border-b-2 border-brand-500 text-ink' : 'text-[#6c655c]'"
        @click="seleccionarTab('pedidos')"
      >
        Pedidos
      </button>
      <button
        type="button"
        class="px-4 py-2 text-sm font-medium transition"
        :class="tab === 'reservas' ? 'border-b-2 border-brand-500 text-ink' : 'text-[#6c655c]'"
        @click="seleccionarTab('reservas')"
      >
        Reservas
      </button>
    </div>

    <!-- ---- Pedidos ---- -->
    <div v-if="tab === 'pedidos'" class="mt-8">
      <div v-if="cargando" class="mt-8 text-center text-sm text-[#6c655c]">Cargando tus pedidos…</div>

      <p v-else-if="error" class="mt-8 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

      <div v-else-if="sinNada" class="mt-8 text-center">
        <img :src="mascota" alt="Mascota de Fragua Real Burger" class="mx-auto w-40 drop-shadow-[0_20px_30px_rgba(0,0,0,0.12)]" />
        <p class="mt-6 font-display text-xl text-ink">Todavía no has pedido nada</p>
        <p class="mt-2 text-sm text-[#6c655c]">Cuando hagas tu primer pedido, lo vas a ver aquí.</p>
        <router-link
          to="/menu"
          class="mt-6 inline-flex items-center justify-center rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
        >
          Ver el menú
        </router-link>
      </div>

      <ul v-else class="space-y-4">
        <li v-for="pedido in pedidos" :key="pedido.id" class="rounded-2xl border border-[#e2dbcf] bg-white">
          <button type="button" class="flex w-full items-center justify-between gap-4 p-5 text-left" @click="alternar(pedido)">
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-700">
                <PackageSearch :size="18" />
              </span>
              <div>
                <p class="font-display text-base text-ink">Pedido #{{ pedido.id }} · {{ TIPOS[pedido.tipo] }}</p>
                <p class="mt-0.5 text-xs text-[#6c655c]">{{ formatoFecha(pedido.created_at) }}</p>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <span class="rounded-full px-3 py-1 text-xs font-medium" :class="ESTADOS[pedido.estado].clase">
                {{ ESTADOS[pedido.estado].texto }}
              </span>
              <span class="hidden text-sm text-ink sm:inline">{{ formatoPrecio(pedido.total) }}</span>
              <ChevronDown :size="18" class="text-[#a39c8f] transition" :class="{ 'rotate-180': abierto === pedido.id }" />
            </div>
          </button>

          <div v-if="abierto === pedido.id" class="border-t border-[#e2dbcf] px-5 py-4">
            <p v-if="cargandoDetalle === pedido.id" class="text-sm text-[#6c655c]">Cargando detalle…</p>
            <template v-else-if="detalleCache[pedido.id]">
              <ul class="space-y-2 text-sm">
                <li
                  v-for="detalle in detalleCache[pedido.id].detalles"
                  :key="detalle.id"
                  class="flex justify-between gap-3 text-[#6c655c]"
                >
                  <span>
                    {{ detalle.cantidad }}× {{ detalle.producto?.nombre ?? detalle.combo?.nombre }}
                    <span v-if="detalle.variante" class="text-xs">({{ detalle.variante.nombre }})</span>
                    <span v-if="detalle.extras?.length" class="block text-xs italic">
                      + {{ detalle.extras.map((e) => e.nombre).join(', ') }}
                    </span>
                  </span>
                  <span class="shrink-0 text-ink">
                    {{ formatoPrecio((Number(detalle.precio_unitario_congelado) + (detalle.extras ?? []).reduce((a, e) => a + Number(e.pivot.precio_congelado), 0)) * detalle.cantidad) }}
                  </span>
                </li>
              </ul>
              <div class="mt-3 space-y-1 border-t border-[#e2dbcf] pt-3 text-sm">
                <!-- Fase 2, tarea 1: subtotal, promociones o descuento aplicados, domicilio y total. -->
                <TotalesPedido :pedido="detalleCache[pedido.id]" tono="cliente" />
                <div v-if="pedido.direccion_entrega" class="text-xs text-[#6c655c]">
                  {{ pedido.direccion_entrega }}<template v-if="pedido.barrio">, {{ pedido.barrio }}</template>
                </div>
              </div>
            </template>

            <div v-if="puedeReintentarPago(pedido)" class="mt-4 border-t border-[#e2dbcf] pt-4">
              <p class="text-xs text-[#6c655c]">Tu pago en línea fue rechazado. Puedes intentarlo de nuevo:</p>
              <p v-if="errorReintento" class="mt-2 alert-error">{{ errorReintento }}</p>
              <div class="mt-2 flex gap-2">
                <button
                  type="button"
                  class="rounded-lg border border-[#e3ded4] px-3 py-1.5 text-xs font-medium text-ink transition hover:bg-[#fbfaf7]"
                  :disabled="reintentando === pedido.id"
                  @click="reintentarPago(pedido, 'nequi')"
                >
                  Pagar con Nequi
                </button>
                <button
                  type="button"
                  class="rounded-lg border border-[#e3ded4] px-3 py-1.5 text-xs font-medium text-ink transition hover:bg-[#fbfaf7]"
                  :disabled="reintentando === pedido.id"
                  @click="reintentarPago(pedido, 'bancolombia')"
                >
                  Pagar con Bancolombia
                </button>
              </div>
            </div>

            <div v-if="puedeCancelarPedido(pedido)" class="mt-4 border-t border-[#e2dbcf] pt-4">
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-50"
                @click="abrirCancelarPedido(pedido)"
              >
                <XCircle :size="14" />
                Cancelar pedido
              </button>
            </div>
          </div>
        </li>
      </ul>
    </div>

    <!-- ---- Reservas ---- -->
    <div v-else class="mt-8">
      <div v-if="cargandoReservas" class="mt-8 text-center text-sm text-[#6c655c]">Cargando tus reservas…</div>

      <p v-else-if="errorReservas" class="mt-8 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorReservas }}</p>

      <div v-else-if="sinNada" class="mt-8 text-center">
        <img :src="mascota" alt="Mascota de Fragua Real Burger" class="mx-auto w-40 drop-shadow-[0_20px_30px_rgba(0,0,0,0.12)]" />
        <p class="mt-6 font-display text-xl text-ink">Todavía no tienes reservas</p>
        <p class="mt-2 text-sm text-[#6c655c]">Cuando reserves una mesa, la vas a ver aquí.</p>
        <router-link
          to="/reservar"
          class="mt-6 inline-flex items-center justify-center rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400"
        >
          Reservar una mesa
        </router-link>
      </div>

      <ul v-else class="space-y-4">
        <li v-for="reserva in reservas" :key="reserva.id" class="rounded-2xl border border-[#e2dbcf] bg-white p-5">
          <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-700">
                <CalendarClock :size="18" />
              </span>
              <div>
                <p class="font-display text-base text-ink">
                  Reserva #{{ reserva.id }}<template v-if="reserva.mesa"> · {{ reserva.mesa.nombre }}</template>
                </p>
                <p class="mt-0.5 text-xs text-[#6c655c]">{{ formatoFecha(reserva.fecha_hora) }}</p>
              </div>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-medium" :class="ESTADOS_RESERVA[reserva.estado].clase">
              {{ ESTADOS_RESERVA[reserva.estado].texto }}
            </span>
          </div>

          <div class="mt-3 flex items-center justify-between border-t border-[#e2dbcf] pt-3 text-sm text-[#6c655c]">
            <span>Depósito: {{ formatoPrecio(reserva.deposito_valor) }}<template v-if="reserva.deposito_reembolsado"> (reembolsado)</template></span>
            <button
              v-if="puedeCancelarReserva(reserva)"
              type="button"
              class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-50"
              @click="abrirCancelarReserva(reserva)"
            >
              <XCircle :size="14" />
              Cancelar reserva
            </button>
          </div>
        </li>
      </ul>
    </div>

    <!-- ---- Modal: cancelar pedido ---- -->
    <div v-if="pedidoACancelar" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
      <div class="w-full max-w-sm rounded-2xl bg-white p-6">
        <h2 class="font-display text-lg text-ink">Cancelar pedido #{{ pedidoACancelar.id }}</h2>
        <p class="mt-1 text-sm text-[#6c655c]">Contanos brevemente por qué cancelas este pedido.</p>
        <textarea
          v-model="motivoCancelacion"
          rows="3"
          class="mt-4 w-full rounded-xl border border-[#e2dbcf] px-3 py-2 text-sm focus:border-brand-500 focus:outline-none"
          placeholder="Motivo de la cancelación"
        />
        <p v-if="errorCancelarPedido" class="mt-2 text-xs text-red-700">{{ errorCancelarPedido }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg px-4 py-2 text-sm text-[#6c655c]" @click="cerrarCancelarPedido">
            Volver
          </button>
          <button
            type="button"
            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition disabled:opacity-60"
            :disabled="cancelandoPedido"
            @click="confirmarCancelarPedido"
          >
            {{ cancelandoPedido ? 'Cancelando…' : 'Sí, cancelar' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ---- Modal: cancelar reserva ---- -->
    <div v-if="reservaACancelar" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4">
      <div class="w-full max-w-sm rounded-2xl bg-white p-6">
        <h2 class="font-display text-lg text-ink">Cancelar reserva #{{ reservaACancelar.id }}</h2>
        <p class="mt-1 text-sm text-[#6c655c]">
          ¿Confirmas que quieres cancelar esta reserva?
          <template v-if="reservaACancelar.deposito_pagado"> El depósito se reembolsa por completo.</template>
        </p>
        <p v-if="errorCancelarReserva" class="mt-2 text-xs text-red-700">{{ errorCancelarReserva }}</p>
        <div class="mt-5 flex justify-end gap-2">
          <button type="button" class="rounded-lg px-4 py-2 text-sm text-[#6c655c]" @click="cerrarCancelarReserva">
            Volver
          </button>
          <button
            type="button"
            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition disabled:opacity-60"
            :disabled="cancelandoReserva"
            @click="confirmarCancelarReserva"
          >
            {{ cancelandoReserva ? 'Cancelando…' : 'Sí, cancelar' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
