# Backend — Services, Controllers, Policies (aplica el Documento Técnico de Arquitectura)

Este paquete complementa `README.md` (base de datos) con la capa que describe el
Documento Técnico de Arquitectura: `app/Services`, `app/Policies`,
`app/Http/Controllers/Api`, `app/Http/Requests/Api`, `app/Http/Middleware`,
`app/Providers/AuthServiceProvider.php`, `routes/api.php` y `config/fragua_real.php`.

> **Nota (06/10/2026):** este documento nació como paquete para copiar a un proyecto
> `laravel new`. Hoy el repositorio es un monorepo y `backend/` ya es el proyecto Laravel
> completo (migraciones, modelos, Services, Controllers, rutas); las instrucciones de
> instalación vigentes están en `README-ENTREGA.md`. La sección de registro de middleware
> y Policies al final se conserva como referencia.

## Correcciones aplicadas frente al documento de arquitectura

La revisión previa del documento encontró varias inconsistencias entre lo que describía y
lo que existía o era técnicamente correcto en Laravel. Quedaron resueltas así:

1. **`PagoPolicy` faltante.** El flujo de la sección 3 ("Administrador cobra un pedido
   Local") usaba `PagoPolicy::cobrar()` sin que existiera. Se creó — pero con un ajuste
   técnico importante (punto 2).
2. **`cobrar` y `pagarEnLinea` viven en `PedidoPolicy`, no en `PagoPolicy`.** Laravel
   resuelve la Policy por la clase del modelo que se autoriza: `$this->authorize('cobrar',
   $pedido)` siempre consulta la Policy registrada para `Pedido`, nunca la de `Pago`. Como
   ambas habilidades autorizan sobre un Pedido (antes de que el Pago exista), quedaron en
   `PedidoPolicy`. `PagoPolicy` solo tiene `anular()`, que sí actúa sobre un `Pago` ya
   creado. Ver el comentario en `app/Policies/PedidoPolicy.php`.
3. **`AuthServiceProvider` registra las 3 Policies**, no solo `PedidoPolicy` como mostraba
   el snippet del documento: `Pedido`, `Pago` y `CajaTurno`.
4. **`ComboService` no reimplementa `Combo::disponible()`.** El documento asignaba
   "determinar disponibilidad de combos" al Service sin mencionar que esa lógica ya vivía
   en el Model. `ComboService` ahora solo añade lo que el Model no puede resolver por sí
   solo (vigencia por fecha/franja/día) y delega el resto a `Combo::disponible()`.
5. **M03 (Compras/Gastos/Cierre Diario) y M08 (Reportes) ya tienen Service.** El
   documento no los incluía en la tabla de Services de la sección 4, dejándolos como
   lógica suelta en el Controller — lo cual contradice el propio argumento del documento
   en su sección 2. Se agregaron `CompraGastoService`, `CierreDiarioService` y
   `ReporteService` (con las 6 agregaciones de M08: ventas por período, productos más
   vendidos, ventas por método de pago, cierres de caja, compras/gastos y reservas).
   *(Histórico: ese conjunto de reportes no coincidía con M08 y fue reemplazado — ver punto 9.)*
6. **`valor_domicilio` y `deposito_valor` ya NO se aceptan del Cliente.** El documento no
   lo especificaba, pero dejar que el body del request fije esos valores permitiría a
   cualquiera poner su propio costo de domicilio o depósito. Ahora los fija el servidor
   desde `config/fragua_real.php` (placeholder hasta que exista la tabla
   `configuraciones` mencionada en `README.md`).
7. **Regla de "quién cobra" corregida (22/09/2026, tras revisar la documentación oficial
   de módulos).** La primera versión de `Pedido::actorQueCobra()`,
   `PedidoPolicy::cobrar()` y `PagoService::validarQuienCobra()` tenía la regla al revés
   (Administrador cobrando local/recogida, Mesero solo domicilio contra entrega) y citaba
   reglas inventadas ("RN-PED-33", "RN-M07-R-06") que no existen en la documentación real.
   La regla confirmada en M06 (RF-M06-003, RF-M06-004, RNF-M06-004) y M07 (sección 13): el
   **Mesero** registra todos los cobros presenciales — local, domicilio contra entrega y
   recogida —; el **Administrador nunca cobra**, solo abre/cierra caja y autoriza
   anulaciones. El punto 1 de esta lista describe el ejemplo original del documento de
   arquitectura ("Administrador cobra un pedido Local"), que ya no refleja la regla vigente.
8. **Depósito de reserva ahora es obligatorio (23/09/2026, RN-M05-14 a 19; decisión de
   negocio "con depósito obligatorio").** Se reescribió el flujo de `Reserva`:
   `ReservaService` gana `registrarDepositoEnLinea()` / `confirmarDepositoEnLinea()` /
   `rechazarDepositoEnLinea()` (mismo patrón confirmar/rechazar/reintentar de la pasarela
   en línea de M06, aplicado a la reserva en vez de a un pedido) y
   `registrarDepositoManual()` (Mesero o Administrador, cualquier método presencial de
   M06). `completar()` ahora sí mueve la mesa de reservada a ocupada y marca
   `deposito_aplicado_abono` (RN-M05-19: el depósito se abona al pedido de esa visita).
   `cancelar()` marca `deposito_reembolsado` cuando aplica (RN-M05-17: reembolso completo
   si se cancela antes de la hora reservada). Se quitó `decidirDevolucionDeposito()`: un
   no-show ya no se reembolsa por decisión discrecional del Administrador (RN-M05-18), así
   que esa ruta y ese método dejaron de existir. La migración de `reservas` gana
   `deposito_pasarela`, `deposito_estado`, `deposito_aplicado_abono` y
   `deposito_registrado_por`, y `deposito_devuelto` se renombró a `deposito_reembolsado`.
9. **M08 (Reportes) reconstruido con el conjunto real de reportes (23/09/2026).** La
   primera versión de `ReporteService`/`ReporteController` tenía 6 reportes que no
   coincidían con el documento oficial (ventas por período/canal, productos más vendidos,
   ventas por método de pago, cierres de caja, compras/gastos, reservas) y no tenía
   selección de periodo, comparación ni exportación. Los reportes reales (RF-M08-002 a
   009) son: ventas totales, ventas por producto y categoría (con más/menos vendidos),
   ventas por tipo de venta y por método de pago, compras y gastos, resultado operativo, y
   uso de mesas. Se agregó `App\Support\Reportes\PeriodoReporte` (RF-M08-001, RN-M08-03:
   semana/mes/personalizado, semana actual por defecto; RN-M08-04: periodo anterior
   equivalente; RN-M08-05: marca un periodo en curso como parcial). `ReporteService` ganó
   `compararResumen()` (RF-M08-010, variación absoluta y porcentual) y `aTabla()` +
   `registrarExportacion()` para la exportación (RF-M08-011/012/014, auditoría vía el
   modelo `Auditoria` ya existente). Nuevo `ReporteExportService` genera el archivo
   exportado. `resultadoOperativo()` sigue la recomendación de arquitectura del documento
   (sección 15): agrega sobre los `cierres_diarios` que M03 ya calculó, y solo recorre
   pedidos/compras/gastos en vivo para los días que aún no tienen cierre (normalmente el
   día en curso). El reporte de "cierres de caja" no forma parte del alcance real de M08
   (no está en su sección 2) y se quitó — la información que ofrecía queda cubierta por
   resultado operativo.
10. **Brechas menores de M01/M03/M04/M05/M06 cerradas (23/09/2026).**
    - **RN-M05-04**: `PedidoService::crear()` ahora marca `mesa.estado = 'ocupada'` al
      registrar el primer pedido local (antes solo se marcaba al finalizar/cancelar, nunca
      al crear).
    - **RN-M06-05, RF-M06-007**: `PedidoService::confirmar()` ahora rechaza confirmar un
      pedido de domicilio o recogida si tiene un pago en línea todavía sin aprobar (pendiente
      o rechazado); si el pedido no tiene pago en línea (paga contra entrega/al recoger), no
      aplica esta validación.
    - **RF-M05-009/010/011**: `MesaController` gana `abrir()` (Mesero/Administrador, sin
      pedido de por medio), `liberar()` (bloqueada si hay pedidos activos, igual que la
      liberación automática ya existente) y `liberarForzada()` (exclusiva del Administrador,
      con motivo obligatorio, salta esa validación y deja registro en auditoría).
    - **RF-M04-016/017**: `CatalogoController::toggleDisponible()` — Mesero o Administrador
      pueden alternar agotado/disponible sin el permiso de edición completa que exige
      `updateProducto()`.
    - **RF-M01-012/013**: `AuthController` gana `solicitarRecuperacion()` /
      `restablecerContrasena()`. Genera un token, lo guarda con hash en la nueva tabla
      `password_reset_tokens` (expira a la hora) y no revela si el correo existe. **Falta el
      envío real del correo** con el token — ver "Qué falta a propósito".
    - **RF-M01-010/023**: `AuthController` gana `logoutTodos()` (revoca todos los tokens del
      usuario autenticado) y `revocarSesiones()` (el Administrador revoca las sesiones de
      otro usuario — no hay `UsuarioController` dedicado, así que vive junto al resto de auth).
      Restablecer la contraseña también revoca todas las sesiones activas.
    - **RF-M03-14**: `CompraGastoService::editar()` + `CompraGastoController::update()` —
      edita un registro mientras esté activo (RN-M03-04 sigue vigente: nunca se borra, solo
      se anula; un registro anulado ya no se puede editar).
11. **Corrección del documento M05 y cierre de HU-M05-015 (23/09/2026).** Al revisar cada
    "brecha" contra la documentación oficial se encontró que la corrección del depósito de
    reserva descrita en el punto 8 nunca se había escrito realmente en
    `M05_Gestion_de_Mesas_Fragua_Real.docx`: RN-M05-14 seguía diciendo "fuera de alcance",
    faltaban RN-M05-15 a 19, RF-M05-028 a 036, la HU-M05-015, y las secciones de Alcance,
    Revisión transversal, Pendientes y Conclusión seguían con el texto antiguo (solo el
    documento de M06 sí tenía la referencia correcta a M05 en su tabla de dependencias). Se
    corrigió el documento M05 completo — devuelto ya a la carpeta del usuario — y se agregó
    en el código el endpoint que le faltaba a la nueva HU-M05-015 ("Consultar depósitos
    retenidos"): `ReservaController::depositosRetenidos()` (`GET
    /reservas/depositos-retenidos`, exclusivo Administrador), que filtra reservas con
    `estado = no_show`, `deposito_pagado = true` y `deposito_reembolsado = false`.
12. **Envío real del correo de recuperación de contraseña (01/10/2026).** Cerrado el
    pendiente que el punto 10 y "Qué falta a propósito" dejaban anotado. Nueva
    `App\Notifications\RecuperarPasswordNotification` (Notification, no Mailable suelto —
    queda lista para encolarse el día que `QUEUE_CONNECTION` deje de ser `sync`):
    `AuthController::solicitarRecuperacion()` la dispara con el token en texto plano (el
    hash sigue siendo lo único que se guarda en `password_reset_tokens`), con un enlace a
    `/restablecer-password?email=&token=` que `RestablecerPasswordView.vue` ya sabía
    precargar. Nueva clave `fragua_real.frontend_url` (env `FRONTEND_URL`, default
    `http://localhost:5173`) arma ese enlace. El driver de correo (`MAIL_MAILER=smtp` y las
    credenciales SMTP reales: host, puerto, usuario, contraseña, from) se configuran en el
    `.env` de cada entorno — no se tocan aquí ni se fuerzan por código, porque
    `phpunit.xml` fija `MAIL_MAILER=array` a propósito para pruebas y forzar `smtp` por
    config() rompería eso.
13. **`GET /caja/turno-actual` devolvía `{}` en vez de `null` sin turno abierto (01/10/2026,
    encontrado en vivo en el QA final).** `CajaController::turnoActual()` hacía
    `return response()->json($this->cajaService->turnoAbierto()?->load([...]));` — código
    correcto en apariencia, pero en esta instalación concreta (Laravel 13 + PHP 8.3)
    `response()->json(null)` no serializa al JSON `null`: serializa al literal `"{}"`.
    Se aisló quitando de en medio el Controller, el Service y el modelo (una ruta de
    prueba con un closure que solo hacía `return response()->json(null);` ya daba `"{}"`),
    así que no es un bug de este proyecto sino del framework/entorno — pero había que
    manejarlo en el código igual. Como `{}` es "truthy" en JS,
    `admin/CajaView.vue` (`turnoActual.value = data`, `v-else-if="!turnoActual"`)
    interpretaba "sin turno" como "turno abierto" y mostraba "Abierta · Base inicial $0 ·
    abierto por —" sin que existiera ningún turno para hoy — lo que a su vez bloqueaba al
    Mesero para cobrar (`RN-M06-03` correctamente rechazaba el cobro porque, en la base de
    datos, no había turno de hoy). `turnoActual()` ahora construye la respuesta a mano
    cuando no hay turno (`response('null', 200)->header('Content-Type',
    'application/json')`) en vez de dejar que `response()->json()` serialice un `null` de
    nivel superior; con un turno real, `response()->json($turno)` sí serializa bien (se
    confirmó en vivo), así que solo ese caso puntual necesitaba el rodeo.

14. **Revisión de la documentación oficial (06/10/2026).** Cruce de los 13 documentos con el
    código y los READMEs. Corregido en los documentos: M05 (referencias a M06 como "pendiente",
    actores del depósito, trazabilidad RF-M05-028 a 036 sin historia de usuario, estado de
    HU-M05-015 que figuraba como pendiente aunque el endpoint ya existe, y nueva
    HU-M05-016 "Configurar valor del depósito"), Catálogo de Pantallas, Cronograma,
    Estructura Técnica y estos READMEs. **Decidido el 06/10/2026:** la liberación forzada de mesa
    (RF-M05-011) la pueden hacer el Mesero y el Administrador, como dice M05; la ruta
    `POST /mesas/{mesa}/liberar-forzada` se movió al grupo `role:administrador,mesero`.
    **Corregido el mismo día:** `GET /pedidos` paginaba de a 20 y las pantallas Mesero > Pedidos y
    Mesero > Caja solo veían esa primera página, así que con volumen real podían quedar pedidos
    activos fuera de la lista. `PedidoController::index` ahora acepta `?activos=1` (pendiente,
    confirmado y preparando) y `?per_page=` (hasta 100); ambas pantallas piden solo lo que
    necesitan. Queda sin paginar Cliente > Mis pedidos (muestra los 20 más recientes).
    **Pantalla Configuración (06/10/2026):** el backend permitía editar el valor de domicilio y el
    depósito (`PUT /configuraciones`) pero el frontend no tenía dónde; se agregó
    `frontend/src/views/admin/ConfiguracionView.vue`, con su entrada en el menú del
    Administrador y `configuracionService.actualizar()`.
    **Quedan abiertos:**
    - *Administrador > Pedidos:* construida el 06/10/2026 como vista de supervisión (filtros por
      estado/canal, historial paginado, confirmar, marcar preparando y cancelar con motivo; el
      cobro sigue siendo solo del Mesero). Aclaración: M02 no define un "panel" propio del
      Administrador (solo dice que interviene según los permisos de M01); la pantalla usa los
      permisos que ya daba `PedidoPolicy`.
    - `Requisitos_Funcionales_Fragua_Real.docx` es un borrador antiguo con otra numeración;
      conviene archivarlo.

15. **Fase 0 del plan de implementación (09/10/2026): errores y huecos de seguridad.** Siete
    tareas, cada una con pruebas automáticas en `tests/Feature` (57 pruebas en total). Cuatro
    migraciones nuevas, todas aditivas (`2026_10_13_000001` a `000004`).
    1. *Finalización (RN-PED-32, RN-M07-06, RN-M07-R-04).* Un pedido solo pasa a FINALIZADO
       desde PREPARANDO, con el pago completo y, en domicilio y recogida, con la entrega o la
       recogida confirmada por el Mesero (`POST /pedidos/{id}/entregar`, `/recoger`,
       `/finalizar`). El cobro contra entrega exige la entrega confirmada (RF-M06-004). Antes un
       pago en línea aprobado finalizaba un pedido pendiente.
    2. *Creación pública de pedidos (RNF-M02-006, RNF-M04-004).* `origen=mesero` exige token de
       Mesero o Administrador; el invitado no puede fijar `cliente_id`; se validan mesa activa,
       variante del producto, extras, categoría activa y vigencia del combo.
    3. *Caja (RN-M06-08, RF-M06-017/019).* El efectivo esperado resta compras y gastos en
       efectivo del día. Anular o reembolsar con la caja del día cerrada exige la contraseña del
       Administrador y queda en la tabla `ajustes_caja`, sin reescribir el turno. Los pagos en
       línea del día aparecen en el detalle del turno.
    4. *Checkout y pago del invitado (RF-M06-005/008).* Cada pedido nace con un `token_publico`
       (solo se entrega al crearlo) para que el invitado pague y consulte
       (`/pedidos/seguimiento/{token}`). Solo un pago en línea pendiente por pedido; reintentar el
       pago ya no crea otro pedido.
    5. *Cifras (RN-M04-26, RF-M08-003/005, RF-M03-33).* Ventas por producto y por tipo incluyen
       combos y extras; el cierre diario toma el día en hora de Bogotá, igual que los reportes.
    6. *Sesiones (RF-M01-006/023).* Desactivar una cuenta revoca sus sesiones; el middleware
       `activo` bloquea a una cuenta desactivada en toda ruta con sesión; hay límite de intentos
       en login, recuperación, `POST /pedidos` y `POST /reservas`.
    7. *Auditoría (RNF-TRANS-020/021/022).* `AuditoriaService::registrar()` guarda usuario, acción,
       entidad, IP y navegador; los registros no se pueden editar ni borrar. Ya se auditan:
       apertura y cierre de caja, anulación y reembolso de pagos, cancelación de pedidos,
       activación y desactivación de usuarios, revocación de sesiones, liberación forzada de mesa
       y exportación de reportes.

    **Cómo correr las pruebas:** `php artisan test` (SQLite en memoria, según `phpunit.xml`).
    Para probar contra PostgreSQL como en producción, define `DB_CONNECTION=pgsql` y los datos
    de una base de pruebas antes del comando. La migración `2026_09_29_000001` se ajustó para
    funcionar en ambos motores.

16. **Fase 1, tarea 1 (10/10/2026): editar y modificar pedidos con historial.** RN-PED-07 a 12,
    RF-M02-L-004/005/012/013/014/015/016/017, HU-M02-002/004/005/020 y sus equivalentes de M07
    (RF-M07-009/021/023/024/028, RF-M07-R-005/016). Migración nueva `2026_10_14_000001`
    (tabla `pedido_modificaciones`); 11 pruebas en `EdicionPedidoTest`.
    - `POST /pedidos/{id}/detalles` agrega una línea (precio vigente congelado, mismas validaciones
      que al crear); `PATCH /pedidos/{id}/detalles/{detalle}` cambia cantidad u observaciones;
      `DELETE /pedidos/{id}/detalles/{detalle}` retira una línea. Solo Mesero y Administrador
      (`PedidoPolicy::modificar`).
    - PENDIENTE admite todo. CONFIRMADO solo toca líneas no preparadas (RN-PED-10). PREPARANDO,
      FINALIZADO y CANCELADO no se modifican: lo nuevo de una mesa en preparación va en otro
      pedido (RN-PED-13). Un pedido no puede quedar sin líneas (para eso se cancela) y el total no
      puede quedar por debajo de lo ya pagado (RF-M06-011). Subir la cantidad de algo agotado se
      rechaza; bajarla, no.
    - Cada cambio recalcula subtotal y total y queda en `pedido_modificaciones` (quién, cuándo,
      antes, después, total anterior y nuevo) y en la auditoría (`modificar_pedido`). Los hechos con
      el pedido CONFIRMADO quedan con `afecta_comanda = true`: son los que imprimirá la comanda de
      actualización (RN-PED-09) cuando exista la impresión POS (Fase 2).
    - `GET /pedidos/{id}/historial` (Mesero y Administrador) arma la historia completa: creación,
      cambios de estado, modificaciones y pagos con sus anulaciones, en orden.
    - Frontend: botón "Editar pedido" (pendiente o confirmado) y "Ver historial" en Pedidos del
      Mesero y del Administrador (`components/pedidos/EditarPedidoModal.vue` y
      `HistorialPedidoModal.vue`).

17. **Fase 1, tarea 2 (11/10/2026): domicilios y recogidas por teléfono, datos de entrega
    completos.** RF-M07-001, RF-M07-003 a 007, RF-M07-010, RF-M07-R-001/003, RN-M07-01,
    RN-M07-R-01, RF-M07-013/015, RF-M07-R-007/008, HU-M07-001/003/004. Migraciones
    `2026_10_14_000002` (columnas `telefono_contacto` y `observaciones_entrega` en `pedidos`, con
    relleno del teléfono de los pedidos existentes) y `000003` (`metodo_pago_preferido` y
    `pasarela_preferida`); 10 pruebas en `PedidoTelefonicoTest`.
    - Un domicilio exige nombre, teléfono, dirección, barrio y referencia (antes barrio y
      referencia eran opcionales). El teléfono se valida (7 a 15 dígitos).
    - Todo domicilio y toda recogida guardan `telefono_contacto`: el del invitado, el que escribe
      el Cliente con cuenta o, si no escribe ninguno, el de su perfil. Un Cliente sin teléfono en
      el perfil tiene que escribirlo. `observaciones_entrega` guarda las indicaciones del pedido
      completo (portería, timbre). Una recogida nunca guarda dirección y un local no guarda
      datos de entrega.
    - El Mesero registra domicilios y recogidas pedidos por teléfono o WhatsApp desde "Nuevo
      pedido" (elige Mesa, Domicilio o Recogida en el primer paso); se cobran contra entrega o
      al recoger. Pedidos del Mesero y del Administrador muestran nombre, teléfono (toque para
      llamar), dirección, barrio, referencia e indicaciones (`DatosEntregaPedido.vue`).
    - Cada domicilio y recogida guarda cómo dijo el cliente que iba a pagar: `contra_entrega`
      (por defecto, también en los pedidos por teléfono) o `en_linea` con su pasarela (Nequi o
      Bancolombia, obligatoria en ese caso). Al registrar un pago en línea, el pedido queda con la
      pasarela aplicada. Pedidos muestra "Paga: contra entrega / al recoger / en línea (Nequi)".

18. **Fase 1, tarea 3 (11/10/2026): cuenta acumulada de la mesa y cobro por mesa.**
    RF-M02-L-018/019/020/021, RN-PED-13/14/30, HU-M02-006, RF-M06-003/011. Migración
    `2026_10_14_000004` (`mesas.ocupada_desde`); 10 pruebas en `CuentaMesaTest`.
    - La cuenta de una mesa son los pedidos locales de la visita en curso, sin cancelados. La
      visita empieza cuando la mesa pasa a Ocupada (primer pedido, "Abrir mesa" o llegada de una
      reserva) y termina cuando pasa a Libre, como define M05; así no se mezclan los pedidos de
      clientes anteriores. Los cambios de estado de la mesa pasan por `Mesa::ocupar()` y
      `Mesa::liberar()`.
    - `GET /mesas/{id}/cuenta` (Mesero y Administrador) devuelve pedidos, total, pagado y saldo.
      `GET /mesas` agrega `cuenta_total`, `cuenta_saldo` y `cuenta_pedidos` a cada mesa ocupada
      para el personal. `GET /pedidos?mesa_id=` filtra por mesa.
    - `POST /mesas/{id}/cobrar` (solo el Mesero) cobra la mesa completa o una parte (pago mixto).
      `MesaCuentaService` reparte el monto entre los pedidos con saldo, del más antiguo al más
      nuevo; cada parte es un pago del pedido (ADR-003) y cada pedido finaliza solo cuando cumple
      RN-PED-32. Nunca cobra más que el saldo y, si algo falla, no queda ningún pago a medias.
    - Frontend: Mesero > Mesas muestra en cada mesa ocupada el total y lo que debe, con "Ver
      cuenta"; Mesero > Cobrar agrupa los pedidos locales en "Mesas por cobrar" y deja los
      domicilios y recogidas uno por uno (`components/pedidos/CuentaMesaModal.vue`).
    - Pendiente (decisión D7 del plan, Fases 2 y 3): el depósito de una reserva todavía no se
      descuenta de la cuenta de la mesa; hoy solo queda marcado como abono en la reserva.

19. **Fase 1, tarea 4 (11/10/2026): reservas del personal.** RF-M05-012/013/015/031/036,
    RN-M05-06/07/12/16, HU-M05-009/010. Migración `2026_10_14_000005` (`reservas.creado_por`
    acepta `administrador` y nueva columna `registrada_por`); 9 pruebas en `ReservaPersonalTest`.
    - CORREGIDO: `POST /reservas` es pública y el controlador usaba `$request->user()`, que ahí
      siempre es null; toda reserva quedaba como `cliente_web`, también las del personal. Ahora
      lee la sesión con Sanctum: la del Mesero queda `mesero`, la del Administrador
      `administrador`, y `registrada_por` guarda quién la registró (y se audita
      `crear_reserva_manual`).
    - Solo mesas activas. Dos reservas de la misma mesa no se cruzan: deben estar separadas al
      menos `duracion_reserva_minutos` (config, 120 por defecto; supuesto de trabajo hasta la
      ventana configurable de la Fase 4). Aparta la mesa una reserva confirmada, una con pago en
      línea en revisión o una manual del personal; una reserva web que nunca empezó a pagar no.
      Si dos reservas web piden la misma hora, la primera cuyo depósito se confirma se queda con
      la mesa y la otra se rechaza al confirmar.
    - `GET /mesas/disponibles?fecha_hora=` (pública) devuelve las mesas activas libres de
      reservas a esa hora; la usan la reserva web y la del personal. `GET /reservas?proximas=1`
      lista pendientes y confirmadas de hoy en adelante, en orden de hora.
    - El depósito manual solo acepta efectivo, tarjeta o transferencia (RN-M05-16; antes también
      `en_linea`). Confirmar, rechazar o registrar un depósito queda auditado (RF-M05-036).
    - Frontend: Mesero > Reservas (nueva pestaña) usa la misma pantalla del Administrador, que
      ahora tiene "Nueva reserva", empieza en "Próximas", dice "Confirmar llegada" y muestra quién
      registró cada reserva; los depósitos retenidos siguen siendo solo del Administrador. La
      reserva web muestra solo las mesas libres a la hora elegida.

20. **Fase 1, tarea 5 (11/10/2026): agotado y búsqueda del menú.** RF-M04-016 a 021 y 025,
    RN-M04-07/08, HU-M04-009/010/013. Migración `2026_10_14_000006` (`variantes.disponible`);
    7 pruebas en `DisponibilidadCatalogoTest`.
    - Una variante (tamaño) se marca agotada o disponible con
      `POST /variantes/{id}/disponibilidad` (Administrador y Mesero), igual que un producto. Una
      variante agotada no se puede pedir ni aumentar en un pedido; los pedidos ya creados no cambian.
      Cada cambio de disponibilidad (producto o variante) queda en la auditoría.
    - `GET /productos` acepta `?q=` (nombre, sin distinguir mayúsculas) y `?categoria_id=`. El
      público solo recibe productos activos y disponibles de categorías activas, con sus variantes
      activas y no agotadas; el personal recibe también lo agotado, para poder reactivarlo.
    - Frontend: pestaña Menú del Mesero (`views/mesero/DisponibilidadView.vue`, también en
      `/admin/disponibilidad` con el botón "Agotados y disponibilidad" del Catálogo): buscador,
      filtro por categoría, "Solo agotados" e interruptores por producto y por tamaño. "Nuevo
      pedido" tiene buscador en todo el menú y ya no ofrece tamaños agotados.

21. **Fase 1, tarea 6 (11/10/2026): cancelación según estado y edición de mesas.**
    RF-M02-L-024, RF-M07-017, RF-M07-R-009/010, RN-M07-05, RF-M05-002/021, HU-M05-002. Sin
    migraciones; 8 pruebas en `CancelacionYMesasTest`.
    - El Mesero cancela un pedido PENDIENTE. Uno CONFIRMADO o en PREPARANDO solo con la contraseña
      de un Administrador activo (`password_administrador`), y la auditoría guarda quién autorizó.
      El Administrador cancela cualquier pedido activo. Decisión tomada para "con autorización"
      de RN-M07-05: la cocina ya tiene ese pedido.
    - El Cliente cancela su domicilio o su recogida PENDIENTE (antes solo el domicilio). El
      invitado lo hace con su enlace: `POST /pedidos/seguimiento/{token}/cancelar`.
    - `PUT /mesas/{id}` (solo Administrador) corrige el nombre sin repetir el de otra mesa; el QR no
      cambia. Crear, renombrar, dar de baja, reactivar y regenerar el QR de una mesa quedan en la
      auditoría (RF-M05-021).
    - Frontend: el modal "Cancelar pedido" del Mesero pide la contraseña del Administrador cuando
      el pedido ya está confirmado; Mis pedidos del Cliente permite cancelar recogidas pendientes;
      Administrador > Mesas tiene "Cambiar nombre".
    - Seguimiento del invitado (RF-M07-027, RF-M07-R-015): página pública
      `/seguimiento/<token>` (`views/cliente/SeguimientoView.vue`) con el avance del pedido, sus
      productos, total y pagos; mientras esté PENDIENTE permite pagar en línea o cancelarlo. Se
      actualiza sola cada 30 segundos. El Checkout del invitado muestra ese enlace al terminar.

22. **Fase 2, tarea 1 (11/10/2026): promociones aplicadas al pedido y descuento manual.**
    RF-M04-035 a 039 y 041, RN-M04-18 a 23, HU-M04-014/015. Migración `2026_10_15_000001`
    (`pedidos.descuento`, `promociones_vigentes`, `promociones_aplicadas`, `descuento_manual*` y
    `pedido_detalles.descuento`); 23 pruebas en `PromocionesYDescuentosTest`.
    - Las promociones **automáticas** vigentes se aplican solas al crear el pedido, en los tres
      canales. Se guardan con el pedido (como los precios, ADR-002): si después se agrega una
      línea, se le aplican las mismas reglas aunque la promoción haya vencido o cambiado.
    - Cómo se calcula (`PromocionService::calcular`): las de producto o categoría rebajan el
      precio del producto en cada línea que coincide (el valor fijo es por unidad); varias se
      suman sin pasar del valor de la línea. Las de "total del pedido" se calculan sobre lo que
      queda (productos y extras) y se reparten entre las líneas. Un combo solo recibe las del
      total. El valor del domicilio nunca se rebaja. Todo se redondea al peso. El total queda
      `subtotal − descuento + domicilio`.
    - CORREGIDO: la vigencia de promociones **y combos** se evaluaba en la hora del servidor
      (UTC); ahora en la de Colombia. Una franja de 12:00 a 15:00 empezaba a las 7:00 a. m.
    - **Descuento manual** (`POST /pedidos/{id}/descuento`, Mesero y Administrador): porcentaje
      (1 a 100) o valor fijo mayor a cero, o una promoción de tipo "manual" preparada por el
      Administrador (`promocion_id`; lista en `GET /promociones/manuales`). El motivo es
      obligatorio. Reemplaza a las automáticas del pedido (no se acumulan); `DELETE` lo quita y
      vuelven las automáticas. Se permite hasta que el pedido se finaliza y nunca deja el total por
      debajo de lo ya pagado. Queda en el historial del pedido y en la auditoría
      (`aplicar_descuento_manual` / `quitar_descuento_manual`, con usuario, motivo y monto).
    - Crear y editar una promoción (incluido desactivarla, RF-M04-040) queda en la auditoría
      (`crear_promocion` / `editar_promocion`, con antes y después).
    - `POST /pedidos/cotizacion` (pública, no guarda nada) devuelve subtotal, promociones,
      domicilio y total de un carrito, con la misma cuenta que usará el pedido. `GET /productos`
      trae `promociones_vigentes` de cada producto para mostrar el precio rebajado.
    - Reportes: las ventas por producto, categoría y tipo de venta restan la parte del descuento
      de cada línea, así cuadran con lo cobrado.
    - Frontend: botón "Descuento" en Pedidos del Mesero y del Administrador
      (`components/pedidos/DescuentoPedidoModal.vue`); los totales con sus rebajas
      (`components/pedidos/TotalesPedido.vue`) en esos pedidos, Mis pedidos y el seguimiento del
      invitado; el carrito, el Checkout, el QR de mesa y "Nuevo pedido" del Mesero muestran las
      promociones vigentes y el total cotizado (`composables/useCotizacion.ts`); el menú, el QR y
      "Nuevo pedido" marcan los productos en promoción.

23. **Fase 2, tarea 2 (12/10/2026): el depósito de la reserva se abona a la cuenta de la visita.**
    RF-M05-034, RN-M05-19, decisión D7 del plan. Migración `2026_10_15_000002` (`pagos.tipo` y
    `pagos.reserva_id`; `reservas.pedido_id`, `deposito_abonado`, `llegada_at`,
    `deposito_caja_turno_id` y `deposito_reembolso_caja_turno_id`); 13 pruebas en
    `AbonoDepositoReservaTest`.
    - D7: el abono es un **pago de tipo `deposito`** sobre el pedido, aprobado, con el método con
      el que se pagó el depósito y **sin turno de caja** (`AbonoDepositoService`,
      `PagoService::registrarAbonoDeposito`). Así la cuenta de la mesa, el saldo, el cobro y la
      finalización funcionan igual que con cualquier pago, y el dinero no se cuenta dos veces.
    - Cuándo: al "Confirmar llegada" (`POST /reservas/{id}/completar`, que ahora guarda
      `llegada_at` y queda en la auditoría) o con el primer pedido en una mesa RESERVADA, que cuenta
      como la llegada de la reserva confirmada de hoy para esa mesa. Se abona del pedido más
      antiguo al más nuevo de la visita; si la visita consume menos que el depósito, lo que sobra
      queda "por abonar" y se usa en los pedidos nuevos de esa visita.
    - Si un pedido baja de valor (edición o descuento) por debajo de lo abonado, o se cancela, el
      abono se reduce solo: el pago se reversa con `reverso_tipo = ajuste_abono` y se registra uno
      nuevo por lo que cabe; lo liberado pasa a otros pedidos de la visita con saldo
      (auditoría `ajustar_abono_deposito`). Si el Administrador anula un abono, no toca ninguna
      caja ni deja ajuste extraordinario, y el valor vuelve a quedar por abonar.
    - Caja (RN-M06-02/08): registrar un depósito manual exige la caja abierta; en efectivo suma
      al efectivo esperado del turno en que se recibe. Cancelar una reserva con depósito en
      efectivo exige la caja abierta y resta la devolución de ese turno. Los depósitos en línea no
      pasan por la caja. El detalle del turno (`GET /caja/turnos/{id}`) trae `depositos_reserva`,
      `depositos_devueltos` y sus totales en efectivo; los abonos no salen en "pagos en línea".
    - Reportes: en ventas por método de pago el abono sale aparte como `deposito_reserva`.
    - Historial del pedido: "Abono del depósito de la reserva #N" y sus ajustes.
    - Frontend: la cuenta de la mesa dice cuánto de lo pagado es depósito; Reservas muestra
      cuánto se abonó y cuánto queda; el detalle del turno en Caja lista los depósitos recibidos y
      devueltos; Reportes tiene la etiqueta "Depósito de reserva".
    - CORREGIDO de paso: la visita de la mesa empieza en el momento exacto del primer pedido, así
      ese pedido siempre queda dentro de la cuenta aunque el reloj cambie de segundo entre guardar
      el pedido y ocupar la mesa.

24. **Fase 2, tarea 3 (12/10/2026): el mismo catálogo en todos los canales.** RF-M04-003, 010,
    011, 020, 021, 022, 023, 027, 028, RN-M04-09, 10, 12, 14, HU-M04-004, 008, 011, 012. Migración
    `2026_10_16_000001` (tabla `producto_extra`); 10 pruebas en `CatalogoCoherenteTest`.
    - Consultas públicas separadas de las de gestión: `GET /categorias` sin sesión (o con un
      Cliente) trae dentro de cada categoría solo los productos activos y disponibles; antes traía
      todos, incluidos los dados de baja. `GET /productos` ya no manda al público los extras
      específicos inactivos. El personal sigue viendo todo para poder reactivarlo.
    - Una categoría desactivada oculta sus productos y los combos que los usan en todos los
      canales, y no se pueden pedir; el combo no se da de baja: vuelve solo al reactivarla.
    - Extras generales (RF-M04-010/011): el Administrador elige en cada producto qué extras
      generales ofrece (`extras_generales` en `POST/PUT /productos`, tabla `producto_extra`). Un
      extra general que no se asocia a ningún producto se sigue ofreciendo con todo el menú (así no
      cambia lo ya cargado). Cada producto trae `extras_disponibles`, la misma lista para el Menú,
      el QR y el pedido del Mesero, y `PedidoService` acepta exactamente esa lista
      (`Extra::aplicaA`). `GET /extras` dice con qué productos se ofrece cada general.
    - Bajas (RF-M04-027, RN-M04-12): dar de baja un producto desactiva los combos que lo usan (queda
      en la auditoría y la respuesta trae `combos_desactivados`); un combo no puede quedar activo
      mientras tenga un componente dado de baja (422 con el nombre del componente).
    - Validaciones: todo precio (producto, variante, extra, combo) mayor a cero (RN-M04-10);
      nombres de producto y de categoría repetidos con mensaje claro (antes, error 500).
    - Auditoría (RF-M04-022): crear o editar categorías, productos, variantes, extras y combos
      queda con quién, cuándo y qué cambió (antes y después, incluido el precio). Guardar sin
      cambios no deja registro.
    - Frontend: `utils/extras.ts` da la misma lista de extras en Menú, QR, "Nuevo pedido" y "Editar
      pedido"; en Catálogo, el formulario del producto tiene "Extras generales que ofrece", la
      lista de extras dice con qué productos se ofrece cada uno, el combo marca los componentes
      dados de baja y se avisa qué combos se desactivaron.

## Qué falta a propósito

Cada uno de los puntos abiertos ya está documentado en los documentos oficiales de módulo (no son
brechas de documentación, solo de implementación técnica):

- **Tabla `configuraciones`**: RESUELTO (28/09/2026). RF-M07-030 y RN-M05-08/13 exigen que el
  Administrador edite estos valores "en cualquier momento"; ahora viven en la tabla
  `configuraciones` (`ConfiguracionController`, `GET/PUT /configuraciones`, solo Administrador) y
  `GET /configuracion` (público) los lee de ahí. `config/fragua_real.php` quedó solo como valor de
  respaldo. El valor inicial del depósito de reserva es $20.000 COP
  (confirmado el 06/10/2026 y documentado en RN-M05-14).
- **Pasarela de pago real (Nequi/Bancolombia)**: documentado como pendiente en
  `M06_Gestion_de_Caja_y_Ventas_Fragua_Real.docx` sección 16 ("Integrar técnicamente las
  pasarelas de Nequi Negocios y Bancolombia") y en `M01...docx` sección 17 ("Proveedor de
  pago en línea"). `PagoService::registrarPagoEnLinea()` (M06) y
  `ReservaService::registrarDepositoEnLinea()` (M05, RN-M05-15) crean el pago/depósito en
  estado `pendiente`; falta el webhook real que lo confirme — `confirmarPagoEnLinea()` y
  `confirmarDepositoEnLinea()` ya existen para cuando esa integración llegue.
- **Alta de Administrador/Mesero**: RESUELTO. `UsuarioController` (solo Administrador) lista, crea
  cuentas de Mesero/Administrador, las activa/desactiva y revoca sus sesiones (RF-M01-001).
  `AuthController::register()` solo expone el autorregistro de Cliente (RF-M01-014).
- **Exportación real de reportes (M08)**: documentado como pendiente en
  `M08_Reportes_e_Informes_Fragua_Real.docx` sección 16 ("Definir el formato exacto y la
  plantilla visual de los archivos exportados"), sobre la base de RF-M08-011/012.
  `ReporteExportService` genera un Excel como CSV real (`fputcsv`, Excel lo abre
  nativamente — falta una librería como `phpoffice/phpspreadsheet` para un `.xlsx` con
  formato) y un PDF real de una sola página sin dependencias externas (un reporte más largo
  se trunca — falta paginación real y una librería como `dompdf`).
- **Caché de reportes de periodos ya cerrados**: recomendación de arquitectura explícita en
  `M08...docx` sección 15; hoy cada consulta recalcula.
- **Generación asíncrona de exportaciones**: recomendación de arquitectura explícita en
  `M08...docx` sección 15; hoy `/reportes/exportar` genera el archivo de forma síncrona.
- **`DomicilioController` dedicado**: no hace falta — M07 vive dentro de `PedidoController`
  gracias a la tabla `pedidos` unificada (ADR-001). No es una brecha.

## Registrar el middleware `role` y las Policies

Este paquete no incluye `bootstrap/app.php` (Laravel 11+) ni `app/Http/Kernel.php`
(Laravel 10) porque no existen todavía en un proyecto que apenas se va a crear con
`laravel new`. Al copiar estos archivos a tu proyecto, registra el alias del middleware:

**Laravel 11+ (`bootstrap/app.php`):**
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\EnsureUserHasRole::class,
    ]);
})
```

**Laravel 10 (`app/Http/Kernel.php`):**
```php
protected $middlewareAliases = [
    // ...
    'role' => \App\Http\Middleware\EnsureUserHasRole::class,
];
```

`AuthServiceProvider` ya viene con las 3 Policies registradas — solo confirma que
`bootstrap/providers.php` (Laravel 11+) o `config/app.php` (Laravel 10) lo incluya.

No olvides instalar y publicar Sanctum (`composer require laravel/sanctum`,
`php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"`) y agregar
`Laravel\Sanctum\HasApiTokens` al modelo `User` si el paquete de base de datos que ya tienes
no lo trae.
