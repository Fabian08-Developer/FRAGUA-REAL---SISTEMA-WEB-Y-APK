<script setup lang="ts">
// Fase 1, tarea 3 — Cuenta de la mesa (RF-M02-L-019/020, RN-PED-13/14, HU-M02-006): todos los
// pedidos de la visita en curso con su total, lo pagado y el saldo. El Mesero cobra aquí la
// mesa completa, o una parte si el cliente paga con varios métodos (RF-M06-003/011): el
// sistema reparte el pago entre los pedidos, del más antiguo al más nuevo.
import { computed, ref, watch } from 'vue'
import { Banknote, CreditCard, Landmark } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import CampoPesos from '@/components/common/CampoPesos.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import { mesaService, type CuentaMesa } from '@/services/mesa.service'
import { mensajeDeError } from '@/services/errors'
import { formatoHoraPedido, saldoPendiente } from '@/utils/pedidoUi'
import type { Pedido } from '@/types/models'

const props = defineProps<{ mesaId: number | null; puedeCobrar: boolean }>()
const emit = defineEmits<{
  (e: 'cerrar'): void
  (e: 'cobrado', cuenta: CuentaMesa): void
}>()

const cuenta = ref<CuentaMesa | null>(null)
const cargando = ref(false)
const error = ref('')
const aviso = ref('')

type Metodo = 'efectivo' | 'tarjeta' | 'transferencia'
const METODOS: { v: Metodo; t: string; icono: typeof Banknote }[] = [
  { v: 'efectivo', t: 'Efectivo', icono: Banknote },
  { v: 'tarjeta', t: 'Tarjeta', icono: CreditCard },
  { v: 'transferencia', t: 'Transferencia', icono: Landmark },
]
const metodo = ref<Metodo>('efectivo')
const monto = ref(0)
const cobrando = ref(false)

const ESTADOS: Record<Pedido['estado'], string> = {
  pendiente: 'Pendiente',
  confirmado: 'Confirmado',
  preparando: 'Preparando',
  finalizado: 'Finalizado',
  cancelado: 'Cancelado',
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

// Fase 2, tarea 2 (RF-M05-034): parte de lo pagado que es el depósito de la reserva abonado.
const abonoDeposito = computed(() =>
  (cuenta.value?.pedidos ?? [])
    .flatMap((p) => p.pagos ?? [])
    .filter((x) => x.tipo === 'deposito' && !x.anulado && x.estado === 'aprobado')
    .reduce((a, x) => a + Number(x.monto), 0),
)

function nombreLinea(d: NonNullable<Pedido['detalles']>[number]) {
  return d.combo ? `Combo ${d.combo.nombre}` : (d.producto?.nombre ?? 'Producto')
}

watch(
  () => props.mesaId,
  async (id) => {
    cuenta.value = null
    error.value = ''
    aviso.value = ''
    if (!id) return
    cargando.value = true
    try {
      const { data } = await mesaService.cuenta(id)
      cuenta.value = data
      monto.value = data.saldo
      metodo.value = 'efectivo'
    } catch (e) {
      error.value = mensajeDeError(e, 'No pudimos cargar la cuenta de la mesa.')
    } finally {
      cargando.value = false
    }
  },
  { immediate: true },
)

// Lo pagado antes de preparar no finaliza el pedido (RN-PED-32): se avisa qué sigue.
const pendientesDePreparar = computed(
  () => cuenta.value?.pedidos.filter((p) => ['pendiente', 'confirmado'].includes(p.estado)).length ?? 0,
)

async function cobrar() {
  if (!cuenta.value || !props.mesaId) return
  if (!(monto.value > 0)) {
    error.value = 'Ingresa un monto mayor a 0.'
    return
  }
  if (monto.value > cuenta.value.saldo) {
    error.value = `El monto supera lo que debe la mesa (${formatoPrecio(cuenta.value.saldo)}).`
    return
  }
  cobrando.value = true
  error.value = ''
  aviso.value = ''
  try {
    const { data } = await mesaService.cobrar(props.mesaId, { metodo: metodo.value, monto: monto.value })
    cuenta.value = data
    monto.value = data.saldo
    if (data.saldo > 0) aviso.value = `Cobro registrado. Falta ${formatoPrecio(data.saldo)}.`
    else if (!data.abierta) aviso.value = 'Cuenta pagada. Los pedidos quedaron finalizados y la mesa está libre.'
    else aviso.value = 'Cuenta pagada. Los pedidos que aún no se preparan se finalizan desde Pedidos.'
    emit('cobrado', data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos registrar el cobro.')
  } finally {
    cobrando.value = false
  }
}
</script>

<template>
  <BaseModal
    :model-value="!!mesaId"
    :title="cuenta ? `Cuenta — ${cuenta.mesa.nombre}` : 'Cuenta de la mesa'"
    @update:model-value="emit('cerrar')"
  >
    <p v-if="cargando" class="text-sm text-gray-500">Cargando la cuenta…</p>

    <template v-else-if="cuenta">
      <p v-if="!cuenta.pedidos.length" class="rounded-lg bg-gray-50 px-3 py-3 text-sm text-gray-600">
        {{ cuenta.abierta ? 'La mesa está abierta pero todavía no tiene pedidos.' : 'La mesa no tiene una cuenta abierta.' }}
      </p>

      <ul v-else class="space-y-3">
        <li v-for="pedido in cuenta.pedidos" :key="pedido.id" class="rounded-xl border border-gray-200 px-3.5 py-3">
          <div class="flex items-center justify-between gap-3 text-xs text-gray-500">
            <span>#{{ pedido.id }} · {{ formatoHoraPedido(pedido.created_at) }} · {{ ESTADOS[pedido.estado] }}</span>
            <span v-if="saldoPendiente(pedido) === 0" class="font-semibold text-emerald-700">Pagado</span>
            <span v-else class="font-semibold text-ink">Debe {{ formatoPrecio(saldoPendiente(pedido)) }}</span>
          </div>
          <ul class="mt-2 space-y-1 text-sm">
            <li v-for="d in pedido.detalles" :key="d.id" class="flex justify-between gap-3">
              <span class="min-w-0 text-ink">
                {{ d.cantidad }}× {{ nombreLinea(d) }}
                <span v-if="d.variante" class="text-xs text-gray-500">({{ d.variante.nombre }})</span>
                <span v-if="d.extras?.length" class="block text-xs text-gray-500">+ {{ d.extras.map((e) => e.nombre).join(', ') }}</span>
              </span>
            </li>
          </ul>
          <p v-if="Number(pedido.descuento ?? 0) > 0" class="mt-1.5 text-right text-xs text-emerald-700">
            {{ (pedido.promociones_aplicadas ?? []).map((p) => p.nombre).join(' + ') || 'Descuento' }}: −{{ formatoPrecio(pedido.descuento ?? 0) }}
          </p>
          <p class="mt-1.5 text-right text-sm font-semibold text-ink">{{ formatoPrecio(pedido.total) }}</p>
        </li>
      </ul>

      <dl class="mt-4 space-y-1 border-t border-gray-200 pt-3 text-sm">
        <div class="flex justify-between text-gray-600"><dt>Total de la mesa</dt><dd>{{ formatoPrecio(cuenta.total) }}</dd></div>
        <div class="flex justify-between text-gray-600"><dt>Pagado</dt><dd>{{ formatoPrecio(cuenta.pagado) }}</dd></div>
        <p v-if="abonoDeposito > 0" class="text-right text-xs text-emerald-700">Incluye {{ formatoPrecio(abonoDeposito) }} del depósito de la reserva</p>
        <div class="flex items-baseline justify-between pt-1">
          <dt class="text-xs font-semibold uppercase tracking-[0.1em] text-gray-500">Saldo</dt>
          <dd class="font-display text-2xl font-medium text-ink">{{ formatoPrecio(cuenta.saldo) }}</dd>
        </div>
      </dl>

      <p v-if="aviso" class="mt-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ aviso }}</p>
      <p v-if="error" class="mt-3 alert-error">{{ error }}</p>

      <!-- Cobro por mesa: solo el Mesero (RF-M06-003) -->
      <div v-if="puedeCobrar && cuenta.saldo > 0" class="mt-4 border-t border-gray-200 pt-4">
        <p class="field-label">Método de pago</p>
        <div class="grid grid-cols-3 gap-2">
          <button
            v-for="opcion in METODOS"
            :key="opcion.v"
            type="button"
            class="flex flex-col items-center gap-1.5 rounded-xl border px-2 py-3 text-xs font-semibold transition active:scale-[0.97]"
            :class="metodo === opcion.v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
            @click="metodo = opcion.v"
          >
            <component :is="opcion.icono" :size="20" />
            {{ opcion.t }}
          </button>
        </div>
        <label class="mt-4 block">
          <span class="field-label">Monto a cobrar</span>
          <span class="relative block">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex w-10 items-center justify-center text-gray-400">$</span>
            <CampoPesos v-model="monto" class="field pl-9 text-lg font-semibold sm:text-base" />
          </span>
        </label>
        <p class="mt-1.5 text-xs text-gray-400">Para un pago mixto, cobra una parte con cada método.</p>
        <p v-if="pendientesDePreparar" class="mt-2 text-xs text-amber-700">
          {{ pendientesDePreparar }} {{ pendientesDePreparar === 1 ? 'pedido aún no pasa' : 'pedidos aún no pasan' }} a preparación:
          quedan pagados y se finalizan después.
        </p>
        <BaseButton v-escritura class="mt-4 w-full" :disabled="cobrando" @click="cobrar">
          {{ cobrando ? 'Registrando…' : `Cobrar ${formatoPrecio(monto || 0)}` }}
        </BaseButton>
      </div>

      <BaseButton variant="ghost" class="mt-3 w-full" @click="emit('cerrar')">Cerrar</BaseButton>
    </template>

    <p v-else-if="error" class="alert-error">{{ error }}</p>
  </BaseModal>
</template>
