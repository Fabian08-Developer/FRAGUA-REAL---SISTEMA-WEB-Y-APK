<script setup lang="ts">
// Resumen general del Administrador (no es un módulo propio — Cronograma_Fragua_Real.xlsx,
// Semana 2 Lunes). KPIs de "hoy": ventas, pedidos activos y mesas ocupadas — ver
// DashboardController::resumen().
import { computed, onMounted, ref } from 'vue'
import {
  Armchair,
  ArrowUpRight,
  BarChart3,
  ClipboardList,
  Receipt,
  UtensilsCrossed,
  Users,
  Wallet,
} from 'lucide-vue-next'
import PageHeader from '@/components/common/PageHeader.vue'
import StatCard from '@/components/common/StatCard.vue'
import { useAuth } from '@/composables/useAuth'
import { dashboardService } from '@/services/dashboard.service'
import { mensajeDeError } from '@/services/errors'
import type { ResumenDashboard } from '@/services/dashboard.service'

const { usuario } = useAuth()

const resumen = ref<ResumenDashboard | null>(null)
const cargando = ref(true)
const error = ref('')

function formatoPrecio(valor: number) {
  return Number(valor).toLocaleString('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 })
}

const saludo = computed(() => {
  const hora = new Date().getHours()
  const momento = hora < 12 ? 'Buenos días' : hora < 19 ? 'Buenas tardes' : 'Buenas noches'
  const nombre = usuario.value?.nombre?.split(' ')[0]
  return nombre ? `${momento}, ${nombre}` : momento
})

const fechaHoy = computed(() => {
  const texto = new Date().toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' })
  return texto.charAt(0).toUpperCase() + texto.slice(1)
})

// Accesos directos a los módulos que ya existen — en teléfono el menú está oculto detrás
// de la hamburguesa, y desde aquí el Administrador llega a lo de siempre con un toque.
const accesos = [
  { to: '/admin/caja', titulo: 'Caja', detalle: 'Turnos y pagos', icono: Wallet },
  { to: '/admin/catalogo', titulo: 'Catálogo', detalle: 'Productos y combos', icono: UtensilsCrossed },
  { to: '/admin/mesas', titulo: 'Mesas y reservas', detalle: 'Salón y QR', icono: Armchair },
  { to: '/admin/compras-gastos', titulo: 'Compras y gastos', detalle: 'Egresos del local', icono: Receipt },
  { to: '/admin/reportes', titulo: 'Reportes', detalle: 'Ventas y márgenes', icono: BarChart3 },
  { to: '/admin/usuarios', titulo: 'Usuarios', detalle: 'Staff y clientes', icono: Users },
]

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await dashboardService.resumen()
    resumen.value = data
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar el resumen del día.')
  } finally {
    cargando.value = false
  }
}

onMounted(cargar)
</script>

<template>
  <div>
    <PageHeader :eyebrow="fechaHoy.toUpperCase()" :titulo="saludo" descripcion="Así va el día — local, domicilio y recogida." />

    <p v-if="error" class="mt-5 alert-error">{{ error }}</p>

    <div v-if="cargando" class="loading-state">Cargando resumen…</div>

    <div v-else-if="resumen" class="mt-7 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
      <!-- Ventas de hoy — la cifra principal, destacada sobre el negro de marca -->
      <StatCard
        class="col-span-2 lg:col-span-1"
        destacada
        etiqueta="Ventas de hoy"
        :valor="formatoPrecio(resumen.ventas_hoy.monto_total)"
        :detalle="`${resumen.ventas_hoy.cantidad_pedidos} ${resumen.ventas_hoy.cantidad_pedidos === 1 ? 'pedido finalizado' : 'pedidos finalizados'}`"
        :icono="Wallet"
      />

      <StatCard
        etiqueta="Pedidos activos"
        :valor="resumen.pedidos_activos.total"
        :detalle="`${resumen.pedidos_activos.local} local · ${resumen.pedidos_activos.domicilio} domicilio · ${resumen.pedidos_activos.recogida} recogida`"
        :icono="ClipboardList"
      />

      <StatCard
        etiqueta="Mesas ocupadas"
        :valor="`${resumen.mesas.ocupadas} / ${resumen.mesas.total}`"
        detalle="Mesas activas del local."
        :icono="Armchair"
      />
    </div>

    <section class="mt-10">
      <h2 class="section-title">Accesos rápidos</h2>
      <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 lg:gap-4">
        <router-link
          v-for="acceso in accesos"
          :key="acceso.to"
          :to="acceso.to"
          class="card-interactive group flex flex-col gap-3 p-4 sm:p-5"
        >
          <span class="flex items-center justify-between">
            <span
              class="flex size-10 items-center justify-center rounded-xl bg-brand-50 text-brand-700 transition group-hover:bg-brand-500 group-hover:text-ink"
            >
              <component :is="acceso.icono" :size="20" />
            </span>
            <ArrowUpRight
              :size="18"
              class="text-gray-300 transition group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-brand-700"
            />
          </span>
          <span>
            <span class="block text-[15px] font-semibold text-ink">{{ acceso.titulo }}</span>
            <span class="mt-0.5 block text-xs text-gray-500">{{ acceso.detalle }}</span>
          </span>
        </router-link>
      </div>
    </section>
  </div>
</template>
