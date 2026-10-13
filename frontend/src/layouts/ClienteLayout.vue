<script setup lang="ts">
import { computed, ref } from 'vue'
import { LayoutDashboard, LogOut, MapPin, Menu, MessageCircle, PackageSearch, ShoppingBag, User, X } from 'lucide-vue-next'
import { useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import { useCarritoStore } from '@/stores/carrito'

// Layout público/Cliente: Home, Menú (M04), Carrito/Checkout (M02/M07),
// Reserva (M05), Mis pedidos y Perfil (M01). Header calcado del Figma
// "Fragua Real Diseños V1" (Home V2 / Menu V2): logo + nombre + nav +
// botón "Mi pedido" con el conteo de líneas del carrito.
//
// CORREGIDO (28/09/2026): Perfil (RF-M01) también vive en este layout para
// Mesero/Administrador (no solo Cliente) — pero antes, un Mesero/Admin que
// llegaba aquí (por ejemplo a /perfil) no tenía forma de volver a su panel
// ni de cerrar sesión, porque ese botón solo existía en AppSidebar
// (Admin/MeseroLayout). Se agrega "Mi panel" + "Cerrar sesión" aquí también.
//
// RESPONSIVO (28/09/2026): la nav de texto y los botones de cuenta solo
// vivían a partir de sm/md, pero no había ningún reemplazo para pantallas
// angostas — un Cliente en /carrito, /checkout, /perfil, etc. no tenía forma
// de llegar a Menú/Reservar desde el header (solo el logo o el footer, hasta
// abajo de la página). Se agrega un menú "drawer" (mismo patrón que
// AppSidebar) por debajo de md, y la nav + los botones de cuenta se juntan a
// partir de ese mismo breakpoint para no dejar un rango intermedio sin nada.
const { estaAutenticado, rol, logout } = useAuth()
const carrito = useCarritoStore()
const router = useRouter()

const rutaPanel = computed(() => (rol.value === 'administrador' ? '/admin' : rol.value === 'mesero' ? '/mesero' : null))

const menuAbierto = ref(false)

function cerrarMenu() {
  menuAbierto.value = false
}

async function cerrarSesion() {
  menuAbierto.value = false
  await logout()
  router.push('/')
}

const enlaces = [
  { to: '/', texto: 'Inicio' },
  { to: '/menu', texto: 'Menú' },
  { to: '/reservar', texto: 'Reservar' },
  { to: { path: '/', hash: '#contacto' }, texto: 'Contacto' },
]

// Mismos datos de contacto reales que usa el Home (no se inventan) —
// se repiten aquí porque el footer, a diferencia del header, vive en
// todas las páginas del layout Cliente, incluidas las que no muestran
// la sección de contacto del Home.
const direccion = 'Esquina frente al malecón turístico, etapa 1.'
const telefono = '322 725 9965'
const whatsappUrl = `https://wa.me/57${telefono.replace(/\s+/g, '')}`
const mapaUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(direccion)}`
const anioActual = new Date().getFullYear()
</script>

<template>
  <div class="flex min-h-screen flex-col bg-cream">
    <header class="sticky top-0 z-20 border-b border-[#e9e3d8] bg-white">
      <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3 sm:gap-6 sm:px-6">
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="flex size-9 shrink-0 items-center justify-center rounded-lg text-ink hover:bg-cream md:hidden"
            aria-label="Abrir menú"
            @click="menuAbierto = true"
          >
            <Menu :size="20" />
          </button>

          <router-link to="/" class="flex items-center gap-3">
            <span
              class="flex size-11 items-center justify-center rounded-full bg-ink text-brand-500"
              aria-hidden="true"
            >
              <span class="font-display text-lg">FR</span>
            </span>
            <span class="flex flex-col leading-tight">
              <span class="font-display text-lg font-medium text-ink">Fragua Real</span>
              <span class="text-[10px] font-medium tracking-[2.6px] text-brand-700">BURGER</span>
            </span>
          </router-link>
        </div>

        <nav class="hidden items-center gap-8 text-sm font-medium text-[#625e57] md:flex">
          <router-link
            v-for="enlace in enlaces"
            :key="enlace.texto"
            :to="enlace.to"
            class="border-b-2 border-transparent py-1 transition hover:text-ink"
            active-class="border-brand-500 text-ink"
            exact-active-class="border-brand-500 text-ink"
          >
            {{ enlace.texto }}
          </router-link>
        </nav>

        <div class="flex items-center gap-3">
          <!-- Mesero/Administrador: volver a su panel — están viendo estas páginas
               porque Perfil (RF-M01) también vive en este layout, no porque este
               sea "su" sitio. -->
          <router-link
            v-if="rutaPanel"
            :to="rutaPanel"
            class="hidden rounded-full border border-[#ddd7cd] px-4 py-2.5 text-sm font-medium text-ink transition hover:border-brand-500 md:block"
          >
            Mi panel
          </router-link>

          <!-- "Mis pedidos y reservas" (HU-M05/M07): único acceso persistente, antes solo
               se llegaba desde la pantalla de confirmación del checkout. -->
          <router-link
            v-if="rol === 'cliente'"
            :to="{ name: 'cliente-pedidos' }"
            class="hidden size-10 items-center justify-center rounded-full border border-[#ddd7cd] text-ink transition hover:bg-cream md:flex"
            title="Mis pedidos y reservas"
          >
            <PackageSearch :size="18" />
          </router-link>

          <router-link
            :to="estaAutenticado ? '/perfil' : '/login'"
            class="hidden size-10 items-center justify-center rounded-full border border-[#ddd7cd] text-ink transition hover:bg-cream md:flex"
            :title="estaAutenticado ? 'Mi perfil' : 'Ingresar'"
          >
            <User :size="18" />
          </router-link>

          <button
            v-if="estaAutenticado"
            type="button"
            class="hidden size-10 items-center justify-center rounded-full border border-[#ddd7cd] text-ink transition hover:border-red-300 hover:bg-red-50 hover:text-red-700 md:flex"
            title="Cerrar sesión"
            @click="cerrarSesion"
          >
            <LogOut :size="18" />
          </button>
          <router-link
            to="/carrito"
            class="flex items-center gap-2 rounded-full border border-[#ddd7cd] bg-white px-4 py-2.5 text-sm font-medium text-ink transition hover:border-brand-500"
          >
            <ShoppingBag :size="16" />
            <span class="hidden sm:inline">Mi pedido</span>
            <span
              class="flex size-5 items-center justify-center rounded-full bg-brand-500 text-[11px] font-medium text-ink"
            >
              {{ carrito.totalProductos }}
            </span>
          </router-link>
        </div>
      </div>
    </header>

    <!-- Menú móvil: nav + cuenta, por debajo de md (mismo patrón "drawer" que AppSidebar). -->
    <Transition
      enter-active-class="transition-opacity duration-200"
      leave-active-class="transition-opacity duration-150"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div v-if="menuAbierto" class="fixed inset-0 z-30 bg-black/40 md:hidden" @click="cerrarMenu" />
    </Transition>

    <aside
      class="fixed inset-y-0 left-0 z-40 flex h-screen w-72 max-w-[85vw] flex-col bg-white transition-transform duration-200 ease-out md:hidden"
      :class="menuAbierto ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="flex items-center justify-between border-b border-[#e9e3d8] px-4 py-5">
        <router-link to="/" class="flex items-center gap-3" @click="cerrarMenu">
          <span class="flex size-10 items-center justify-center rounded-full bg-ink text-brand-500" aria-hidden="true">
            <span class="font-display text-base">FR</span>
          </span>
          <span class="flex flex-col leading-tight">
            <span class="font-display text-base font-medium text-ink">Fragua Real</span>
            <span class="text-[9px] font-medium tracking-[2.4px] text-brand-700">BURGER</span>
          </span>
        </router-link>
        <button
          type="button"
          class="flex size-8 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600"
          aria-label="Cerrar menú"
          @click="cerrarMenu"
        >
          <X :size="18" />
        </button>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-2 py-3">
        <router-link
          v-for="enlace in enlaces"
          :key="enlace.texto"
          :to="enlace.to"
          class="flex items-center rounded-lg px-3 py-2.5 text-sm font-medium text-[#3f3b35] hover:bg-brand-50 hover:text-brand-700"
          active-class="bg-brand-50 text-brand-700"
          exact-active-class="bg-brand-50 text-brand-700"
          @click="cerrarMenu"
        >
          {{ enlace.texto }}
        </router-link>
      </nav>

      <div class="space-y-1 border-t border-[#e9e3d8] p-2">
        <router-link
          v-if="rutaPanel"
          :to="rutaPanel"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-[#3f3b35] hover:bg-gray-100"
          @click="cerrarMenu"
        >
          <LayoutDashboard :size="18" />
          Mi panel
        </router-link>
        <router-link
          v-if="rol === 'cliente'"
          :to="{ name: 'cliente-pedidos' }"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-[#3f3b35] hover:bg-gray-100"
          @click="cerrarMenu"
        >
          <PackageSearch :size="18" />
          Mis pedidos y reservas
        </router-link>
        <router-link
          :to="estaAutenticado ? '/perfil' : '/login'"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-[#3f3b35] hover:bg-gray-100"
          @click="cerrarMenu"
        >
          <User :size="18" />
          {{ estaAutenticado ? 'Mi perfil' : 'Ingresar' }}
        </router-link>
        <button
          v-if="estaAutenticado"
          type="button"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-[#3f3b35] hover:bg-red-50 hover:text-red-700"
          @click="cerrarSesion"
        >
          <LogOut :size="18" />
          Cerrar sesión
        </button>
      </div>
    </aside>

    <main class="flex-1">
      <router-view />
    </main>

    <footer class="border-t border-white/10 bg-ink text-white">
      <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6">
        <div class="grid gap-10 text-center sm:grid-cols-2 sm:text-left lg:grid-cols-[1.3fr_1fr_1fr]">
          <div class="flex flex-col items-center sm:items-start">
            <router-link to="/" class="flex items-center gap-3">
              <span class="flex size-9 items-center justify-center rounded-full bg-white/10 text-brand-500" aria-hidden="true">
                <span class="font-display text-base">FR</span>
              </span>
              <span class="flex flex-col leading-tight">
                <span class="font-display text-base font-medium text-white">Fragua Real</span>
                <span class="text-[9px] font-medium tracking-[2.4px] text-brand-500">BURGER</span>
              </span>
            </router-link>
            <p class="mt-4 max-w-xs text-sm leading-relaxed text-[#c7c3bc]">
              Hamburguesas, perros y mucho más, preparados al momento. Pide a domicilio, recoge en el local o
              reserva tu mesa.
            </p>
            <a
              :href="whatsappUrl"
              target="_blank"
              rel="noopener"
              class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-medium text-ink transition hover:bg-brand-400"
            >
              <MessageCircle :size="16" />
              Escribir por WhatsApp
            </a>
          </div>

          <div>
            <p class="text-[11px] font-medium tracking-[2px] text-brand-500">EXPLORAR</p>
            <nav class="mt-4 flex flex-col gap-3 text-sm text-[#c7c3bc]">
              <router-link
                v-for="enlace in enlaces"
                :key="enlace.texto"
                :to="enlace.to"
                class="transition hover:text-white"
              >
                {{ enlace.texto }}
              </router-link>
              <router-link to="/carrito" class="transition hover:text-white">Mi pedido</router-link>
            </nav>
          </div>

          <div>
            <p class="text-[11px] font-medium tracking-[2px] text-brand-500">CONTACTO</p>
            <div class="mt-4 flex flex-col items-center gap-4 sm:items-start">
              <a
                :href="mapaUrl"
                target="_blank"
                rel="noopener"
                class="flex items-start gap-2.5 text-sm text-[#c7c3bc] transition hover:text-white"
              >
                <MapPin :size="17" class="mt-0.5 shrink-0 text-brand-500" />
                <span>{{ direccion }}</span>
              </a>
              <a
                :href="whatsappUrl"
                target="_blank"
                rel="noopener"
                class="flex items-center gap-2.5 text-sm text-[#c7c3bc] transition hover:text-white"
              >
                <MessageCircle :size="17" class="shrink-0 text-brand-500" />
                <span>{{ telefono }}</span>
              </a>
            </div>
          </div>
        </div>

        <div class="mt-12 flex flex-col items-center gap-3 border-t border-white/10 pt-6 text-center sm:flex-row sm:justify-between sm:text-left">
          <p class="text-sm text-[#c7c3bc]">El antojo tiene corona.</p>
          <p class="text-xs text-[#8b8579]">© {{ anioActual }} Fragua Real Burger. Todos los derechos reservados.</p>
        </div>
      </div>
    </footer>
  </div>
</template>
