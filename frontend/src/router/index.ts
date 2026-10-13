import { watch } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { Rol } from '@/types/models'

// Espera a que termine la primera carga de sesión (auth.listo — ver stores/auth.ts)
// antes de dejar seguir la navegación. Sin esto, el primer beforeEach (que Vue Router
// dispara desde app.use(router) en main.ts, antes de que exista tiempo para que
// cargarUsuarioActual() resuelva) leería auth.rol todavía en null.
function esperarSesionLista(auth: ReturnType<typeof useAuthStore>) {
  if (auth.listo) return Promise.resolve()
  return new Promise<void>((resolve) => {
    const detener = watch(
      () => auth.listo,
      (listo) => {
        if (listo) {
          detener()
          resolve()
        }
      },
    )
  })
}

const router = createRouter({
  history: createWebHistory(),
  // Permite enlaces con ancla (p. ej. el "Contacto" del header, que apunta a
  // #contacto en el Home) y deja el scroll donde estaba al usar atrás/adelante.
  scrollBehavior(to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.hash) return { el: to.hash, behavior: 'smooth' }
    return { top: 0 }
  },
  routes: [
    // RF-M02-L-025 a 028: pedido por QR — página propia, sin ClienteLayout (sin nav,
    // login ni footer: el Cliente llegó escaneando el papel de la mesa, no navegando
    // el sitio). Antes de la ruta comodín de abajo, pero fuera de los demás grupos.
    {
      path: '/mesa/:qr_code',
      name: 'mesa-qr',
      component: () => import('@/views/cliente/MesaQrView.vue'),
    },

    // ---- Público / Cliente (M02, M04, M05, M07) ----
    {
      path: '/',
      component: () => import('@/layouts/ClienteLayout.vue'),
      children: [
        { path: '', name: 'home', component: () => import('@/views/HomeView.vue') },
        { path: 'menu', name: 'menu', component: () => import('@/views/cliente/MenuView.vue') },
        { path: 'carrito', name: 'carrito', component: () => import('@/views/cliente/CarritoView.vue') },
        { path: 'checkout', name: 'checkout', component: () => import('@/views/cliente/CheckoutView.vue') },
        { path: 'reservar', name: 'reservar', component: () => import('@/views/cliente/ReservaView.vue') },
        // Fase 1, tarea 6 (RF-M07-027, RF-M07-R-015): seguimiento del invitado con su enlace privado.
        {
          path: 'seguimiento/:token',
          name: 'seguimiento-pedido',
          component: () => import('@/views/cliente/SeguimientoView.vue'),
        },
        {
          path: 'pedidos',
          name: 'cliente-pedidos',
          component: () => import('@/views/cliente/PedidosView.vue'),
          meta: { requiereAuth: true, roles: ['cliente'] as Rol[] },
        },
        {
          path: 'perfil',
          name: 'perfil',
          component: () => import('@/views/cliente/PerfilView.vue'),
          meta: { requiereAuth: true, roles: ['cliente', 'administrador', 'mesero'] as Rol[] },
        },
      ],
    },

    // ---- Auth (M01) ----
    {
      path: '/',
      component: () => import('@/layouts/AuthLayout.vue'),
      meta: { soloInvitado: true },
      children: [
        { path: 'login', name: 'login', component: () => import('@/views/auth/LoginView.vue') },
        { path: 'registro', name: 'registro', component: () => import('@/views/auth/RegisterView.vue') },
        {
          path: 'recuperar-password',
          name: 'recuperar-password',
          component: () => import('@/views/auth/RecuperarPasswordView.vue'),
        },
        {
          path: 'restablecer-password',
          name: 'restablecer-password',
          component: () => import('@/views/auth/RestablecerPasswordView.vue'),
        },
      ],
    },

    // ---- Administrador (M01, M03-M06, M08) ----
    {
      path: '/admin',
      component: () => import('@/layouts/AdminLayout.vue'),
      meta: { requiereAuth: true, roles: ['administrador'] as Rol[] },
      children: [
        { path: '', name: 'admin-dashboard', component: () => import('@/views/admin/DashboardView.vue') },
        { path: 'usuarios', name: 'admin-usuarios', component: () => import('@/views/admin/UsuariosView.vue') },
        { path: 'pedidos', name: 'admin-pedidos', component: () => import('@/views/admin/PedidosView.vue') },
        {
          path: 'compras-gastos',
          name: 'admin-compras-gastos',
          component: () => import('@/views/admin/ComprasGastosView.vue'),
        },
        { path: 'catalogo', name: 'admin-catalogo', component: () => import('@/views/admin/CatalogoView.vue') },
        // Fase 1, tarea 5: la misma pantalla de disponibilidad del Mesero (RF-M04-016/017).
        {
          path: 'disponibilidad',
          name: 'admin-disponibilidad',
          component: () => import('@/views/mesero/DisponibilidadView.vue'),
        },
        { path: 'mesas', name: 'admin-mesas', component: () => import('@/views/admin/MesasView.vue') },
        { path: 'reservas', name: 'admin-reservas', component: () => import('@/views/admin/ReservasView.vue') },
        { path: 'caja', name: 'admin-caja', component: () => import('@/views/admin/CajaView.vue') },
        { path: 'reportes', name: 'admin-reportes', component: () => import('@/views/admin/ReportesView.vue') },
        {
          path: 'configuracion',
          name: 'admin-configuracion',
          component: () => import('@/views/admin/ConfiguracionView.vue'),
        },
      ],
    },

    // ---- Mesero (M02, M05, M06) ----
    {
      path: '/mesero',
      component: () => import('@/layouts/MeseroLayout.vue'),
      meta: { requiereAuth: true, roles: ['mesero'] as Rol[] },
      children: [
        { path: '', name: 'mesero-pedidos', component: () => import('@/views/mesero/PedidosView.vue') },
        {
          path: 'pedidos/nuevo',
          name: 'mesero-nuevo-pedido',
          component: () => import('@/views/mesero/NuevoPedidoView.vue'),
          // Pantalla completa: trae su propio encabezado y barra del carrito — el layout
          // esconde la barra superior/inferior de móvil y no le pone márgenes (MeseroLayout).
          meta: { pantallaCompleta: true },
        },
        { path: 'mesas', name: 'mesero-mesas', component: () => import('@/views/mesero/MesasView.vue') },
        { path: 'caja', name: 'mesero-caja', component: () => import('@/views/mesero/CajaView.vue') },
        // Fase 1, tarea 4 (HU-M05-009/010): la misma pantalla de Reservas del Administrador,
        // que se adapta al rol (el Mesero no ve los depósitos retenidos, RF-M05-035).
        { path: 'reservas', name: 'mesero-reservas', component: () => import('@/views/admin/ReservasView.vue') },
        // Fase 1, tarea 5 (HU-M04-009/010/013): agotado/disponible y búsqueda del menú.
        { path: 'menu', name: 'mesero-menu', component: () => import('@/views/mesero/DisponibilidadView.vue') },
      ],
    },

    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue') },
  ],
})

// Guard de autenticación y rol (RF-M01-009: restringir funcionalidades según
// rol y permisos). La autorización fina de cada acción sigue viviendo en el
// backend (Policies) — este guard solo evita navegar a una zona equivocada.
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await esperarSesionLista(auth)

  if (to.meta.soloInvitado && auth.estaAutenticado) {
    return { name: 'home' }
  }

  if (to.meta.requiereAuth && !auth.estaAutenticado) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // CORREGIDO (29/09/2026): "auth.rol && ..." dejaba pasar SIN chequear el rol cuando
  // auth.rol todavía era null (ej. justo después de que el interceptor 401 de api.ts
  // limpia la sesión, o en cualquier estado donde el token diga "autenticado" pero el
  // usuario aún no cargó) — una ruta con roles restringidos requiere un rol conocido y
  // permitido, nunca "sin rol = dejar pasar".
  const rolesPermitidos = to.meta.roles as Rol[] | undefined
  if (rolesPermitidos && !(auth.rol && rolesPermitidos.includes(auth.rol))) {
    return { name: 'home' }
  }

  return true
})

export default router
