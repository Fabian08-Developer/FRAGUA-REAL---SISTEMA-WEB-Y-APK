<script setup lang="ts">
// M05 — Reservar mesa con depósito obligatorio (RN-M05-14 a 19). Dos pasos:
// 1) crear la reserva (queda "pendiente_pago"); 2) pagar el depósito en línea
// (Nequi/Bancolombia). El pago en línea hoy lo confirma el Mesero/Administrador
// manualmente (no hay webhook real todavía, ver README-BACKEND.md) — por eso el
// paso 2 termina en "en revisión", no en "confirmada" al instante.
// Fase 1, tarea 4: al elegir fecha y hora, la lista de mesas se reduce a las que no tienen otra
// reserva a esa hora (GET /mesas/disponibles); el servidor vuelve a validarlo al crear.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { CalendarClock, CheckCircle2, Clock, Mail, MessageCircle, Phone, ReceiptText, RotateCcw, Smartphone, User, Users } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import { useAuthStore } from '@/stores/auth'
import { mesaService } from '@/services/mesa.service'
import { configuracionService } from '@/services/configuracion.service'
import { reservaService } from '@/services/reserva.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import { irAlCheckout } from '@/utils/pasarela'
import type { Mesa, Reserva } from '@/types/models'

const auth = useAuthStore()

const mesas = ref<Mesa[]>([])
const depositoValor = ref(20000)
// Fase 4, tarea 2 (RN-M05-07/08/18): minutos de espera antes de liberar la reserva.
const toleranciaMinutos = ref(15)
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
const redirigiendo = ref(false) // Fase 6: saliendo hacia el checkout de la pasarela
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
    toleranciaMinutos.value = config.reserva_tolerancia_minutos ?? 15
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

// Mejora de diseño (16/10/2026): la mesa se elige con botones y el resumen usa su nombre.
const PASARELAS = [
  { v: 'nequi', t: 'Nequi' },
  { v: 'bancolombia', t: 'Botón Bancolombia' },
] as const
const mesaElegida = computed(() => mesas.value.find((m) => m.id === form.mesa_id) ?? null)
// En orden natural: Mesa 1, Mesa 2… Mesa 10 (el servidor no las manda ordenadas).
const mesasOrdenadas = computed(() =>
  [...mesas.value].sort((x, y) => x.nombre.localeCompare(y.nombre, 'es', { numeric: true })),
)
const fechaHoraLegible = computed(() => {
  if (!form.fecha || !form.hora) return ''
  const texto = new Date(`${form.fecha}T${form.hora}`).toLocaleString('es-CO', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    hour: 'numeric',
    minute: '2-digit',
  })
  return texto.charAt(0).toUpperCase() + texto.slice(1)
})
const whatsappUrl = 'https://wa.me/573227259965'

function elegirMesa(id: number) {
  form.mesa_id = id
  if (errores.value.mesa_id) errores.value = { ...errores.value, mesa_id: '' }
}

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

async function confirmarReserva() {
  errores.value = {}
  errorGeneral.value = ''
  // Con botones ya no hay un <select required>: la mesa se valida aquí antes de enviar.
  if (form.mesa_id === '') {
    errores.value = { mesa_id: 'Elige una mesa.' }
    return
  }
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
    // Fase 6, tarea 1 (RF-M05-030): con el enlace secreto que devolvió la creación de la reserva.
    const { data } = await reservaService.registrarDepositoEnLinea(reservaCreada.value.id, {
      pasarela: pasarela.value,
      token: reservaCreada.value.token_publico ?? '',
    })
    // Con la pasarela activa se paga en Wompi y se vuelve por /pago/retorno.
    if (irAlCheckout(data.checkout)) {
      redirigiendo.value = true
      return
    }
    depositoRegistrado.value = true
  } catch (error) {
    errorDeposito.value = mensajeDeError(error, 'No pudimos registrar el pago del depósito. Intenta de nuevo.')
  } finally {
    if (!redirigiendo.value) pagandoDeposito.value = false
  }
}
</script>

<template>
  <!-- Mejora de diseño (16/10/2026): pasos numerados (cuándo, mesa, datos), mesas como botones, un
       panel con el depósito y las condiciones, y altura mínima para que el pie de página no suba
       mientras cargan las mesas. -->
  <div class="mx-auto min-h-[75dvh] max-w-6xl px-4 pb-16 pt-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">RESERVA TU MESA</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink sm:text-5xl">Reservar mesa</h1>
    <p class="mt-3 max-w-2xl text-[15px] text-[#6c655c]">
      Elige el día, la hora y tu mesa. Te la guardamos con un depósito que se descuenta de tu cuenta el día de tu visita.
    </p>

    <div class="mt-8 grid items-start gap-6 lg:grid-cols-[1fr_340px]">
      <!-- Confirmación / pago del depósito -->
      <div v-if="reservaCreada" class="rounded-2xl border border-[#e2dbcf] bg-white p-6 sm:p-8">
        <template v-if="!depositoRegistrado">
          <span class="flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-700">
            <CalendarClock :size="24" />
          </span>
          <h2 class="mt-4 font-display text-2xl text-ink">Reserva #{{ reservaCreada.id }} creada</h2>
          <p class="mt-1 text-sm text-[#6c655c]">Falta un paso: paga el depósito para confirmarla.</p>

          <dl class="mt-5 grid gap-3 rounded-xl bg-[#fbf8f2] p-4 text-sm sm:grid-cols-3">
            <div>
              <dt class="text-[11px] uppercase tracking-[0.08em] text-[#7d756b]">Mesa</dt>
              <dd class="mt-0.5 font-medium text-ink">{{ mesaElegida?.nombre ?? '—' }}</dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-[11px] uppercase tracking-[0.08em] text-[#7d756b]">Cuándo</dt>
              <dd class="mt-0.5 font-medium text-ink">{{ fechaHoraLegible }}</dd>
            </div>
          </dl>

          <p class="mt-6 text-xs font-medium uppercase tracking-[0.08em] text-[#6c655c]">Paga con</p>
          <div class="mt-2 grid gap-3 sm:grid-cols-2">
            <label
              v-for="opcion in PASARELAS"
              :key="opcion.v"
              class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3.5 text-sm transition"
              :class="pasarela === opcion.v ? 'border-brand-500 bg-[#fff7e5] text-ink' : 'border-[#dfd8cd] bg-white text-[#59534c] hover:border-brand-400'"
            >
              <input v-model="pasarela" type="radio" :value="opcion.v" class="accent-brand-600" />
              <Smartphone :size="16" class="text-brand-700" />
              <span class="font-medium">{{ opcion.t }}</span>
            </label>
          </div>

          <p v-if="errorDeposito" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorDeposito }}</p>

          <BaseButton class="mt-6 w-full sm:w-auto" :disabled="pagandoDeposito" @click="pagarDeposito">
            <Smartphone :size="16" />
            {{
              redirigiendo
                ? 'Abriendo la pasarela…'
                : pagandoDeposito
                  ? 'Registrando…'
                  : `Pagar ${formatoPrecio(reservaCreada.deposito_valor)} con ${pasarela === 'nequi' ? 'Nequi' : 'Bancolombia'}`
            }}
          </BaseButton>
        </template>

        <template v-else>
          <span class="flex size-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
            <CheckCircle2 :size="26" />
          </span>
          <h2 class="mt-4 font-display text-2xl text-ink">¡Ya casi! Tu depósito quedó registrado</h2>
          <p class="mt-2 text-sm text-[#6c655c]">
            En breve confirmamos el pago y tu reserva pasa a «Confirmada». Te llega un correo con el enlace para verla o
            cancelarla.
          </p>
          <div class="mt-6 flex flex-col gap-3 sm:flex-row">
            <router-link
              :to="{ name: 'home' }"
              class="inline-flex items-center justify-center rounded-xl border border-[#e3ded4] px-6 py-3 text-sm font-medium text-ink transition hover:bg-[#fbfaf7]"
            >
              Volver al inicio
            </router-link>
            <a
              :href="whatsappUrl"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-sm font-medium text-brand-700 transition hover:bg-brand-50"
            >
              <MessageCircle :size="16" />
              Escríbenos por WhatsApp
            </a>
          </div>
        </template>
      </div>

      <!-- Formulario -->
      <form v-else class="space-y-5" @submit.prevent="confirmarReserva">
        <!-- 1. Cuándo -->
        <section class="rounded-2xl border border-[#e2dbcf] bg-white p-5 sm:p-6">
          <h2 class="flex items-center gap-3 font-display text-xl text-ink">
            <span class="flex size-7 items-center justify-center rounded-full bg-ink font-sans text-xs font-semibold text-brand-400">1</span>
            ¿Cuándo vienes?
          </h2>
          <div class="mt-4 grid grid-cols-2 gap-3">
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
                :class="{ '!border-red-400 !ring-red-100': errores.fecha_hora }"
              />
            </label>
          </div>
          <span v-if="errores.fecha_hora" class="mt-1.5 block text-xs text-red-600">{{ errores.fecha_hora }}</span>
          <p v-else-if="fechaHoraLegible" class="mt-2 text-xs text-[#6c655c]">{{ fechaHoraLegible }}</p>
        </section>

        <!-- 2. Mesa -->
        <section class="rounded-2xl border border-[#e2dbcf] bg-white p-5 sm:p-6">
          <h2 class="flex items-center gap-3 font-display text-xl text-ink">
            <span class="flex size-7 items-center justify-center rounded-full bg-ink font-sans text-xs font-semibold text-brand-400">2</span>
            Elige tu mesa
          </h2>
          <p class="mt-1 text-xs text-[#6c655c]">
            {{ filtradasPorHora ? 'Solo se muestran las mesas libres a la hora que elegiste.' : 'Elige primero la fecha y la hora para ver solo las mesas libres.' }}
          </p>

          <div v-if="cargandoMesas" class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4" aria-hidden="true">
            <span v-for="n in 8" :key="n" class="h-12 animate-pulse rounded-xl bg-[#ece5d9]" />
          </div>
          <p v-else-if="!mesas.length" class="mt-4 rounded-xl bg-[#fbf8f2] px-4 py-3 text-sm text-[#6c655c]">
            {{ filtradasPorHora ? 'No hay mesas libres a esa hora. Prueba con otra hora u otro día.' : 'No hay mesas disponibles para reservar.' }}
          </p>
          <div v-else class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4" role="radiogroup" aria-label="Mesa">
            <button
              v-for="mesa in mesasOrdenadas"
              :key="mesa.id"
              type="button"
              role="radio"
              :aria-checked="form.mesa_id === mesa.id"
              class="rounded-xl border px-3 py-3 text-sm font-medium transition"
              :class="
                form.mesa_id === mesa.id
                  ? 'border-ink bg-ink text-white'
                  : 'border-[#dfd8cd] bg-white text-[#3f3b35] hover:border-brand-500'
              "
              @click="elegirMesa(mesa.id)"
            >
              {{ mesa.nombre }}
            </button>
          </div>
          <span v-if="errores.mesa_id" class="mt-2 block text-xs text-red-600">{{ errores.mesa_id }}</span>
        </section>

        <!-- 3. Datos -->
        <section class="space-y-4 rounded-2xl border border-[#e2dbcf] bg-white p-5 sm:p-6">
          <h2 class="flex items-center gap-3 font-display text-xl text-ink">
            <span class="flex size-7 items-center justify-center rounded-full bg-ink font-sans text-xs font-semibold text-brand-400">3</span>
            Tus datos
          </h2>
          <BaseInput v-model="form.cliente_nombre" label="Nombre" :icon="User" :error="errores.cliente_nombre" />
          <div class="grid gap-4 sm:grid-cols-2">
            <BaseInput v-model="form.cliente_telefono" label="Teléfono" :icon="Phone" :error="errores.cliente_telefono" />
            <BaseInput v-model="form.cliente_correo" label="Correo" :icon="Mail" :error="errores.cliente_correo" />
          </div>
          <p class="text-xs text-[#6c655c]">Te enviamos al correo la confirmación y un enlace para ver o cancelar la reserva.</p>
        </section>

        <p v-if="errorGeneral" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorGeneral }}</p>

        <BaseButton type="submit" :disabled="enviando || cargandoMesas" class="w-full">
          <Users :size="16" />
          {{ enviando ? 'Creando reserva…' : `Continuar y pagar el depósito de ${formatoPrecio(depositoValor)}` }}
        </BaseButton>
      </form>

      <!-- Depósito y condiciones -->
      <aside class="rounded-2xl bg-ink p-6 text-white lg:sticky lg:top-24">
        <p class="text-[11px] font-medium uppercase tracking-[0.14em] text-white/60">Depósito de la reserva</p>
        <p class="mt-1 font-display text-4xl text-brand-400">{{ formatoPrecio(depositoValor) }}</p>
        <ul class="mt-5 space-y-4 text-sm text-white/85">
          <li class="flex gap-3">
            <ReceiptText :size="18" class="mt-0.5 shrink-0 text-brand-400" />
            <span>Se descuenta de tu cuenta el día de tu visita: no es un cobro adicional.</span>
          </li>
          <li class="flex gap-3">
            <RotateCcw :size="18" class="mt-0.5 shrink-0 text-brand-400" />
            <span>Si cancelas antes de la hora, te lo devolvemos completo.</span>
          </li>
          <li class="flex gap-3">
            <Clock :size="18" class="mt-0.5 shrink-0 text-brand-400" />
            <span>Te esperamos hasta {{ toleranciaMinutos }} minutos después de la hora. Si no llegas, la mesa se libera y el depósito no se devuelve.</span>
          </li>
        </ul>
        <a
          :href="whatsappUrl"
          target="_blank"
          rel="noopener"
          class="mt-6 inline-flex items-center gap-2 text-sm font-medium text-brand-400 hover:text-brand-300"
        >
          <MessageCircle :size="16" />
          ¿Dudas? Escríbenos por WhatsApp
        </a>
      </aside>
    </div>
  </div>
</template>
