<script setup lang="ts">
// M03 — Compras, Gastos y Cierre Diario (exclusivo Administrador). Ver CompraGastoController
// y CierreDiarioService en el backend; RN-M03-01 a 15 en el documento oficial del módulo.
import { computed, onMounted, ref } from 'vue'
import { Ban, Pencil, Plus, Printer, Receipt, ShoppingCart, Wallet } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import StatCard from '@/components/common/StatCard.vue'
import { compraGastoService, type CompraGastoPayload } from '@/services/compra-gasto.service'
import { reporteService } from '@/services/reporte.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import type { CierreDiario, CompraGasto } from '@/types/models'

function hoy() {
  // Fecha local (Bogotá en la práctica) en formato Y-m-d — `fecha` es una columna DATE
  // simple en el backend (RN-M03-14: puede diferir de cuándo se registra), así que no hace
  // falta convertir huso horario, a diferencia de un datetime como Reserva.fecha_hora.
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// ---- Listado ----
const registros = ref<CompraGasto[]>([])
const total = ref(0)
const cargando = ref(true)
const error = ref('')
const filtroTipo = ref<'todos' | 'compra' | 'gasto'>('todos')

const OPCIONES_TIPO: { v: typeof filtroTipo.value; t: string }[] = [
  { v: 'todos', t: 'Todos' },
  { v: 'compra', t: 'Compras' },
  { v: 'gasto', t: 'Gastos' },
]

const METODOS_PAGO: Record<CompraGasto['metodo_pago'], string> = {
  efectivo: 'Efectivo',
  transferencia: 'Transferencia',
  tarjeta: 'Tarjeta',
  otro: 'Otro',
}

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await compraGastoService.index({
      tipo: filtroTipo.value === 'todos' ? undefined : filtroTipo.value,
    })
    registros.value = data.data
    total.value = data.total
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar las compras y gastos.')
  } finally {
    cargando.value = false
  }
}

function reemplazar(actualizado: CompraGasto) {
  const i = registros.value.findIndex((r) => r.id === actualizado.id)
  if (i !== -1) registros.value[i] = actualizado
}

// ---- Resumen del día (reutiliza el reporte de M08 — RN de agregación ya vive ahí) ----
const resumenDia = ref<{ total_compras: number; total_gastos: number } | null>(null)
async function cargarResumenDia() {
  try {
    const { data } = await reporteService.comprasYGastos({ desde: hoy(), hasta: hoy() })
    resumenDia.value = data.reporte.resumen
  } catch {
    resumenDia.value = null // el resumen es informativo; si falla, no bloquea la pantalla
  }
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

// CORREGIDO: aunque `fecha` es una columna DATE (RN-M03-14, sin hora), Eloquent la
// serializa igual como datetime ISO completo (p. ej. "2026-10-01T00:00:00.000000Z") — por
// eso NO se le debe concatenar "T00:00:00" (quedaba "...ZT00:00:00", Invalid Date).
function formatoFecha(valor: string) {
  return new Date(valor).toLocaleDateString('es-CO', { dateStyle: 'medium', timeZone: 'UTC' })
}

// ---- Crear / editar ----
const modalFormAbierto = ref(false)
const editando = ref<CompraGasto | null>(null)
const form = ref<CompraGastoPayload>({
  tipo: 'compra',
  categoria: '',
  descripcion: '',
  valor: 0,
  metodo_pago: 'efectivo',
  proveedor: '',
  numero_factura: '',
  soporte_url: '',
  fecha: hoy(),
})
const erroresForm = ref<Record<string, string>>({})
const errorForm = ref('')
const guardando = ref(false)

function abrirCrear() {
  editando.value = null
  form.value = {
    tipo: 'compra',
    categoria: '',
    descripcion: '',
    valor: 0,
    metodo_pago: 'efectivo',
    proveedor: '',
    numero_factura: '',
    soporte_url: '',
    fecha: hoy(),
  }
  erroresForm.value = {}
  errorForm.value = ''
  modalFormAbierto.value = true
}

function abrirEditar(registro: CompraGasto) {
  editando.value = registro
  form.value = {
    tipo: registro.tipo,
    categoria: registro.categoria ?? '',
    descripcion: registro.descripcion,
    valor: Number(registro.valor),
    metodo_pago: registro.metodo_pago,
    proveedor: registro.proveedor ?? '',
    numero_factura: registro.numero_factura ?? '',
    soporte_url: registro.soporte_url ?? '',
    fecha: registro.fecha,
  }
  erroresForm.value = {}
  errorForm.value = ''
  modalFormAbierto.value = true
}

async function confirmarGuardar() {
  guardando.value = true
  erroresForm.value = {}
  errorForm.value = ''

  const payload: CompraGastoPayload = {
    ...form.value,
    categoria: form.value.categoria || undefined,
    proveedor: form.value.proveedor || undefined,
    numero_factura: form.value.numero_factura || undefined,
    soporte_url: form.value.soporte_url || undefined,
  }

  try {
    if (editando.value) {
      const { data } = await compraGastoService.editar(editando.value.id, payload)
      reemplazar(data)
    } else {
      await compraGastoService.crear(payload)
      await cargar()
    }
    await cargarResumenDia()
    modalFormAbierto.value = false
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresForm.value = campos
    } else {
      errorForm.value = mensajeDeError(e, 'No pudimos guardar el registro.')
    }
  } finally {
    guardando.value = false
  }
}

// ---- Anular ----
const registroAAnular = ref<CompraGasto | null>(null)
const motivoAnular = ref('')
const errorAnular = ref('')
const anulando = ref(false)

function abrirAnular(registro: CompraGasto) {
  registroAAnular.value = registro
  motivoAnular.value = ''
  errorAnular.value = ''
}

async function confirmarAnular() {
  if (!registroAAnular.value) return
  if (!motivoAnular.value.trim()) {
    errorAnular.value = 'El motivo es obligatorio (RN-M03-06).'
    return
  }

  anulando.value = true
  errorAnular.value = ''
  try {
    const { data } = await compraGastoService.anular(registroAAnular.value.id, motivoAnular.value.trim())
    reemplazar(data)
    await cargarResumenDia()
    registroAAnular.value = null
  } catch (e) {
    errorAnular.value = mensajeDeError(e, 'No pudimos anular el registro.')
  } finally {
    anulando.value = false
  }
}

// ---- Cierre diario (RN-M03-09/10/15, HU-M03-08) ----
const fechaCierre = ref(hoy())
const generandoCierre = ref(false)
const errorCierre = ref('')
const ultimoCierre = ref<CierreDiario | null>(null)

async function generarCierre() {
  generandoCierre.value = true
  errorCierre.value = ''
  ultimoCierre.value = null
  try {
    const { data } = await compraGastoService.generarCierreDiario(fechaCierre.value)
    ultimoCierre.value = data
  } catch (e) {
    errorCierre.value = mensajeDeError(e, 'No pudimos generar el cierre diario.')
  } finally {
    generandoCierre.value = false
  }
}

function imprimirCierre() {
  window.print()
}

const contadorTexto = computed(() => (total.value === 1 ? '1 registro' : `${total.value} registros`))

onMounted(() => {
  cargar()
  cargarResumenDia()
})
</script>

<template>
  <div>
    <PageHeader
      eyebrow="FINANZAS"
      titulo="Compras y gastos"
      descripcion="Registro de compras, otros gastos y cierre diario (M03)."
    >
      <template #actions>
        <BaseButton variant="primary" @click="abrirCrear">
          <Plus :size="17" />
          Registrar compra o gasto
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Resumen del día — RN-M03-06/07: totalizar compras y gastos activos del día. -->
    <div class="mt-7 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
      <StatCard etiqueta="Compras de hoy" :valor="formatoPrecio(resumenDia?.total_compras ?? 0)" :icono="ShoppingCart" />
      <StatCard etiqueta="Gastos de hoy" :valor="formatoPrecio(resumenDia?.total_gastos ?? 0)" :icono="Receipt" />
      <StatCard
        class="col-span-2 sm:col-span-1"
        destacada
        etiqueta="Total pagado hoy"
        :valor="formatoPrecio((resumenDia?.total_compras ?? 0) + (resumenDia?.total_gastos ?? 0))"
        :icono="Wallet"
      />
    </div>

    <!-- Cierre diario — HU-M03-08: solo Administrador lo ejecuta; queda consultable. -->
    <div class="card mt-6 p-4 sm:p-5 print:border-none print:p-0 print:shadow-none">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between print:hidden">
        <div>
          <p class="section-title">Cierre diario</p>
          <p class="mt-0.5 text-xs text-gray-500">
            Ventas − compras − gastos del día. No es un arqueo de caja ni la utilidad contable real (RN-M03-09/10).
          </p>
        </div>
        <div class="flex items-end gap-2">
          <label class="block flex-1 sm:flex-none">
            <span class="field-label">Fecha</span>
            <input v-model="fechaCierre" type="date" class="field" />
          </label>
          <BaseButton variant="secondary" :disabled="generandoCierre" @click="generarCierre">
            {{ generandoCierre ? 'Generando…' : 'Generar cierre' }}
          </BaseButton>
        </div>
      </div>

      <p v-if="errorCierre" class="mt-3 alert-error print:hidden">{{ errorCierre }}</p>

      <div v-if="ultimoCierre" class="mt-4 border-t border-gray-200 pt-4">
        <div class="flex items-center justify-between">
          <p class="text-sm font-semibold text-ink">Cierre del {{ formatoFecha(ultimoCierre.fecha) }}</p>
          <button type="button" class="flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-700 print:hidden" @click="imprimirCierre">
            <Printer :size="14" />
            Imprimir
          </button>
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
          <div>
            <dt class="text-xs text-gray-500">Ventas</dt>
            <dd class="font-semibold text-ink">{{ formatoPrecio(ultimoCierre.total_ventas) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500">Compras</dt>
            <dd class="font-semibold text-ink">{{ formatoPrecio(ultimoCierre.total_compras) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500">Gastos</dt>
            <dd class="font-semibold text-ink">{{ formatoPrecio(ultimoCierre.total_gastos) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500">Resultado operativo</dt>
            <dd class="font-semibold" :class="ultimoCierre.resultado_operativo >= 0 ? 'text-emerald-700' : 'text-red-700'">
              {{ formatoPrecio(ultimoCierre.resultado_operativo) }}
            </dd>
          </div>
        </dl>
      </div>
    </div>

    <!-- Listado -->
    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
      <div class="segmented">
        <button
          v-for="opcion in OPCIONES_TIPO"
          :key="opcion.v"
          type="button"
          class="segmented-item"
          :class="filtroTipo === opcion.v ? 'is-active' : ''"
          @click="filtroTipo = opcion.v; cargar()"
        >
          {{ opcion.t }}
        </button>
      </div>
    </div>

    <p v-if="error" class="mt-4 alert-error print:hidden">{{ error }}</p>

    <div v-if="cargando" class="loading-state print:hidden">Cargando registros…</div>

    <div v-else-if="!registros.length" class="mt-10 empty-state print:hidden">
      <Receipt :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm text-gray-500">No hay compras ni gastos en este filtro.</p>
    </div>

    <template v-else>
      <p class="mt-4 text-xs text-gray-500 print:hidden">{{ contadorTexto }}</p>
      <ul class="mt-2 space-y-3 print:hidden">
        <li
          v-for="registro in registros"
          :key="registro.id"
          class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ registro.descripcion }}
              <span
                class="badge"
                :class="registro.tipo === 'compra' ? 'bg-sky-50 text-sky-700 ring-sky-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20'"
              >
                {{ registro.tipo === 'compra' ? 'Compra' : 'Gasto' }}
              </span>
              <span
                class="badge"
                :class="registro.estado === 'activo' ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'"
              >
                {{ registro.estado === 'activo' ? 'Activo' : 'Anulado' }}
              </span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ formatoFecha(registro.fecha) }} · {{ METODOS_PAGO[registro.metodo_pago] }}
              <template v-if="registro.categoria"> · {{ registro.categoria }}</template>
              <template v-if="registro.proveedor"> · {{ registro.proveedor }}</template>
              <template v-if="registro.registrado_por_usuario"> · registrado por {{ registro.registrado_por_usuario.nombre }}</template>
            </p>
            <p v-if="registro.estado === 'anulado' && registro.anulado_motivo" class="mt-1 text-xs italic text-red-600">
              Anulado: {{ registro.anulado_motivo }}
            </p>
          </div>

          <div class="flex shrink-0 items-center justify-between gap-3 border-t border-gray-100 pt-3 sm:justify-end sm:border-0 sm:pt-0">
            <span class="font-display text-xl font-medium text-ink">{{ formatoPrecio(registro.valor) }}</span>
            <div v-if="registro.estado === 'activo'" class="flex gap-1.5">
              <button type="button" class="flex size-10 items-center justify-center rounded-xl border border-gray-200 text-gray-500 transition hover:border-brand-400 hover:bg-brand-50 hover:text-ink" title="Editar" aria-label="Editar" @click="abrirEditar(registro)">
                <Pencil :size="16" />
              </button>
              <button type="button" class="flex size-10 items-center justify-center rounded-xl border border-gray-200 text-red-600 transition hover:border-red-300 hover:bg-red-50" title="Anular" aria-label="Anular" @click="abrirAnular(registro)">
                <Ban :size="16" />
              </button>
            </div>
          </div>
        </li>
      </ul>
    </template>

    <!-- Crear/editar -->
    <BaseModal v-model="modalFormAbierto" :title="editando ? 'Editar registro' : 'Registrar compra o gasto'">
      <form class="space-y-4" @submit.prevent="confirmarGuardar">
        <div>
          <p class="field-label">Tipo</p>
          <div class="grid grid-cols-2 gap-2">
            <button
              type="button"
              class="rounded-xl border px-3 py-3 text-sm font-semibold transition active:scale-[0.98]"
              :class="form.tipo === 'compra' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="form.tipo = 'compra'"
            >
              Compra
            </button>
            <button
              type="button"
              class="rounded-xl border px-3 py-3 text-sm font-semibold transition active:scale-[0.98]"
              :class="form.tipo === 'gasto' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="form.tipo = 'gasto'"
            >
              Gasto
            </button>
          </div>
        </div>

        <label class="block">
          <span class="field-label">Descripción</span>
          <input v-model="form.descripcion" type="text" class="field" />
          <span v-if="erroresForm.descripcion" class="mt-1 block text-xs text-red-600">{{ erroresForm.descripcion }}</span>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Valor</span>
            <input v-model.number="form.valor" type="number" min="0.01" step="0.01" class="field" />
            <span v-if="erroresForm.valor" class="mt-1 block text-xs text-red-600">{{ erroresForm.valor }}</span>
          </label>
          <label class="block">
            <span class="field-label">Fecha</span>
            <input v-model="form.fecha" type="date" class="field" />
            <span v-if="erroresForm.fecha" class="mt-1 block text-xs text-red-600">{{ erroresForm.fecha }}</span>
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Categoría (opcional)</span>
            <input v-model="form.categoria" type="text" class="field" />
          </label>
          <label class="block">
            <span class="field-label">Forma de pago</span>
            <select v-model="form.metodo_pago" class="field">
              <option value="efectivo">Efectivo</option>
              <option value="transferencia">Transferencia</option>
              <option value="tarjeta">Tarjeta</option>
              <option value="otro">Otro</option>
            </select>
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Proveedor (opcional)</span>
            <input v-model="form.proveedor" type="text" class="field" />
          </label>
          <label class="block">
            <span class="field-label">N.º de factura (opcional)</span>
            <input v-model="form.numero_factura" type="text" class="field" />
          </label>
        </div>

        <label class="block">
          <span class="field-label">URL del soporte (opcional)</span>
          <input v-model="form.soporte_url" type="text" placeholder="https://…" class="field" />
          <span class="mt-1 block text-xs text-gray-400">Foto o PDF de la factura/recibo (JPG, PNG o PDF) subido aparte — pega aquí el enlace.</span>
        </label>

        <p v-if="errorForm" class="alert-error">{{ errorForm }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalFormAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardando">
            {{ guardando ? 'Guardando…' : editando ? 'Guardar cambios' : 'Registrar' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>

    <!-- Anular -->
    <BaseModal :model-value="!!registroAAnular" :title="`Anular registro #${registroAAnular?.id ?? ''}`" @update:model-value="registroAAnular = null">
      <div class="space-y-4">
        <p class="text-sm text-gray-600">
          El registro queda marcado como ANULADO y se excluye de los totales — no se elimina (RN-M03-04/05).
        </p>
        <label class="block">
          <span class="field-label">Motivo</span>
          <textarea
            v-model="motivoAnular"
            rows="3"
            class="field"
            placeholder="Motivo de la anulación"
          />
        </label>
        <p v-if="errorAnular" class="alert-error">{{ errorAnular }}</p>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="registroAAnular = null">Volver</BaseButton>
          <BaseButton variant="danger" type="button" :disabled="anulando" @click="confirmarAnular">
            {{ anulando ? 'Anulando…' : 'Sí, anular' }}
          </BaseButton>
        </div>
      </div>
    </BaseModal>
  </div>
</template>
