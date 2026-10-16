# Frontend — Fragua Real Burger

Aplicación web (SPA) en Vue 3 con TypeScript y Vite que usa la API de Laravel de `../backend`. Tiene
tres zonas, una por rol: la web pública del Cliente, el panel del Mesero y el del Administrador.

**Tecnología**: Vue 3 (`<script setup>`), TypeScript, Vite 6, Vue Router 4, Pinia, Tailwind CSS 3
(paleta de marca `brand`, `ink` y `cream`; títulos en Playfair Display y texto en DM Sans, desde Google Fonts), íconos Lucide,
Axios, `qrcode` y `jspdf` para el QR imprimible de las mesas.

## Correrlo

```bash
npm install
cp .env.example .env    # VITE_API_BASE_URL=/api
npm run dev             # http://localhost:5173
```

En desarrollo, Vite pasa `/api` a `http://localhost:8000` (ver `vite.config.ts`), donde corre
`php artisan serve`.

| Comando | Qué hace |
|---|---|
| `npm run dev` | Servidor de desarrollo |
| `npm run build` | Revisa los tipos (`vue-tsc`) y compila a `dist/`, que es lo que sirve Nginx |
| `npm run type-check` | Solo revisa los tipos |

## Sesión

El inicio de sesión devuelve un token de Sanctum que se guarda en `localStorage` (`stores/auth.ts`) y
viaja en cada petición (`services/api.ts`). Si la API responde 401 (sesión vencida o
cerrada por el Administrador), se borra el token y la aplicación pide entrar de nuevo. El router (`router/index.ts`) protege cada zona por rol; la pestaña Gastos del Mesero
además pide el permiso `puede_registrar_gastos`.

## Estructura de `src/`

| Carpeta | Contenido |
|---|---|
| `views/cliente/` | Menú, Carrito, Checkout, Mis pedidos, Perfil, Reservar, Pedido por QR de mesa, Seguimiento del invitado, Reserva por enlace y retorno del pago |
| `views/mesero/` | Pedidos, Nuevo pedido, Mesas, Cobrar, Menú (agotados) y Gastos |
| `views/admin/` | Tablero, Pedidos, Catálogo, Mesas, Reservas, Caja, Compras y gastos, Reportes, Usuarios, Auditoría y Configuración |
| `views/auth/` | Iniciar sesión, Registro, Recuperar y Restablecer contraseña |
| `views/` | Inicio y página no encontrada |
| `layouts/` | Un layout por zona: Cliente, Mesero, Administrador y acceso |
| `components/common/` | Botones, campos, modales, encabezado, barra lateral, QR de mesa, sesiones |
| `components/pedidos/` | Editar, historial, descuento, cuenta de la mesa, comanda, comprobante, datos de entrega, listo para recoger |
| `components/caja/`, `compras/`, `configuracion/` | Pagos por revisar, cierre diario, tareas automáticas y revisión antes de producción |
| `services/` | Una llamada a la API por módulo (`pedido.service.ts`, `caja.service.ts`…) y el manejo de errores en español (`errors.ts`) |
| `stores/` | `auth.ts` (sesión) y `carrito.ts` (carrito con productos y combos) |
| `composables/` | `useAuth` y `useCotizacion` (total con promociones antes de pedir) |
| `utils/` | Comandas, comprobantes, combos, extras, promociones, pasarela, auditoría, redirección segura |
| `types/models.ts` | Tipos que reflejan las respuestas del backend |
| `assets/` | Estilos de Tailwind, mascota, foto del local y fotos de platos |

Las vistas del Administrador y del Mesero que comparten pantalla (Reservas y la de agotados) usan el
mismo componente con permisos distintos.

## Rutas

| Ruta | Pantalla | Quién |
|---|---|---|
| `/`, `/menu`, `/carrito`, `/checkout`, `/reservar` | Web pública | Todos |
| `/mesa/:qr_code` | Pedido desde la mesa | Todos, sin cuenta |
| `/seguimiento/:token`, `/reserva/:token`, `/pago/retorno/:referencia` | Enlaces privados | Quien tenga el enlace |
| `/pedidos` | Mis pedidos y reservas | Cliente |
| `/perfil` | Perfil y sesiones | Cualquier rol con sesión |
| `/login`, `/registro`, `/recuperar-password`, `/restablecer-password` | Acceso | Sin sesión |
| `/mesero/...` | Pedidos, nuevo pedido, mesas, caja, reservas, menú, gastos | Mesero |
| `/admin/...` | Tablero y las 11 pantallas del Administrador | Administrador |

## Documentación

El catálogo de pantallas con lo que cubre cada una está en la carpeta «Documentacion Fragua Real»; el
resto, en la raíz del repositorio (`README.md`).
