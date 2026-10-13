<script setup lang="ts">
// M05 — Reservar mesa con depósito obligatorio (RN-M05-14 a 19). Dos pasos:
// 1) crear la reserva (queda "pendiente_pago"); 2) pagar el depósito en línea
// (Nequi/Bancolombia). El pago en línea hoy lo confirma el Mesero/Administrador
// manualmente (no hay webhook real todavía, ver README-BACKEND.md) — por eso el
// paso 2 termina en "en revisión", no en "confirmada" al instante.
// Fase 1, tarea 4: al elegir fecha y hora, la lista de mesas se reduce a las que no tienen otra
// reserva a esa hora (GET /mesas/disponibles); el servidor vuelve a validarlo al crear.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { CalendarClock, CheckCircle2, Mail, Phone, Smartphone, User, Users } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import { useAuthStore } from '@/stores/auth'
import { mesaService } from '@/services/mesa.service'
import { configuracionService } from '@/services/configuracion.service'
import { reservaService } from '@/services/reserva.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import type { Mesa, Reserva } from '@/types/models'

const auth = useAuthStore()

const mesas = ref<Mesa[]>([])
const depositoValor = ref(20000)
const cargandoMesas = ref(true)

const form = reactive({
  mesa_id: '' as number | '',
  fecha: '',
  hora: '',
  cliente_nombre: auth.usuario?.nombre ?? '',
  cliente_telefono: auth.usuario?.telefono ?? '',
  cliente_correo: auth.usuario?.email ?? '',
})

const pasarela = ref<'nequi' | 'bancolombia'>('nequi')

const enviando = ref(false)
const errores = ref<Record<string, string>>({})
const errorGeneral = ref('')

const reservaCreada = ref<Reserva | null>(null)
const pagandoDeposito = ref(false)
const depositoRegistrado = ref(false)
const errorDeposito = ref('')

// No se puede reservar para "ahora mismo" ni para el pasado — mínimo, dentro de 1 hora.
const fechaMinima = computed(() => {
  const min = new Date(Date.now() + 60 * 60 * 1000)
  return min.toISOString().slice(0, 10)
})

onMounted(async () => {
  try {
    const [{ data: listaMesas }, { data: config }] = await Promise.all([
      mesaService.index(),
      configuracionService.obtener(),
    ])
    mesas.value = listaMesas
    depositoValor.value = config.deposito_reserva
  } catch {
    errorGeneral.value = 'No pudimos cargar las mesas disponibles. Recarga la página.'
  } finally {
    cargandoMesas.value = false
  }
})

// Con fecha y hora elegidas, solo las mesas sin otra reserva que se cruce.
const filtradasPorHora = ref(false)
watch(
  () => [form.fecha, form.hora],
  async ([fecha, hora]) => {
    if (!fecha || !hora) return
    cargandoMesas.value = true
    try {
      const { data } = await reservaService.mesasDisponibles(`${fecha} ${hora}`)
      mesas.value = data
      filtradasPorHora.value = true
      if (form.mesa_id !== '' && !data.some((m) => m.id === form.mesa_id)) form.mesa_id = ''
    } catch {
      // Si falla, queda la lista anterior; el servidor rechaza la reserva si la mesa ya está tomada.
    } finally {
      cargandoMesas.value = false
    }
  },
)

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

async function confirmarReserva() {
  errores.value = {}
  errorGeneral.value = ''
  enviando.value = true

  try {
    const { data } = await reservaService.crear({
      mesa_id: Number(form.mesa_id),
      cliente_nombre: form.cliente_nombre,
      cliente_telefono: form.cliente_telefono,
      cliente_correo: form.cliente_correo,
      fecha_hora: `${form.fecha}T${form.hora}`,
    })
    reservaCreada.value = data
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) {
      errores.value = campos
    } else {
      errorGeneral.value = mensajeDeError(error, 'No pudimos crear la reserva. Intenta de nuevo.')
    }
  } finally {
    enviando.value = false
  }
}

async function pagarDeposito() {
  if (!reservaCreada.value) return
  errorDeposito.value = ''
  pagandoDeposito.value = true
  try {
    await reservaService.registrarDepositoEnLinea(reservaCreada.value.id, { pasarela: pasarela.value })
    depositoRegistrado.value = true
  } catch (error) {
    errorDeposito.value = mensajeDeError(error, 'No pudimos registrar el pago del depósito. Intenta de nuevo.')
  } finally {
    pagandoDeposito.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">RESERVA TU MESA</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink">Reservar mesa</h1>
    <p class="mt-2 text-sm text-[#6c655c]">
      Toda reserva requiere un depósito de {{ formatoPrecio(depositoValor) }}, que se descuenta de tu cuenta el día
      de tu visita. Si cancelas a tiempo, te lo devolvemos completo.
    </p>

    <!-- Confirmación / pago del depósito -->
    <div v-if="reservaCreada" class="mt-8 rounded-2xl border border-[#e2dbcf] bg-white p-8">
      <template v-if="!depositoRegistrado">
        <CalendarClock :size="40" class="text-brand-600" />
        <h2 class="mt-4 font-display text-2xl text-ink">Reserva #{{ reservaCreada.id }} creada</h2>
        <p class="mt-2 text-sm text-[#6c655c]">
          Para confirmarla, paga el depósito de {{ formatoPrecio(reservaCreada.deposito_valor) }} en línea.
        </p>

        <div class="mt-5 flex gap-3">
          <label class="flex items-center gap-2 text-sm text-ink">
            <input v-model="pasarela" type="radio" value="nequi" />
            Nequi
          </label>
          <label class="flex items-center gap-2 text-sm text-ink">
            <input v-model="pasarela" type="radio" value="bancolombia" />
            Bancolombia
          </label>
        </div>

        <p v-if="errorDeposito" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorDeposito }}</p>

        <BaseButton class="mt-5" :disabled="pagandoDeposito" @click="pagarDeposito">
          <Smartphone :size="16" />
          {{ pagandoDeposito ? 'Registrando…' : `Pagar depósito con ${pasarela === 'nequi' ? 'Nequi' : 'Bancolombia'}` }}
        </BaseButton>
      </template>

      <template v-else>
        <CheckCircle2 :size="48" class="text-brand-600" />
        <h2 class="mt-4 font-display text-2xl text-ink">¡Ya casi! Tu depósito quedó registrado</h2>
        <p class="mt-2 text-sm text-[#6c655c]">
          En breve confirmamos el pago y tu reserva pasa a "Confirmada". Si tienes dudas, escríbenos por WhatsApp.
        </p>
        <router-link
          :to="{ name: 'home' }"
          class="mt-6 inline-flex items-center justify-center rounded-xl border border-[#e3ded4] px-6 py-3 text-sm font-medium text-ink transition hover:bg-[#fbfaf7]"
        >
          Volver al inicio
        </router-link>
      </template>
    </div>

    <!-- Formulario -->
    <form v-else class="mt-8 space-y-6" @submit.prevent="confirmarReserva">
      <section class="grid gap-4 sm:grid-cols-2">
        <label class="block">
          <span class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">Mesa</span>
          <select
            v-model="form.mesa_id"
            required
            class="w-full rounded-xl border border-[#e3ded4] bg-[#fbfaf7] px-4 py-3 text-sm text-ink transition focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-brand-500/15"
            :class="{ '!border-red-400 !ring-red-100': errores.mesa_id }"
          >
            <option value="" disabled>
              {{ cargandoMesas ? 'Cargando mesas…' : filtradasPorHora && !mesas.length ? 'No hay mesas libres a esa hora' : 'Elige una mesa' }}
            </option>
            <option v-for="mesa in mesas" :key="mesa.id" :value="mesa.id">{{ mesa.nombre }}</option>
          </select>
          <span v-if="errores.mesa_id" class="mt-1.5 block text-xs text-red-600">{{ errores.mesa_id }}</span>
          <span v-else-if="filtradasPorHora" class="mt-1.5 block text-xs text-[#6c655c]">Mesas libres a la hora que elegiste.</span>
        </label>

        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">Fecha</span>
            <input
              v-model="form.fecha"
              type="date"
              required
              :min="fechaMinima"
              class="w-full rounded-xl border border-[#e3ded4] bg-[#fbfaf7] px-4 py-3 text-sm text-ink transition focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-brand-500/15"
              :class="{ '!border-red-400 !ring-red-100': errores.fecha_hora }"
            />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-[#6c655c]">Hora</span>
            <input
              v-model="form.hora"
              type="time"
              required
              class="w-full rounded-xl border border-[#e3ded4] bg-[#fbfaf7] px-4 py-3 text-sm text-ink transition focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-brand-500/15"
            />
          </label>
        </div>
        <span v-if="errores.fecha_hora" class="-mt-3 block text-xs text-red-600 sm:col-span-2">{{ errores.fecha_hora }}</span>
      </section>

      <section class="space-y-4">
        <h2 class="font-display text-lg text-ink">Datos de contacto</h2>
        <BaseInput v-model="form.cliente_nombre" label="Nombre" :icon="User" :error="errores.cliente_nombre" />
        <div class="grid gap-4 sm:grid-cols-2">
          <BaseInput v-model="form.cliente_telefono" label="Teléfono" :icon="Phone" :error="errores.cliente_telefono" />
          <BaseInput v-model="form.cliente_correo" label="Correo" :icon="Mail" :error="errores.cliente_correo" />
        </div>
      </section>

      <p v-if="errorGeneral" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorGeneral }}</p>

      <BaseButton type="submit" :disabled="enviando || cargandoMesas" class="w-full">
        <Users :size="16" />
        {{ enviando ? 'Creando reserva…' : 'Continuar y pagar depósito' }}
      </BaseButton>
    </form>
  </div>
</template>
