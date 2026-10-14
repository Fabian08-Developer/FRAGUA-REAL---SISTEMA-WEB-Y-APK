<script setup lang="ts">
// Fase 7, tarea 2 (13/10/2026): el «usuario autorizado» de M03 (RNF-TRANS-012, RF-M03-32,
// HU-M03-01 a 03). Un Mesero con el permiso "Puede registrar compras y gastos" (lo da el
// Administrador en Usuarios) registra lo que paga para el negocio, adjunta la foto o el PDF del
// recibo y ve lo que él registró. Editar, anular y el cierre diario siguen siendo del Administrador
// (Administrador > Compras y gastos). Usa las mismas rutas; el servidor solo le devuelve lo suyo.
import { computed, onMounted, ref } from 'vue'
import { Paperclip, Plus, Receipt, Upload } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { compraGastoService, type CompraGastoPayload } from '@/services/compra-gasto.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import type { CompraGasto } from '@/types/models'

function hoy() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const METODOS_PAGO: Record<CompraGasto['metodo_pago'], string> = {
  efectivo: 'Efectivo',
  transferencia: 'Transferencia',
  tarjeta: 'Tarjeta',
  otro: 'Otro',
}
const UNIDADES = ['unidades', 'kilos', 'gramos', 'litros', 'bolsas', 'paquetes', 'cajas', 'botellas']
const TIPOS_SOPORTE = ['image/jpeg', 'image/png', 'application/pdf']

// ---- Lo que registré ----
const registros = ref<CompraGasto[]>([])
const total = ref(0)
const pagina = ref(1)
const ultimaPagina = ref(1)
const cargando = ref(true)
const error = ref('')
const aviso = ref('')
const categorias = ref<Record<'compra' | 'gasto', string[]>>({ compra: [], gasto: [] })

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await compraGastoService.index({ page: pagina.value })
    registros.value = data.data
    total.value = data.total
    ultimaPagina.value = data.last_page
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar tus compras y gastos.')
  } finally {
    cargando.value = false
  }
}

async function cargarCategorias() {
  try {
    const { data } = await compraGastoService.categorias()
    categorias.value = data
  } catch {
    // Sin la lista, el formulario lo dirá al guardar.
  }
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

function formatoFecha(valor: string) {
  return new Date(valor).toLocaleDateString('es-CO', { dateStyle: 'medium', timeZone: 'UTC' })
}

const contadorTexto = computed(() => (total.value === 1 ? '1 registro' : `${total.value} registros`))

// ---- Registrar ----
const modalAbierto = ref(false)
function formVacio(): CompraGastoPayload {
  return {
    tipo: 'gasto',
    categoria: '',
    descripcion: '',
    cantidad: null,
    unidad: '',
    valor: 0,
    metodo_pago: 'efectivo',
    proveedor: '',
    numero_factura: '',
    observaciones: '',
    fecha: hoy(),
  }
}
const form = ref<CompraGastoPayload>(formVacio())
const soporte = ref<File | null>(null)
const erroresForm = ref<Record<string, string>>({})
const errorForm = ref('')
const guardando = ref(false)

function abrirRegistrar() {
  form.value = formVacio()
  soporte.value = null
  erroresForm.value = {}
  errorForm.value = ''
  modalAbierto.value = true
}

function cambiarTipo(tipo: CompraGastoPayload['tipo']) {
  form.value.tipo = tipo
  if (!categorias.value[tipo].includes(form.value.categoria)) form.value.categoria = ''
}

function elegirSoporte(evento: Event) {
  const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null
  erroresForm.value = { ...erroresForm.value, soporte: '' }
  if (archivo && !TIPOS_SOPORTE.includes(archivo.type)) {
    erroresForm.value = { ...erroresForm.value, soporte: 'El soporte debe ser una imagen JPG o PNG, o un PDF.' }
    soporte.value = null
    ;(evento.target as HTMLInputElement).value = ''
    return
  }
  soporte.value = archivo
}

async function confirmarRegistrar() {
  guardando.value = true
  erroresForm.value = {}
  errorForm.value = ''
  const payload: CompraGastoPayload = {
    ...form.value,
    cantidad: form.value.cantidad ? Number(form.value.cantidad) : null,
    unidad: form.value.unidad || null,
    proveedor: form.value.proveedor || null,
    numero_factura: form.value.numero_factura || null,
    observaciones: form.value.observaciones || null,
  }
  try {
    await compraGastoService.crear(payload, soporte.value)
    modalAbierto.value = false
    aviso.value = `${form.value.tipo === 'compra' ? 'Compra' : 'Gasto'} registrado.`
    pagina.value = 1
    await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) erroresForm.value = campos
    else errorForm.value = mensajeDeError(e, 'No pudimos guardar el registro.')
  } finally {
    guardando.value = false
  }
}

// ---- Soporte: ver o adjuntar después (RF-M03-07, RNF-M03-11) ----
const procesandoSoporte = ref<number | null>(null)

async function verSoporte(registro: CompraGasto) {
  procesandoSoporte.value = registro.id
  error.value = ''
  // La pestaña se abre antes de pedir el archivo para que el navegador no la bloquee.
  const ventana = window.open('', '_blank')
  try {
    const { data } = await compraGastoService.verSoporte(registro.id)
    const url = URL.createObjectURL(data)
    if (ventana) ventana.location.href = url
    else window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 60000)
  } catch (e) {
    ventana?.close()
    error.value = mensajeDeError(e, 'No pudimos abrir el soporte.')
  } finally {
    procesandoSoporte.value = null
  }
}

async function adjuntarSoporte(registro: CompraGasto, evento: Event) {
  const entrada = evento.target as HTMLInputElement
  const archivo = entrada.files?.[0]
  entrada.value = ''
  if (!archivo) return
  error.value = ''
  aviso.value = ''
  if (!TIPOS_SOPORTE.includes(archivo.type)) {
    error.value = 'El soporte debe ser una imagen JPG o PNG, o un PDF.'
    return
  }
  procesandoSoporte.value = registro.id
  try {
    const { data } = await compraGastoService.adjuntarSoporte(registro.id, archivo)
    const i = registros.value.findIndex((r) => r.id === registro.id)
    if (i !== -1) registros.value[i] = { ...registros.value[i], ...data }
    aviso.value = 'Soporte adjuntado.'
  } catch (e) {
    const campos = erroresDeValidacion(e)
    error.value = campos.soporte ?? mensajeDeError(e, 'No pudimos adjuntar el soporte.')
  } finally {
    procesandoSoporte.value = null
  }
}

onMounted(() => {
  cargar()
  cargarCategorias()
})
</script>

<template>
  <div>
    <PageHeader
      eyebrow="CAJA"
      titulo="Compras y gastos"
      descripcion="Registra lo que pagaste para el negocio, con la foto del recibo. Aquí ves lo que tú registraste; para corregir o anular algo, avísale al Administrador."
    >
      <template #actions>
        <BaseButton variant="primary" @click="abrirRegistrar">
          <Plus :size="18" class="mr-1.5" />
          Registrar
        </BaseButton>
      </template>
    </PageHeader>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>
    <p v-if="aviso" class="mt-4 alert-success">{{ aviso }}</p>

    <div v-if="cargando" class="loading-state">Cargando…</div>

    <div v-else-if="!registros.length" class="mt-10 empty-state">
      <Receipt :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm text-gray-500">Todavía no has registrado compras ni gastos.</p>
    </div>

    <template v-else>
      <p class="mt-4 text-xs text-gray-500">{{ contadorTexto }}</p>
      <ul class="mt-2 space-y-3">
        <li v-for="registro in registros" :key="registro.id" class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ registro.descripcion }}
              <span
                class="badge"
                :class="registro.tipo === 'compra' ? 'bg-sky-50 text-sky-700 ring-sky-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20'"
              >
                {{ registro.tipo === 'compra' ? 'Compra' : 'Gasto' }}
              </span>
              <span v-if="registro.estado === 'anulado'" class="badge bg-red-50 text-red-700 ring-red-600/20">Anulado</span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ formatoFecha(registro.fecha) }} · {{ METODOS_PAGO[registro.metodo_pago] }}
              <template v-if="registro.categoria"> · {{ registro.categoria }}</template>
              <template v-if="registro.cantidad"> · {{ Number(registro.cantidad) }} {{ registro.unidad ?? '' }}</template>
              <template v-if="registro.proveedor"> · {{ registro.proveedor }}</template>
            </p>
            <p v-if="registro.estado === 'anulado' && registro.anulado_motivo" class="mt-1 text-xs italic text-red-600">
              Anulado por el Administrador: {{ registro.anulado_motivo }}
            </p>
            <button
              v-if="registro.tiene_soporte"
              type="button"
              class="mt-1.5 inline-flex min-h-9 items-center gap-1.5 text-xs font-semibold text-brand-700 hover:underline"
              :disabled="procesandoSoporte === registro.id"
              @click="verSoporte(registro)"
            >
              <Paperclip :size="14" /> {{ procesandoSoporte === registro.id ? 'Abriendo…' : `Ver soporte (${registro.soporte_nombre ?? 'archivo'})` }}
            </button>
            <label
              v-else-if="registro.estado === 'activo'"
              class="mt-1.5 inline-flex min-h-9 cursor-pointer items-center gap-1.5 text-xs font-semibold text-brand-700 hover:underline"
            >
              <Upload :size="14" />
              {{ procesandoSoporte === registro.id ? 'Subiendo…' : 'Adjuntar foto o PDF del recibo' }}
              <input
                type="file"
                accept="image/jpeg,image/png,application/pdf"
                class="sr-only"
                :disabled="procesandoSoporte === registro.id"
                @change="adjuntarSoporte(registro, $event)"
              />
            </label>
          </div>
          <span class="shrink-0 font-display text-xl font-medium text-ink" :class="{ 'text-gray-400 line-through': registro.estado === 'anulado' }">
            {{ formatoPrecio(registro.valor) }}
          </span>
        </li>
      </ul>

      <div v-if="ultimaPagina > 1" class="mt-6 flex items-center justify-center gap-3">
        <BaseButton variant="ghost" :disabled="pagina <= 1 || cargando" @click="pagina--; cargar()">Anterior</BaseButton>
        <span class="text-xs text-gray-500">{{ pagina }} / {{ ultimaPagina }}</span>
        <BaseButton variant="ghost" :disabled="pagina >= ultimaPagina || cargando" @click="pagina++; cargar()">Siguiente</BaseButton>
      </div>
    </template>

    <BaseModal v-model="modalAbierto" title="Registrar compra o gasto">
      <form class="space-y-4" @submit.prevent="confirmarRegistrar">
        <div>
          <p class="field-label">Tipo</p>
          <div class="grid grid-cols-2 gap-2">
            <button
              type="button"
              class="min-h-12 rounded-xl border px-3 py-3 text-sm font-semibold transition active:scale-[0.98]"
              :class="form.tipo === 'gasto' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="cambiarTipo('gasto')"
            >
              Gasto (transporte, servicios…)
            </button>
            <button
              type="button"
              class="min-h-12 rounded-xl border px-3 py-3 text-sm font-semibold transition active:scale-[0.98]"
              :class="form.tipo === 'compra' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="cambiarTipo('compra')"
            >
              Compra (insumos)
            </button>
          </div>
        </div>

        <label class="block">
          <span class="field-label">Descripción</span>
          <input v-model="form.descripcion" type="text" class="field" placeholder="Ej.: taxi para traer el pan" />
          <span v-if="erroresForm.descripcion" class="mt-1 block text-xs text-red-600">{{ erroresForm.descripcion }}</span>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Valor</span>
            <input v-model.number="form.valor" type="number" min="0.01" step="0.01" inputmode="decimal" class="field" />
            <span v-if="erroresForm.valor" class="mt-1 block text-xs text-red-600">{{ erroresForm.valor }}</span>
          </label>
          <label class="block">
            <span class="field-label">Fecha</span>
            <input v-model="form.fecha" type="date" :max="hoy()" class="field" />
            <span v-if="erroresForm.fecha" class="mt-1 block text-xs text-red-600">{{ erroresForm.fecha }}</span>
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Categoría</span>
            <select v-model="form.categoria" class="field">
              <option value="" disabled>Elige una</option>
              <option v-for="c in categorias[form.tipo]" :key="c" :value="c">{{ c }}</option>
            </select>
            <span v-if="erroresForm.categoria" class="mt-1 block text-xs text-red-600">{{ erroresForm.categoria }}</span>
          </label>
          <label class="block">
            <span class="field-label">Cómo lo pagaste</span>
            <select v-model="form.metodo_pago" class="field">
              <option value="efectivo">Efectivo de la caja</option>
              <option value="transferencia">Transferencia</option>
              <option value="tarjeta">Tarjeta</option>
              <option value="otro">Otro</option>
            </select>
            <span v-if="form.metodo_pago === 'efectivo'" class="mt-1 block text-xs text-gray-500">Se descuenta del efectivo esperado de la caja de hoy.</span>
          </label>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Cantidad (opcional)</span>
            <input v-model.number="form.cantidad" type="number" min="0" step="any" class="field" />
            <span v-if="erroresForm.cantidad" class="mt-1 block text-xs text-red-600">{{ erroresForm.cantidad }}</span>
          </label>
          <label class="block">
            <span class="field-label">Unidad (opcional)</span>
            <input v-model="form.unidad" type="text" list="unidades-mesero" maxlength="50" class="field" placeholder="kilos, bolsas, cajas…" />
            <datalist id="unidades-mesero">
              <option v-for="u in UNIDADES" :key="u" :value="u" />
            </datalist>
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
          <span class="field-label">Observaciones (opcional)</span>
          <textarea v-model="form.observaciones" rows="2" maxlength="1000" class="field" />
        </label>

        <label class="block">
          <span class="field-label">Foto o PDF del recibo (opcional)</span>
          <input
            type="file"
            accept="image/jpeg,image/png,application/pdf"
            class="field file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700"
            @change="elegirSoporte"
          />
          <span class="mt-1 block text-xs text-gray-400">JPG, PNG o PDF de hasta 5 MB. También lo puedes adjuntar después.</span>
          <span v-if="erroresForm.soporte" class="mt-1 block text-xs text-red-600">{{ erroresForm.soporte }}</span>
        </label>

        <p v-if="errorForm" class="alert-error">{{ errorForm }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardando">{{ guardando ? 'Guardando…' : 'Registrar' }}</BaseButton>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
