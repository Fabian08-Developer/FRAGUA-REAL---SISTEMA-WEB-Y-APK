# Fragua Real Burger — Documento de entrega

Cierre de la semana 2 del cronograma (tarea 30: "sistema completo listo para
entrega"). Este documento resume qué se construyó, cómo correrlo, qué se
verificó en el QA final de cierre y qué queda a propósito fuera de alcance.
No repite el detalle técnico de `README.md` (base de datos) y
`README-BACKEND.md` (Services/Policies/Controllers, con el historial completo
de correcciones) — enlaza a ellos para eso.

## Qué es

Sistema de restaurante de una sede (sin multi-tenant) para Fragua Real
Burger: pedidos locales (mesa con QR), a domicilio y para recoger; reservas
con depósito obligatorio; caja y cobros; catálogo de productos/combos/
promociones; compras y gastos con cierre diario; reportes; y gestión de
usuarios. Cubre los 9 módulos (M00–M08) de los documentos oficiales de
requisitos, con tres roles: **Cliente** (web pública, con o sin cuenta),
**Mesero** y **Administrador**.

## Stack

- **Backend**: Laravel 13 + Sanctum (autenticación por token, no por cookie),
  PostgreSQL.
- **Frontend**: Vue 3 + TypeScript (`<script setup>`) + Tailwind, servido por
  Vite (puerto 5173) con proxy a la API Laravel (puerto 8000).
- Detalle completo de modelos/migraciones en `README.md`; de
  Services/Policies/Controllers y el historial de correcciones de
  documentación y código en `README-BACKEND.md`.

## Cómo correrlo

```bash
# Backend
cd backend
composer install
# .env ya configurado (PostgreSQL + SMTP real para recuperación de contraseña)
php artisan migrate --seed
php artisan serve

# Frontend (otra terminal)
cd frontend
npm install
npm run dev
```

Frontend en `http://localhost:5173`, API en `http://localhost:8000` (proxy
`/api` → backend, ver `frontend/vite.config.ts`).

### Cuentas de prueba (seeder)

| Rol | Correo | Contraseña |
|---|---|---|
| Administrador | admin@fraguareal.com | password |
| Mesero | mesero@fraguareal.com | password |
| Cliente | cliente@fraguareal.com | password |

El Cliente también puede navegar y pedir como invitado, sin cuenta.

## QA final — estado por rol

Verificado en vivo contra los servidores de desarrollo del usuario
(no solo revisión de código):

- **Cliente**: home, menú, carrito (invitado y con cuenta), checkout de
  domicilio, formulario de reserva, registro, login, perfil, historial de
  pedidos. Sin hallazgos.
- **Mesero**: listado de pedidos y confirmación, mesas, cobro de un pedido
  (efectivo) de punta a punta — incluido el bug de caja de abajo, que
  bloqueaba el cobro hasta corregirlo.
- **Administrador**: apertura/cierre de turno de caja, historial de turnos.
  Catálogo, Usuarios, Mesas, Reservas, Dashboard, Compras y Gastos, y
  Reportes se construyeron y probaron puntualmente en sesiones anteriores
  (ver `README-BACKEND.md`); no se repitió una pasada sistemática de todos
  ellos en este cierre.
- **Recuperación de contraseña por correo real** (RF-M01-012/013): probada
  de punta a punta con una cuenta real — el correo llega y el enlace
  precarga el formulario de restablecimiento.

## Bug real encontrado y corregido en este cierre

`GET /caja/turno-actual` devolvía el JSON `{}` en vez de `null` cuando no
había turno abierto hoy. Causa: en esta instalación (Laravel 13 + PHP 8.3),
`response()->json(null)` no serializa al `null` esperado — serializa al
literal `"{}"` (se aisló con una ruta de prueba sin Controller ni Service de
por medio). Como `{}` es "truthy" en JavaScript, la pantalla de Caja del
Administrador mostraba "Abierta" sin que existiera ningún turno para hoy, lo
que a su vez bloqueaba al Mesero para cobrar (la validación de que debe
existir un turno abierto — RN-M06-03 — rechazaba el cobro correctamente,
porque en la base de datos realmente no había turno de hoy).

Corregido en `CajaController::turnoActual()`: cuando no hay turno, construye
la respuesta HTTP a mano en vez de dejar que `response()->json()` serialice
un `null` de nivel superior. Verificado en vivo: el Administrador ve
correctamente "sin turno abierto hoy", pudo abrir uno, y el Mesero completó
el cobro pendiente. Detalle completo en `README-BACKEND.md`, punto 13.

## Qué falta a propósito

Actualizado 06/10/2026 respecto a lo documentado en `README-BACKEND.md` ("Qué falta
a propósito"): pasarela de pago real (Nequi/Bancolombia, webhook pendiente),
exportación de reportes con formato real (hoy CSV/PDF simples), caché y
generación asíncrona de exportaciones de reportes. Cada punto cita su RF/RN
o sección oficial exacta — no son brechas de documentación, son alcance
técnico fuera de este backend de referencia.

La tabla `configuraciones` (valor de domicilio y depósito de reserva, editables por el
Administrador) ya está implementada desde el 28/09/2026; el valor inicial del
depósito de reserva es $20.000 COP (confirmado el 06/10/2026, RN-M05-14).

## Pendientes conocidos al 10/10/2026

- Las cuentas de prueba (`password`) deben desactivarse o cambiarse antes de producción.
- La revisión del 09/10/2026 encontró funciones documentadas que el sistema aún no tiene
  (282 de 582 requisitos hechos; al 11/10/2026 van 406 de 579 verificables, 70 %). Están ordenadas en el documento "Plan de implementación —
  Fragua Real Burger" y en la hoja "Plan de implementación" del Cronograma. La Fase 0
  (errores y seguridad) está terminada; ver `README-BACKEND.md`, punto 15. La Fase 1 está en
  curso: las tareas 1 (editar pedidos con historial), 2 (domicilios y recogidas por teléfono) y
  3 (cuenta y cobro por mesa) y 4 (reservas del Mesero) están hechas; ver los puntos 16 a 19.
  Con las tareas 5 (agotado y búsqueda) y 6 (cancelación y nombres de mesa) la Fase 1 quedó
  completa el 11/10/2026; ver los puntos 20 y 21. La Fase 2 empezó con la tarea 1
  (promociones aplicadas al pedido y descuento manual) y la tarea 2 (el depósito de la reserva
  se abona a la cuenta de la visita) y la tarea 3 (el mismo catálogo en todos los canales); ver los
  puntos 22 a 24.
- Las pantallas cambiadas en las Fases 0, 1 y 2 (Mesero > Pedidos, Nuevo pedido y Cobrar,
  Administrador > Pedidos, Caja, Catálogo y Reportes, Menú, Carrito, QR de mesa, Checkout, Mis
  pedidos y Seguimiento) no se compilaron en la sesión donde se hicieron: correr
  `npm run build` en `frontend` antes de probarlas.
- Después de actualizar el código hay que correr `php artisan migrate` (4 migraciones nuevas
  del 09/10/2026, 6 de la Fase 1 y 3 de la Fase 2, ninguna borra datos).

## Cambios de comportamiento de la Fase 0 (09/10/2026)

- Un pedido ya no se finaliza solo con el pago: tiene que estar en preparación y, si es
  domicilio o recogida, el Mesero confirma la entrega o la recogida (botones en Mesero >
  Pedidos; en Cobrar, el domicilio contra entrega pide confirmar la entrega antes de cobrar).
  Un local pagado antes de prepararse se finaliza con el botón "Finalizar pedido".
- Un pago en línea aprobado ya no finaliza el pedido: el Mesero lo confirma como siempre.
- El invitado puede pagar en línea; reintentar el pago no crea otro pedido.
- El efectivo esperado de la caja descuenta las compras y gastos pagados en efectivo.
- Anular o reembolsar un pago de un día con la caja cerrada pide la contraseña del
  Administrador y queda como ajuste extraordinario.
- Una cuenta desactivada pierde sus sesiones y no entra a ninguna pantalla.

## Cambios de comportamiento de la Fase 1 (10/10/2026)

- El Mesero y el Administrador editan un pedido PENDIENTE (agregar, cambiar cantidad u
  observaciones, quitar) con el botón "Editar pedido". Un pedido CONFIRMADO también se edita,
  pero solo en las líneas que no se han marcado como preparadas.
- Cada pedido tiene "Ver historial": quién lo creó, confirmó, cambió o cobró, y a qué hora.
- "Nuevo pedido" del Mesero ahora pregunta primero el canal: Mesa, Domicilio o Recogida. Para
  domicilio y recogida pide los datos del cliente (los pedidos por teléfono o WhatsApp).
- Un domicilio exige barrio y punto de referencia, además de la dirección. El Cliente con cuenta
  confirma su teléfono en el Checkout, y todos pueden dejar indicaciones para la entrega.
- Pedidos (Mesero y Administrador) muestra los datos de entrega, el teléfono para llamar y cómo
  dijo el cliente que iba a pagar (contra entrega, al recoger o en línea con Nequi o Bancolombia).
- Mesero > Mesas muestra cuánto lleva la cuenta de cada mesa ocupada y cuánto debe; "Ver
  cuenta" muestra todos sus pedidos. En Mesero > Cobrar, los pedidos de mesa aparecen agrupados
  por mesa y se cobran juntos con "Cobrar mesa" (también por partes, para pagos mixtos).
- El depósito de una reserva se descuenta de la cuenta de la mesa desde la Fase 2 (ver abajo).
- El Mesero tiene su pestaña Reservas: registra reservas por teléfono, su depósito en efectivo,
  tarjeta o transferencia, y confirma la llegada del cliente. El Administrador tiene el mismo
  botón "Nueva reserva". Al elegir fecha y hora solo se ofrecen las mesas libres a esa hora, y
  dos reservas de la misma mesa quedan separadas al menos 2 horas.
- El depósito manual ya no ofrece "En línea": ese es el pago del Cliente por Nequi o Bancolombia.
- El Mesero tiene la pestaña Menú para marcar agotado o disponible un producto o un tamaño, con
  buscador; "Nuevo pedido" también tiene buscador. El Administrador llega a la misma pantalla
  desde Catálogo > "Agotados y disponibilidad".
- El Mesero cancela solo pedidos pendientes; uno ya confirmado necesita que un Administrador
  escriba su contraseña en el mismo modal. El Cliente ahora también puede cancelar su recogida
  pendiente. El Administrador puede cambiar el nombre de una mesa sin que cambie su QR.
- El invitado recibe al terminar el Checkout un enlace privado (/seguimiento/...) para ver el
  estado de su pedido, pagarlo en línea o cancelarlo mientras esté pendiente.
- Una mesa sigue quedando «Reservada» desde que se confirma el depósito, aunque la reserva sea
  para otro día; cuándo marcarla se resuelve en la fase de automatización de reservas.

## Cambios de comportamiento de la Fase 2 (11/10/2026)

- Las promociones automáticas que el Administrador crea en Catálogo ya se aplican solas a los
  pedidos de los tres canales, dentro de su fecha, horario y días. Varias vigentes se suman. Un
  pedido conserva las promociones con las que se creó aunque después cambien.
- El menú, el QR de mesa y "Nuevo pedido" del Mesero marcan los productos en promoción; el
  carrito, el Checkout, el QR y "Nuevo pedido" muestran cuánto rebaja cada promoción y el total.
- En Pedidos, el Mesero y el Administrador tienen el botón "Descuento": porcentaje, valor fijo o
  una promoción de tipo "manual", siempre con motivo. Reemplaza a las promociones automáticas
  del pedido (no se suman) y se puede quitar. Queda en el historial y en la auditoría.
- El valor del domicilio nunca se rebaja. El descuento no puede dejar el total por debajo de lo
  ya pagado.
- Los horarios de promociones y combos ahora se cuentan en la hora de Colombia (antes, en la
  del servidor, cinco horas adelante).
- Los reportes de ventas por producto y por tipo de venta ya restan los descuentos.
- Al confirmar la llegada de una reserva, o con el primer pedido en una mesa reservada, el
  depósito se abona a la cuenta de la mesa: el saldo baja y la cuenta dice cuánto es depósito. Si
  la visita gasta menos, lo que sobra se usa en los pedidos siguientes de esa visita.
- Registrar un depósito presencial ahora exige la caja abierta, y el depósito en efectivo suma al
  efectivo esperado de ese día. Cancelar una reserva con depósito en efectivo (devolverlo) también
  exige la caja abierta y lo resta. El detalle del turno en Caja lista los depósitos.
- En Reportes > Ventas por método, el abono del depósito sale como "Depósito de reserva".
- En Catálogo, cada producto elige qué extras generales ofrece; un extra general sin productos
  elegidos se ofrece con todo el menú. El Menú, el QR de mesa y el pedido del Mesero muestran
  ahora los mismos extras (antes los generales no salían en ninguno).
- Dar de baja un producto desactiva los combos que lo usan, y el combo no se puede volver a
  activar hasta quitar o reemplazar ese producto. Desactivar una categoría oculta sus productos
  y sus combos en todos los canales.
- Precios en cero se rechazan, y un nombre de producto o categoría repetido muestra un mensaje
  claro. Cada alta o cambio del catálogo (incluido el precio) queda en la auditoría.

## Artefactos de prueba dejados en la base de datos

Quedaron de las distintas sesiones de QA — no son bugs, pero conviene
limpiarlos (o resetear la base de datos con el seeder) antes de usar el
sistema con datos reales:

- Un turno de caja del 29/09/2026 que quedó abierto sin cerrar (no afecta la
  regla de "un turno por día", que se evalúa por fecha).
- Dos cuentas Cliente de prueba creadas durante el QA de recuperación de
  contraseña y registro (`qa.cliente.cierre@fraguareal.test` y la cuenta de
  prueba de SMTP, ya desactivada).
- Pedidos de prueba (#2 a #20) con sus cobros ya registrados.
