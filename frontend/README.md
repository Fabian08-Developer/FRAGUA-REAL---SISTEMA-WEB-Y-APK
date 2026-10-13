# Frontend — Fragua Real Burger

SPA independiente (no Blade, no Inertia) que consume el backend Laravel como
API. Stack: Vue 3 + TypeScript + Vite, Vue Router 4, Pinia, Tailwind CSS,
Lucide Icons (`lucide-vue-next`), Axios, autenticación con Laravel Sanctum
por **token** (no por cookie/SPA — ver `services/api.ts`).

## Instalación

```bash
npm install
cp .env.example .env
npm run dev
```

Por defecto Vite sirve en `http://localhost:5173` y hace proxy de `/api`
hacia `http://localhost:8000` (donde corre `php artisan serve` del backend en
`../backend`). Ajusta `vite.config.ts` si tu backend corre en otro puerto, o
cambia `VITE_API_BASE_URL` en `.env` para apuntar a una URL absoluta.

## Estructura de carpetas

```
src/
  assets/         Estilos globales (main.css con las directivas de Tailwind)
  components/
    common/       Componentes reutilizables (botones, modales, inputs, nav)
  composables/    Lógica reutilizable con Composition API (useAuth, etc.)
  layouts/        Layouts por tipo de usuario/zona
  router/         Definición de rutas y guards de navegación por rol
  services/       Cliente HTTP (Axios) y llamadas a la API por módulo
  stores/         Estado global con Pinia (auth, carrito, etc.)
  types/          Interfaces TypeScript que reflejan los modelos del backend
  views/
    auth/         Login, registro, recuperación de contraseña
    admin/        Vistas exclusivas del Administrador (M01, M03-M06, M08)
    mesero/       Vistas del Mesero (M02, M05, M06)
    cliente/      Vistas del Cliente (M02, M04, M05, M07)
```

## Convención de módulos → vistas

Cada vista referencia en un comentario a qué módulo de la documentación
oficial (M00–M08) pertenece, igual que se hizo en el backend, para que sea
fácil cruzar una vista con sus RF/HU/RN. Ver la carpeta "Documentacion Fragua
Real" del usuario para el detalle de cada módulo.

| Vista | Módulo | Rol |
|---|---|---|
| `views/auth/*` | M01 | Público / todos |
| `views/admin/UsuariosView.vue` | M01 | Administrador |
| `views/admin/PedidosView.vue`, `views/mesero/PedidosView.vue` | M02 | Administrador, Mesero |
| `views/admin/ComprasGastosView.vue` | M03 | Administrador |
| `views/admin/CatalogoView.vue`, `views/cliente/MenuView.vue` | M04 | Administrador, Cliente |
| `views/admin/MesasView.vue`, `views/mesero/MesasView.vue`, `views/admin/ReservasView.vue`, `views/cliente/ReservaView.vue` | M05 | Administrador, Mesero, Cliente |
| `views/admin/CajaView.vue`, `views/mesero/CajaView.vue` | M06 | Administrador, Mesero |
| `views/cliente/CheckoutView.vue` | M07 | Cliente |
| `views/admin/ReportesView.vue` | M08 | Administrador |

## Pendiente (a propósito, fuera de esta entrega)

- Paleta de marca real y tipografía (hoy `tailwind.config.js` trae un
  placeholder de color `brand`) — reemplazar cuando el diseño de Figma esté
  disponible.
- Componentes base (`components/common/`) y las vistas mismas quedaron como
  esqueleto navegable (layout + título + comentario del módulo/RF que
  implementan); el contenido real de cada una se construye módulo por
  módulo.
