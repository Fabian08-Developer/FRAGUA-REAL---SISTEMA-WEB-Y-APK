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
# Desde la Fase 5 (13/10/2026) el backend usa barryvdh/laravel-dompdf para los PDF. La primera vez
# después de actualizar: composer update barryvdh/laravel-dompdf --with-all-dependencies
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

| Rol | Correo | Usuario | Contraseña |
|---|---|---|---|
| Administrador | admin@fraguareal.com | admin | password |
| Mesero | mesero@fraguareal.com | mesero | password |
| Cliente | cliente@fraguareal.com | cliente | password |

El usuario (Fase 3) solo existe en una base sembrada después del 12/10/2026; en una base
anterior se entra con el correo o se le pone desde Usuarios.

El Cliente también puede navegar y pedir como invitado, sin cuenta.

## Programador y cola en el VPS (Fase 4)

Desde la Fase 4 el sistema hace tareas solo (no-show, recordatorios, avisos por correo, limpieza
de sesiones). Para eso, en el VPS de Hostinger tienen que correr dos procesos además de Nginx y
PHP-FPM. Los archivos listos están en `backend/deploy/` (ajustar la ruta
`/var/www/fragua-real/backend` y el usuario `www-data` si en el servidor son otros):

1. **Programador (cron)**: copiar `backend/deploy/cron-fragua-real` a `/etc/cron.d/fragua-real`
   (dueño root, permisos 644). Ejecuta `php artisan schedule:run` cada minuto.
2. **Trabajador de la cola (Supervisor)**: `sudo apt install supervisor`, copiar
   `backend/deploy/supervisor-fragua-real-cola.conf` a `/etc/supervisor/conf.d/` y correr
   `sudo supervisorctl reread && sudo supervisorctl update`. Ejecuta
   `php artisan queue:work database` y lo reinicia si se cae.
3. En el `.env` del servidor: `QUEUE_CONNECTION=database` y `CACHE_STORE=database` (las tablas
   las crea `php artisan migrate`), un `MAIL_MAILER` que envíe de verdad (SMTP) y
   `FRONTEND_URL` con la dirección pública del sitio (los correos de reserva llevan un enlace
   a esa dirección).
4. Después de cada despliegue del código: `php artisan migrate --force`,
   `php artisan config:cache` y `php artisan queue:restart` (Supervisor levanta el trabajador
   con el código nuevo).

Comprobación: Administrador > Configuración > **Tareas automáticas** muestra si el programador y
la cola están activos (cada uno deja un latido cada minuto), los trabajos pendientes y fallidos,
y si el correo sale de verdad. En el equipo de desarrollo se puede probar con
`php artisan schedule:work` y `php artisan queue:work` en dos terminales.

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
- En el VPS, PHP y Nginx deben aceptar archivos de hasta 8 MB para los soportes de compras
  (ver `README-BACKEND.md`, punto 25), y `storage/app/private` debe entrar en las copias de
  seguridad.
- La revisión del 09/10/2026 encontró funciones documentadas que el sistema aún no tiene
  (282 de 582 requisitos hechos; al 13/10/2026 van 479 de 579 verificables, 83 %). Están ordenadas en el documento "Plan de implementación —
  Fragua Real Burger" y en la hoja "Plan de implementación" del Cronograma. La Fase 0
  (errores y seguridad) está terminada; ver `README-BACKEND.md`, punto 15. La Fase 1 está en
  curso: las tareas 1 (editar pedidos con historial), 2 (domicilios y recogidas por teléfono) y
  3 (cuenta y cobro por mesa) y 4 (reservas del Mesero) están hechas; ver los puntos 16 a 19.
  Con las tareas 5 (agotado y búsqueda) y 6 (cancelación y nombres de mesa) la Fase 1 quedó
  completa el 11/10/2026; ver los puntos 20 y 21. La Fase 2 empezó con la tarea 1
  (promociones aplicadas al pedido y descuento manual) y la tarea 2 (el depósito de la reserva
  se abona a la cuenta de la visita), la tarea 3 (el mismo catálogo en todos los canales) y la
  tarea 4 (compras y gastos completos). Con la tarea 5 (cierre diario completo) la Fase 2 quedó
  completa el 12/10/2026; ver los puntos 22 a 26. La Fase 3 empezó con la tarea 1 (consulta
  de auditoría y eventos de seguridad), la tarea 2 (editar usuario y cambiar su rol), la tarea 3
  (iniciar sesión con correo o nombre de usuario) y la tarea 4 (sesiones abiertas con su origen y
  vencimiento); la Fase 3 quedó completa el 12/10/2026. Ver los puntos 27 a 30.
- Política de sesiones con valores iniciales: una sesión se cierra sola tras 7 días sin uso y, como
  máximo, a los 30 días. Se cambian en el `.env` del servidor
  (`FRAGUA_REAL_SESION_INACTIVIDAD_MINUTOS`, `FRAGUA_REAL_SESION_DURACION_MAXIMA_MINUTOS`). La
  limpieza diaria de sesiones vencidas (`sesiones:limpiar`) necesita el cron de Laravel en el VPS
  (Fase 4).
- La Fase 4 empezó con la tarea 1 (cola y programador en marcha), la tarea 2 (tiempos de las
  reservas configurables), la tarea 3 (no-show automático, mesa Reservada a tiempo y
  recordatorio), la tarea 4 (avisos de reserva por correo y cancelación por enlace) y la tarea 5
  (aviso «listo para recoger»): ver "Programador y cola en el VPS" y `README-BACKEND.md`,
  puntos 31 a 35. La Fase 4 quedó completa el 13/10/2026 salvo el canal SMS, que se aplazó por
  la decisión D3 hasta elegir proveedor.
- La Fase 5 empezó el 13/10/2026 con la tarea 1 (comprobante de pago): ver `README-BACKEND.md`,
  punto 36, con la reimpresión controlada que pediste el mismo día (punto 37) y con la tarea 2
  (cierre diario impreso y en PDF, punto 38). Trae una dependencia nueva de Composer (`barryvdh/laravel-dompdf`): después de
  actualizar el código hay que correr `composer update barryvdh/laravel-dompdf
  --with-all-dependencies` en `backend` (actualiza `composer.lock`; en el VPS, `composer install`
  con ese lock). Para que el comprobante muestre el NIT, ponerlo en `FRAGUA_REAL_NIT`. Decisiones del 12/10/2026: el sistema corre en un VPS de
  Hostinger (D2); los avisos al cliente van por correo en V1 y el SMS queda para después (D3);
  reservas con no-show a los 15 min, recordatorio 2 h antes y mesa Reservada 1 h antes,
  editables en Configuración (D6).
- Las pantallas cambiadas en las Fases 0, 1 y 2 (Mesero > Pedidos, Nuevo pedido y Cobrar,
  Administrador > Pedidos, Caja, Catálogo y Reportes, Menú, Carrito, QR de mesa, Checkout, Mis
  pedidos y Seguimiento) no se compilaron en la sesión donde se hicieron: correr
  `npm run build` en `frontend` antes de probarlas.
- El modal del QR de mesa (Administrador > Mesas) cargaba las librerías de QR y PDF desde un
  CDN (esm.sh), lo que hacía fallar `npm run build` (2 errores TS2307 de `vue-tsc`) y dejaba el
  QR dependiendo de un servidor externo. Corregido el 12/10/2026: `qrcode` y `jspdf` (4.2.1 o
  superior, sin las vulnerabilidades de las versiones anteriores) son dependencias del
  proyecto y `@types/qrcode` de desarrollo; después de actualizar hay que correr
  `npm install` en `frontend` antes de `npm run build`. El nombre de la mesa se escapa al
  imprimir el QR.
- Después de actualizar el código hay que correr `php artisan migrate` (4 migraciones nuevas
  del 09/10/2026, 6 de la Fase 1, 5 de la Fase 2, 3 de la Fase 3, 4 de la Fase 4 y 3 de la Fase 5, ninguna borra datos).

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
- En Compras y gastos la categoría es obligatoria y se elige de una lista según el tipo; se
  pueden anotar cantidad, unidad y observaciones, y adjuntar la foto o el PDF de la factura
  (antes había que pegar un enlace). El soporte solo lo abre el Administrador con su sesión.
- El historial de compras y gastos se filtra por fechas, categoría, forma de pago, proveedor y
  estado, con los totales de lo filtrado, y muestra quién corrigió o anuló cada registro.
- Una compra no puede registrarse con fecha futura.
- El cierre diario muestra primero el cálculo del día con todo su desglose (ventas por tipo y por
  forma de pago, compras y gastos por categoría y por forma de pago, y el arqueo de la caja). Solo
  se puede cerrar el día después de cerrar la caja de ese día. Los cierres anteriores quedan en
  una lista para consultarlos.
- Registrar, corregir o anular una compra o un gasto de un día ya cerrado pide la contraseña del
  Administrador y un motivo. El cierre no cambia: la corrección se ve debajo, con los valores
  actualizados.

## Cambios de comportamiento de la Fase 3 (desde el 12/10/2026)

- Nueva pantalla Administrador > Auditoría: quién hizo cada acción crítica, cuándo, sobre qué
  registro, desde qué IP y con qué navegador, con filtros por fechas, usuario, grupo, acción,
  registro, IP y texto. Es solo de consulta. Arriba muestra los inicios de sesión fallidos de las
  últimas 24 horas y avisa si una misma IP acumula 3 o más intentos.
- Ahora quedan registrados los inicios de sesión (también los fallidos, con el motivo), los
  cierres de sesión, el registro de clientes, los cambios y restablecimientos de contraseña, el
  alta de usuarios del personal y los cambios de configuración (domicilio y depósito).
- En Usuarios, cada usuario tiene el botón "Actividad", que abre la auditoría filtrada por él.
- En Usuarios, el botón "Editar" corrige nombre, correo y teléfono y cambia el rol (Administrador,
  Mesero o Cliente). Cambiar el rol cierra las sesiones de esa persona: debe volver a entrar. Nadie
  cambia su propio rol, y la contraseña no se cambia desde ahí.
- Se puede iniciar sesión con el correo o con un nombre de usuario. El nombre de usuario es
  opcional: se elige al crear la cuenta, en Mi perfil o lo pone el Administrador en Usuarios. Las
  cuentas que ya existían siguen entrando con su correo hasta que se les ponga uno.
- Mi perfil muestra las sesiones abiertas (dispositivo, IP, último uso y cuándo vence) y permite
  cerrar una o todas las demás. En Usuarios, "Sesiones (n)" muestra las de cada cuenta y cierra
  una o todas. Una sesión se cierra sola tras 7 días sin uso o a los 30 días; después hay que
  volver a entrar. Las sesiones abiertas antes de esta versión no tienen IP ni dispositivo.
- El historial de auditoría ya no se puede cambiar ni borrar ni siquiera desde la base de datos.
  Consecuencia: un usuario con historial no se puede borrar de la base de datos (se desactiva,
  como siempre).

## Cambios de comportamiento de la Fase 5 (desde el 13/10/2026)

- Todo pedido pagado por completo tiene su comprobante de pago con número consecutivo (FR-000001,
  FR-000002…): ticket de 80 mm para imprimir en los pedidos del local y PDF en domicilio y
  recogida, que además le llega al cliente por correo si hay a dónde. Se abre desde Pedidos, desde
  Cobrar apenas se completa el pago, desde Mis pedidos y desde el enlace de seguimiento.
- Si un pedido deja de estar pagado (se anula un pago, se agrega un producto) o se cancela, su
  comprobante queda anulado con el motivo y, al volver a pagarse, sale uno nuevo con otro número.
  Ningún comprobante se borra.
- El comprobante no es una factura electrónica y lo dice. Una mesa con varios pedidos tiene un
  comprobante por pedido.
- Reimpresión: el personal puede volver a imprimir un comprobante cuando quiera; desde la segunda
  vez sale marcado «REIMPRESIÓN · copia n» con la fecha y la hora, y cada impresión queda en
  Auditoría con quién la hizo. Lo que descarga el cliente no se marca. El Administrador ve en
  Pedidos > "Comprobantes" todos los del pedido, también los anulados, y abre cualquiera (el anulado
  sale con su sello).
- El cierre diario de un día cerrado se imprime o se guarda en PDF (tamaño carta) con el día, los
  totales, el desglose, el arqueo, las correcciones posteriores, quién cerró y quién imprime, y
  líneas de firma. Sigue la misma regla de reimpresión. Un día sin cerrar se imprime solo como
  vista previa y la hoja lo dice.

## Cambios de comportamiento de la Fase 4 (desde el 12/10/2026)

- Configuración tiene la tarjeta "Tareas automáticas": dice si el programador de tareas y la cola
  de avisos están corriendo en el servidor, cuántos trabajos hay pendientes o fallidos y si el
  correo sale de verdad o solo se registra.
- Configuración tiene "Tiempos de las reservas": tolerancia antes del no-show (15 min),
  anticipación del recordatorio (2 h) y desde cuándo la mesa se ve Reservada (1 h). Se usan desde
  la tarea 3 de la Fase 4. En Reservar mesa, el Cliente ve cuántos minutos se le espera.
- Las reservas se manejan solas (con el programador corriendo): la mesa se ve Reservada solo
  desde 1 h antes (antes quedaba Reservada desde que se pagaba el depósito, aunque fuera para otro
  día); si el cliente no llega en 15 min, la reserva pasa sola a "No asistió" y el depósito se
  retiene; y 2 h antes le llega un correo de recordatorio. "Marcar no-show" a mano solo aparece
  cuando ya pasó la tolerancia.
- Cada reserva avisa al cliente por correo cuando se confirma el depósito, cuando se cancela
  (dice si el depósito se devuelve) y cuando se marca "No asistió", además del recordatorio.
  Cada correo trae un enlace para ver la reserva y cancelarla sin cuenta, mientras no haya
  llegado su hora; si el depósito se pagó en efectivo, el enlace pide llamar o pasar por el
  local. En Reservas, cada reserva muestra sus avisos ("enviado", "en cola" o "falló" con el
  motivo) y un botón "Reintentar" para los que fallaron. Si el correo falla, la reserva sigue
  normal. Los avisos por SMS quedan para después (decisión D3).
- Las recogidas tienen el botón "Listo para recoger" (Mesero y Administrador): el pedido sigue en
  Preparando, sus líneas quedan preparadas y el cliente recibe un correo con lo que falta por
  pagar y el enlace de su pedido. El invitado puede dejar un correo opcional en el Checkout (y el
  Mesero al registrar una recogida por teléfono); sin correo, la pantalla pide llamarlo. Mis
  pedidos y el seguimiento muestran "Listo para recoger".
- Se crearon las tablas que el `.env` ya esperaba (`jobs`, `failed_jobs`, `cache`,
  `cache_locks`, `sessions`…). Sin ellas, con `CACHE_STORE=database`, el límite de intentos del
  inicio de sesión y el programador fallaban en el servidor.

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
