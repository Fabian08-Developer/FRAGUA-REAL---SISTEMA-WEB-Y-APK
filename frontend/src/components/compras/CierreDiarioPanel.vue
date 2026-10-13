<script setup lang="ts">
// Fase 2, tarea 5 — Cierre diario completo (M03): consulta previa del día con su desglose
// (RF-M03-21 a 26: ventas por tipo y por forma de pago, compras y gastos por categoría y por forma
// de pago, arqueo de la caja), cierre formal solo con la caja del día cerrada (RN-M06-11),
// historial de cierres (RF-M03-27) y, en un día cerrado, sus correcciones autorizadas con los
// valores actualizados (RF-M03-20, RN-M03-15, HU-M03-09). El resultado es estimado, no la
// utilidad contable (RF-M03-34).
// Fase 5, tarea 2 (RF-M03-28/29, HU-M03-11/12): un día cerrado se imprime o se guarda en PDF con la
// plantilla del cierre (con responsables, correcciones y reimpresión marcada y auditada). Un día sin
// cerrar solo se imprime como vista previa desde el navegador, y la hoja lo dice.
import { computed, onMounted, ref } from 'vue'
import { FileText, History, Lock, Printer } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import { compraGastoService } from '@/services/compra-gasto.service'
import { mensajeDeError } from '@/services/errors'
import { abrirPdf } from '@/utils/documentos'
import type { CalculoCierre, CierreDiario, ConsultaCierre, PreviaCierre } from '@/types/models'

const props = defineProps<{ fechaInicial: string }>()

const fecha = ref(props.fechaInicial)
const previa = ref<PreviaCierre | null>(null)
const consulta = ref<ConsultaCierre | null>(null)
const cargando = ref(false)
const cerrando = ref(false)
const error = ref('')
const historial = ref<CierreDiario[]>([])

const METODOS: Record<string, string> = {
  efectivo: 'Efectivo',
  tarjeta: 'Tarjeta',
  transferencia: 'Transferencia',
  en_linea: 'En línea',
  deposito_reserva: 'Depósito de reserva',
  otro: 'Otro',
}
const TIPOS_VENTA: Record<string, string> = { alimento: 'Alimentos', bebida: 'Bebidas', otro: 'Otros' }

// Lo que se muestra: el desglose guardado al cerrar, o el cálculo en vivo si aún no se cierra.
const desglose = computed<CalculoCierre | null>(() => consulta.value?.cierre.detalle ?? consulta.value?.calculo_hoy ?? previa.value)

function formatoPrecio(valor: number | string | null | undefined) {
  return Number(valor ?? 0).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}
function formatoFecha(valor: string) {
  return new Date(valor.length === 10 ? `${valor}T12:00:00` : valor).toLocaleDateString('es-CO', { dateStyle: 'medium', timeZone: 'America/Bogota' })
}
function formatoFechaHora(valor?: string) {
  return valor ? new Date(valor).toLocaleString('es-CO', { timeZone: 'America/Bogota', dateStyle: 'medium', timeStyle: 'short' }) : ''
}

async function cargar() {
  cargando.value = true
  error.value = ''
  consulta.value = null
  try {
    const { data } = await compraGastoService.previaCierre(fecha.value)
    previa.value = data
    if (data.cerrado && data.cierre_id) {
      consulta.value = (await compraGastoService.verCierre(data.cierre_id)).data
    }
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos calcular el cierre.')
  } finally {
    cargando.value = false
  }
}

async function cargarHistorial() {
  try {
    historial.value = (await compraGastoService.cierres()).data.data
  } catch {
    historial.value = []
  }
}

async function cerrarDia() {
  cerrando.value = true
  error.value = ''
  try {
    const { data } = await compraGastoService.generarCierreDiario(fecha.value)
    consulta.value = data
    if (previa.value) previa.value = { ...previa.value, cerrado: true, cierre_id: data.cierre.id, puede_cerrar: false }
    await cargarHistorial()
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cerrar el día.')
  } finally {
    cerrando.value = false
  }
}

// Vista previa de un día sin cerrar: impresión del navegador (no es el cierre formal).
function imprimirVistaPrevia() {
  window.print()
}

// RF-M03-28/29: el cierre formal en PDF (se imprime desde la pestaña que se abre o se guarda).
const abriendoPdf = ref(false)
async function abrirPdfCierre() {
  const cierre = consulta.value?.cierre
  if (!cierre) return
  abriendoPdf.value = true
  error.value = ''
  try {
    await abrirPdf(() => compraGastoService.pdfCierre(cierre.id), 'No pudimos generar el PDF del cierre.')
    cierre.impresiones = (cierre.impresiones ?? 0) + 1
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No pudimos generar el PDF del cierre.'
  } finally {
    abriendoPdf.value = false
  }
}

function abrirDelHistorial(c: CierreDiario) {
  fecha.value = c.fecha.slice(0, 10)
  cargar()
}

// Las compras y gastos registrados o corregidos cambian el cálculo: la vista de compras lo pide.
defineExpose({ recargar: () => Promise.all([cargar(), cargarHistorial()]) })

onMounted(() => {
  cargar()
  cargarHistorial()
})
</script>

<template>
  <div class="card mt-6 p-4 sm:p-5 print:border-none print:p-0 print:shadow-none">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between print:hidden">
      <div>
        <p class="section-title">Cierre diario</p>
        <p class="mt-0.5 text-xs text-gray-500">
          Ventas − compras − otros gastos del día. Es un resultado estimado: no es un arqueo de caja ni la utilidad contable.
        </p>
      </div>
      <form class="flex items-end gap-2" @submit.prevent="cargar">
        <label class="block flex-1 sm:flex-none">
          <span class="field-label">Fecha</span>
          <input v-model="fecha" type="date" class="field" />
        </label>
        <BaseButton variant="secondary" type="submit" :disabled="cargando">{{ cargando ? 'Calculando…' : 'Ver cálculo' }}</BaseButton>
      </form>
    </div>

    <p v-if="error" class="mt-3 alert-error print:hidden">{{ error }}</p>

    <template v-if="desglose && previa">
      <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-4">
        <p class="text-sm font-semibold text-ink">
          {{ consulta ? 'Cierre del' : 'Cálculo del' }} {{ formatoFecha(previa.fecha) }}
          <span v-if="consulta" class="ml-1 badge bg-emerald-50 text-emerald-700 ring-emerald-600/20"><Lock :size="12" class="mr-1 inline" />Cerrado</span>
          <span v-else class="ml-1 badge bg-amber-50 text-amber-700 ring-amber-600/20">Sin cerrar</span>
        </p>
        <div v-if="consulta" class="flex flex-col items-end gap-0.5 print:hidden">
          <BaseButton variant="secondary" size="sm" :disabled="abriendoPdf" @click="abrirPdfCierre">
            <FileText :size="14" />
            {{ abriendoPdf ? 'Generando…' : (consulta.cierre.impresiones ?? 0) > 0 ? 'Reimprimir PDF del cierre' : 'Imprimir / PDF del cierre' }}
          </BaseButton>
          <span v-if="(consulta.cierre.impresiones ?? 0) > 0" class="text-[11px] text-gray-500">
            Ya se imprimió {{ consulta.cierre.impresiones === 1 ? '1 vez' : `${consulta.cierre.impresiones} veces` }}: saldrá marcado «REIMPRESIÓN».
          </span>
        </div>
        <button v-else type="button" class="flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-700 print:hidden" @click="imprimirVistaPrevia">
          <Printer :size="14" /> Imprimir vista previa
        </button>
      </div>
      <p v-if="!consulta" class="mt-2 hidden rounded border border-gray-400 px-2 py-1 text-xs font-semibold print:block">
        VISTA PREVIA — este día todavía no está cerrado; los valores pueden cambiar.
      </p>
      <p v-if="consulta" class="mt-1 text-xs text-gray-500">
        Cerrado por {{ consulta.cierre.cerrado_por_usuario?.nombre ?? '—' }} el {{ formatoFechaHora(consulta.cierre.created_at) }}.
      </p>

      <!-- Totales: los del cierre tal como se hizo, o los del cálculo en vivo. -->
      <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div><dt class="text-xs text-gray-500">Ventas ({{ desglose.ventas.pedidos }} pedidos)</dt><dd class="font-semibold text-ink">{{ formatoPrecio(consulta ? consulta.cierre.total_ventas : desglose.ventas.total) }}</dd></div>
        <div><dt class="text-xs text-gray-500">Compras</dt><dd class="font-semibold text-ink">{{ formatoPrecio(consulta ? consulta.cierre.total_compras : desglose.compras.total) }}</dd></div>
        <div><dt class="text-xs text-gray-500">Otros gastos</dt><dd class="font-semibold text-ink">{{ formatoPrecio(consulta ? consulta.cierre.total_gastos : desglose.gastos.total) }}</dd></div>
        <div>
          <dt class="text-xs text-gray-500">Resultado operativo</dt>
          <dd class="font-semibold" :class="Number(consulta ? consulta.cierre.resultado_operativo : desglose.resultado_operativo) >= 0 ? 'text-emerald-700' : 'text-red-700'">
            {{ formatoPrecio(consulta ? consulta.cierre.resultado_operativo : desglose.resultado_operativo) }}
          </dd>
        </div>
      </dl>
      <p class="mt-2 text-xs italic text-gray-500">{{ desglose.nota }}</p>

      <!-- RN-M03-15, HU-M03-09: correcciones autorizadas después del cierre y valores actualizados. -->
      <div v-if="consulta?.ajustes.length" class="mt-4 rounded-xl bg-amber-50 p-3 text-xs text-amber-900">
        <p class="font-semibold">Correcciones después del cierre</p>
        <ul class="mt-1.5 space-y-1">
          <li v-for="a in consulta.ajustes" :key="a.id">
            {{ formatoFechaHora(a.created_at) }} · {{ a.autorizado_por_usuario?.nombre ?? '—' }} ·
            {{ a.accion === 'registrar' ? 'Registró' : a.accion === 'editar' ? 'Corrigió' : 'Anuló' }}
            "{{ a.compra_gasto?.descripcion ?? `registro #${a.compra_gasto_id}` }}"
            <template v-if="Number(a.delta_compras)"> · compras {{ Number(a.delta_compras) > 0 ? '+' : '' }}{{ formatoPrecio(a.delta_compras) }}</template>
            <template v-if="Number(a.delta_gastos)"> · gastos {{ Number(a.delta_gastos) > 0 ? '+' : '' }}{{ formatoPrecio(a.delta_gastos) }}</template>
            · "{{ a.motivo }}"
          </li>
        </ul>
        <p class="mt-2 font-semibold">
          Valores actualizados: compras {{ formatoPrecio(consulta.actualizado.total_compras) }} · gastos {{ formatoPrecio(consulta.actualizado.total_gastos) }} ·
          resultado {{ formatoPrecio(consulta.actualizado.resultado_operativo) }}
        </p>
      </div>

      <!-- Desglose (RF-M03-22/23/24, RN-M06-12, HU-M03-10). -->
      <div class="mt-4 grid gap-4 text-xs sm:grid-cols-2">
        <section>
          <p class="font-semibold uppercase tracking-wide text-gray-400">Ventas por tipo</p>
          <ul class="mt-1.5 space-y-1">
            <li v-for="t in desglose.ventas.por_tipo" :key="t.tipo_venta" class="flex justify-between"><span>{{ TIPOS_VENTA[t.tipo_venta] ?? t.tipo_venta }}</span><span class="font-medium text-ink">{{ formatoPrecio(t.monto) }}</span></li>
            <li v-if="!desglose.ventas.por_tipo.length" class="text-gray-400">Sin ventas.</li>
          </ul>
        </section>
        <section>
          <p class="font-semibold uppercase tracking-wide text-gray-400">Ventas por forma de pago</p>
          <ul class="mt-1.5 space-y-1">
            <li v-for="m in desglose.ventas.por_metodo" :key="m.metodo" class="flex justify-between"><span>{{ METODOS[m.metodo] ?? m.metodo }}</span><span class="font-medium text-ink">{{ formatoPrecio(m.monto) }}</span></li>
            <li v-if="!desglose.ventas.por_metodo.length" class="text-gray-400">Sin pagos.</li>
          </ul>
        </section>
        <section>
          <p class="font-semibold uppercase tracking-wide text-gray-400">Compras por categoría</p>
          <ul class="mt-1.5 space-y-1">
            <li v-for="c in desglose.compras.por_categoria" :key="c.categoria" class="flex justify-between"><span>{{ c.categoria }} ({{ c.cantidad }})</span><span class="font-medium text-ink">{{ formatoPrecio(c.monto) }}</span></li>
            <li v-if="!desglose.compras.por_categoria.length" class="text-gray-400">Sin compras.</li>
          </ul>
        </section>
        <section>
          <p class="font-semibold uppercase tracking-wide text-gray-400">Otros gastos por categoría</p>
          <ul class="mt-1.5 space-y-1">
            <li v-for="c in desglose.gastos.por_categoria" :key="c.categoria" class="flex justify-between"><span>{{ c.categoria }} ({{ c.cantidad }})</span><span class="font-medium text-ink">{{ formatoPrecio(c.monto) }}</span></li>
            <li v-if="!desglose.gastos.por_categoria.length" class="text-gray-400">Sin gastos.</li>
          </ul>
        </section>
        <section class="sm:col-span-2">
          <p class="font-semibold uppercase tracking-wide text-gray-400">Compras y gastos por forma de pago</p>
          <ul class="mt-1.5 space-y-1">
            <li v-for="f in desglose.compras_gastos_por_forma_de_pago" :key="f.metodo" class="flex justify-between gap-3">
              <span>{{ METODOS[f.metodo] ?? f.metodo }}</span>
              <span class="text-gray-500">compras {{ formatoPrecio(f.compras) }} · gastos {{ formatoPrecio(f.gastos) }} · <span class="font-medium text-ink">{{ formatoPrecio(f.total) }}</span></span>
            </li>
            <li v-if="!desglose.compras_gastos_por_forma_de_pago.length" class="text-gray-400">Sin registros.</li>
          </ul>
        </section>
        <!-- RN-M06-11: el arqueo de la caja alimenta el cierre. -->
        <section class="sm:col-span-2">
          <p class="font-semibold uppercase tracking-wide text-gray-400">Arqueo de caja</p>
          <p v-if="!desglose.caja" class="mt-1.5 text-gray-400">No hubo turno de caja ese día.</p>
          <p v-else class="mt-1.5">
            {{ desglose.caja.estado === 'abierta' ? 'Caja abierta' : 'Caja cerrada' }} · base {{ formatoPrecio(desglose.caja.base_inicial) }} ·
            esperado {{ formatoPrecio(desglose.caja.efectivo_esperado) }}
            <template v-if="desglose.caja.efectivo_contado !== null"> · contado {{ formatoPrecio(desglose.caja.efectivo_contado) }} · diferencia {{ formatoPrecio(desglose.caja.diferencia) }}</template>
            <template v-if="desglose.caja.justificacion"> · "{{ desglose.caja.justificacion }}"</template>
          </p>
        </section>
      </div>

      <!-- Cierre formal (RF-M03-25, HU-M03-08): solo una vez por día y con la caja cerrada. -->
      <div v-if="!consulta" class="mt-5 flex flex-col gap-2 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
        <p class="text-xs text-gray-500">{{ previa.motivo_no_cierra ?? 'El cierre guarda estos valores y su desglose. Después, cualquier corrección de ese día pide tu contraseña y un motivo.' }}</p>
        <BaseButton :disabled="!previa.puede_cerrar || cerrando" @click="cerrarDia">
          <Lock :size="15" /> {{ cerrando ? 'Cerrando…' : 'Cerrar el día' }}
        </BaseButton>
      </div>
    </template>

    <!-- RF-M03-27: historial de cierres. -->
    <details v-if="historial.length" class="mt-5 border-t border-gray-200 pt-3 print:hidden">
      <summary class="flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-gray-600"><History :size="14" /> Cierres anteriores ({{ historial.length }})</summary>
      <ul class="mt-2 divide-y divide-gray-100 text-xs">
        <li v-for="c in historial" :key="c.id">
          <button type="button" class="flex w-full min-h-10 items-center justify-between gap-3 py-1.5 text-left hover:text-ink" @click="abrirDelHistorial(c)">
            <span>{{ formatoFecha(c.fecha.slice(0, 10)) }} · {{ c.cerrado_por_usuario?.nombre ?? '—' }}<template v-if="c.ajustes_count"> · {{ c.ajustes_count }} corrección(es)</template></span>
            <span class="font-medium" :class="Number(c.resultado_operativo) >= 0 ? 'text-emerald-700' : 'text-red-700'">{{ formatoPrecio(c.resultado_operativo) }}</span>
          </button>
        </li>
      </ul>
    </details>
  </div>
</template>
