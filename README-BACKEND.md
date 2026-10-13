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

25. **Fase 2, tarea 4 (12/10/2026): compras y gastos completos (M03).** RF-M03-01, 03, 04, 07,
    13, 19, 30, RN-M03-02, 03, 14, RNF-M03-04, 06, 11, HU-M03-02, 03. Migración
    `2026_10_16_000002` (`cantidad`, `unidad`, `observaciones`, `soporte_path/nombre/mime`,
    `editado_por/at`, `anulado_por/at` en `compras_gastos`); 10 pruebas en
    `ComprasGastosCompletosTest`.
    - Categoría obligatoria y de su tipo: Compra → Alimentos, Bebidas, Empaques, Insumos, Otros;
      Gasto → Transporte, Limpieza, Servicios, Mantenimiento, Papelería, Otros (M03, sección 9).
      Viven en `config/fragua_real.php` (`categorias_compras_gastos`) para ampliarlas sin tocar
      código (RNF-M03-12); `GET /compras-gastos/categorias` las entrega. Un registro viejo con
      otra categoría se puede seguir editando mientras no se cambie.
    - Cantidad y unidad solo informativas (no hay inventario, RN-M03-02) y observaciones.
    - La fecha de la compra puede ser anterior al registro, pero no futura (RN-M03-14); la fecha y
      hora del registro son automáticas (RF-M03-30).
    - Soporte (RF-M03-07, RNF-M03-06/11): `soporte` en `POST /compras-gastos` (multipart) o en
      `POST /compras-gastos/{id}/soporte`. Solo JPG, PNG o PDF, revisando el contenido real del
      archivo, hasta 5 MB (`FRAGUA_REAL_SOPORTE_MAX_KB`). Se guarda en `storage/app/private`
      (disco `local`), nunca en una carpeta pública; se ve con `GET /compras-gastos/{id}/soporte`
      y sesión de Administrador. Reemplazarlo no borra el anterior (queda nombrado en la
      auditoría). El campo suelto `soporte_url` ya no se recibe; los registros viejos lo conservan.
    - Historial (RF-M03-13): `GET /compras-gastos` filtra por `desde`, `hasta`, `tipo`,
      `categoria`, `metodo_pago`, `proveedor` (parte del nombre) y `estado`, y trae `totales` de lo
      filtrado (solo activos).
    - Trazabilidad (RF-M03-19, RNF-M03-04): cada registro guarda quién y cuándo lo corrigió o anuló,
      y la auditoría guarda alta, corrección (antes y después), anulación y soporte.
    - Frontend (Administrador > Compras y gastos): categoría en lista según el tipo, cantidad,
      unidad, observaciones, archivo de soporte y "Ver soporte"; filtros con sus totales y
      paginación; quién corrigió o anuló cada registro.
    - **Para el VPS (Hostinger):** PHP debe aceptar archivos de al menos 6 MB
      (`upload_max_filesize = 6M` y `post_max_size = 8M` en `php.ini`) y Nginx
      `client_max_body_size 8m;`. La carpeta `storage/app/private` debe poder escribirla el usuario
      del servidor web e incluirse en las copias de seguridad.

26. **Fase 2, tarea 5 (12/10/2026): cierre diario completo (M03).** RF-M03-20 a 27, 34,
    RN-M03-08, 15, RN-M06-11, 12, HU-M03-07 a 10. Migración `2026_10_16_000003`
    (`cierres_diarios.detalle` y `caja_turno_id`, tabla `cierre_ajustes`); 7 pruebas en
    `CierreDiarioCompletoTest`. Con esta tarea la Fase 2 quedó completa.
    - Consulta previa (RF-M03-26): `GET /cierres-diarios/previa?fecha=` da el cálculo del día con
      su desglose: ventas por tipo de venta y por forma de pago (el abono de depósitos aparte),
      compras y otros gastos por categoría, compras y gastos por forma de pago, el arqueo de la caja
      del día, y si ya se puede cerrar. El resultado lleva la nota "estimado, no es la utilidad
      contable" (RF-M03-34).
    - Cierre formal (RF-M03-25, HU-M03-08): `POST /cierres-diarios` exige que la caja de ese día ya
      esté cerrada, porque el arqueo alimenta el cierre (RN-M06-11); no acepta días futuros ni un
      segundo cierre. Guarda los totales, el desglose y el turno de caja, y queda en la auditoría.
    - Historial (RF-M03-27): `GET /cierres-diarios` (con cuántas correcciones tuvo cada uno) y
      `GET /cierres-diarios/{id}` (el cierre tal como se hizo, sus ajustes, los valores
      actualizados y el cálculo de hoy).
    - Correcciones después del cierre (RF-M03-20, RN-M03-08/15, HU-M03-09, decisión D7):
      registrar, corregir o anular una compra o gasto de un día cerrado (o moverla a ese día) exige
      `password_confirmacion` del Administrador y un motivo (`motivo_correccion`; al anular, el de
      la anulación). Sin ellos responde 422 con `requiere_autorizacion: true`. El cierre no se
      reescribe: cada corrección queda en `cierre_ajustes` (cuánto cambiaron compras y gastos,
      quién autorizó, cuándo y por qué) y en la auditoría (`ajuste_cierre_diario`).
    - CORREGIDO de paso: el total de compras y gastos de un día se calculaba con `whereBetween`
      sobre la fecha, que en SQLite no encontraba los registros (la fecha se guarda con hora).
    - Frontend: `components/compras/CierreDiarioPanel.vue` en Compras y gastos — cálculo del día
      con todo el desglose, botón "Cerrar el día" (desactivado con el motivo si la caja sigue
      abierta), cierres anteriores, y en un día cerrado sus correcciones y los valores
      actualizados. Al guardar o anular algo de un día cerrado, el formulario pide la contraseña y
      el motivo.
27. **Fase 3, tarea 1 (12/10/2026): consulta de auditoría y eventos de seguridad (M01).**
    RF-M01-017, 024, 025, HU-M01-015, 020, RNF-TRANS-022, 023, RN-USR-12, 13, CA-M01-015/020.
    Migración `2026_10_17_000001` (triggers que impiden cambiar o borrar `auditorias` e índice por
    fecha); 7 pruebas en `AuditoriaConsultaTest`.
    - Eventos de seguridad auditados (`AuthController`): inicio de sesión (`inicio_sesion`), intento
      fallido (`inicio_sesion_fallido`, con el correo usado y el motivo: contraseña incorrecta,
      cuenta inexistente o desactivada; nunca la contraseña), cierre de sesión (uno o todos los
      dispositivos), registro de cliente, cambio de contraseña, solicitud de recuperación (solo si
      la cuenta existe; la respuesta sigue siendo la misma), restablecimiento y restablecimiento
      fallido (enlace no válido o vencido), y actualización del perfil propio (antes y después).
      También el alta de usuarios del personal (`crear_usuario`) y los cambios de configuración
      (`editar_configuracion`, valor de domicilio y depósito con antes y después). Cada registro
      guarda IP y navegador (RF-M01-024).
    - Consulta (RF-M01-025, RNF-TRANS-023): `GET /auditorias` y `GET /auditorias/opciones`, solo
      Administrador y solo lectura. Filtros: días del negocio (`desde`/`hasta`, hora de Colombia),
      usuario que hizo la acción, grupo (seguridad, usuarios, pedidos, caja, compras, catálogo,
      mesas, reportes, configuración), acción, registro afectado (`entidad`, `entidad_id`), IP y
      texto en los detalles (con o sin tildes). Cada registro llega con el texto de la acción, su
      grupo y un resumen del navegador ("Chrome en Android"). `opciones` trae los valores de los
      filtros y el resumen de seguridad de las últimas 24 horas: inicios fallidos y exitosos,
      restablecimientos e IPs con 3 o más intentos fallidos. El catálogo de acciones vive en
      `App\Support\Auditoria\CatalogoAuditoria`; una acción nueva que no esté ahí se muestra igual,
      en el grupo "Otros".
    - Historial protegido (RNF-TRANS-022, RN-USR-13, CA-M01-020.2): además del bloqueo en el modelo
      (Fase 0), la base de datos rechaza cualquier `UPDATE`, `DELETE` o `TRUNCATE` sobre
      `auditorias` (trigger en PostgreSQL; en SQLite para las pruebas). Por eso un usuario con
      registros de auditoría no se puede borrar de la base de datos: el sistema nunca borra
      usuarios, los desactiva.
    - CORREGIDO de paso: la hora de cada registro la ponía la base de datos (`CURRENT_TIMESTAMP`),
      que en PostgreSQL depende de la zona horaria del servidor; ahora la pone la aplicación en
      UTC, como el resto de fechas, para que los filtros por día sean exactos.
    - Frontend: `views/admin/AuditoriaView.vue` (menú "Auditoría") con el resumen de seguridad,
      atajo "Solo seguridad y sesiones", filtros y el detalle legible de cada registro (motivo,
      antes → después, navegador completo). En Usuarios, el botón "Actividad" abre la auditoría
      filtrada por ese usuario, con un atajo para ver también lo que pasó sobre su cuenta.
28. **Fase 3, tarea 2 (12/10/2026): editar un usuario y cambiar su rol (M01).** RF-M01-003, 005,
    011, 021, 022, 023, HU-M01-003, 005, CA-M01-003.1/003.2, CA-M01-005.1/005.2. Sin migración;
    7 pruebas en `EditarUsuarioTest`.
    - `PUT /usuarios/{id}` (solo Administrador, `UpdateUsuarioRequest`): nombre, correo (único,
      sin chocar con el suyo), teléfono (vacío = sin teléfono) y rol. Solo esos campos
      (CA-M01-003.1): la contraseña y el estado activo no se cambian aquí; la contraseña la cambia
      cada persona en su perfil o con la recuperación, y activar/desactivar tiene su propio botón.
    - Rol: solo administrador, mesero o cliente (RF-M01-022; cualquier otro, como "cocina" o
      "domiciliario", se rechaza). Un Cliente puede pasar a Mesero y al revés. El Administrador no
      puede cambiar su propio rol (se quedaría sin acceso a Usuarios): 422 sin guardar nada.
    - Cambiar el rol cierra todas las sesiones de esa cuenta (RF-M01-023): sus permisos cambian y
      ninguna pestaña abierta sigue con el rol anterior. La respuesta trae `sesiones_cerradas`.
    - Auditoría: `editar_usuario` con el antes → después de lo que cambió (guardar sin cambios no
      registra nada) y `cambiar_rol` aparte, con el rol anterior, el nuevo y cuántas sesiones se
      cerraron.
    - Frontend: botón "Editar" en cada usuario de Administrador > Usuarios; modal con nombre,
      correo, teléfono y rol (Administrador, Mesero o Cliente). Si el rol cambia, avisa que se
      cerrarán sus sesiones y el botón dice "Guardar y cambiar rol"; en la cuenta propia el rol
      aparece bloqueado.
29. **Fase 3, tarea 3 (12/10/2026): iniciar sesión con el correo o con un nombre de usuario (M01).**
    RF-M01-007, 011, 016, RNF-M01-004, RN-USR-04, HU-M01-006, 011. Migración
    `2026_10_17_000002` (`users.username`, opcional y único); 5 pruebas en `LoginPorUsuarioTest`.
    - Nombre de usuario (`App\Support\Usuarios\NombreUsuario`, mismas reglas en registro, perfil y
      Usuarios): opcional; de 3 a 30 caracteres; empieza con una letra; solo letras sin tilde,
      números, punto, guion y guion bajo; nunca lleva "@". Se guarda en minúsculas y es único sin
      importar mayúsculas. Las cuentas que ya existían quedan sin nombre de usuario y siguen
      entrando con su correo.
    - `POST /auth/login` recibe `login` (correo o nombre de usuario) y `password`; si lo escrito
      lleva "@" se busca como correo, si no como nombre de usuario. El campo anterior `email` se
      sigue aceptando (con cualquiera de los dos). El error es el mismo en todos los casos y sale
      en el campo que se envió. La auditoría guarda por dónde entró (`via`: correo o usuario) y,
      en los intentos fallidos, el correo o el usuario escrito.
    - Dónde se pone: al registrarse (opcional), en Mi perfil (cada quien lo elige, cambia o quita;
      si el formulario no lo envía, no se toca) y en Usuarios al crear o editar una cuenta (con
      su antes → después en la auditoría). La búsqueda de Usuarios también busca por él.
    - Seeder: las cuentas de prueba tienen `admin`, `mesero` y `cliente` como nombre de usuario
      (solo en una base recién sembrada).
    - Frontend: el login pide "Correo o nombre de usuario" (con autocompletado del navegador);
      Crear cuenta y Mi perfil tienen el campo opcional; Usuarios lo muestra como "@usuario" y
      lo pide al crear y editar. `BaseInput` acepta `autocomplete`.
    - La recuperación de contraseña sigue siendo por correo.
30. **Fase 3, tarea 4 (12/10/2026): sesiones abiertas con su origen y su vencimiento (M01).**
    RF-M01-010, 023, 024, HU-M01-010, RNF-M01-005, 015. Migración `2026_10_17_000003` (IP y
    navegador en `personal_access_tokens`); 7 pruebas en `SesionesActivasTest`. Con esta tarea la
    Fase 3 quedó completa.
    - `SesionService`: cada inicio de sesión o registro abre la sesión guardando IP, navegador y
      su vencimiento máximo (`expires_at`). Política en `config/fragua_real.php` (`sesiones`) y en
      el `.env`: se cierra sola tras `FRAGUA_REAL_SESION_INACTIVIDAD_MINUTOS` sin uso (7 días) y,
      aunque se use, a los `FRAGUA_REAL_SESION_DURACION_MAXIMA_MINUTOS` (30 días). Son valores
      iniciales propuestos; el negocio los ajusta sin tocar código. `AppServiceProvider` le pasa
      la regla de inactividad a Sanctum (`authenticateAccessTokensUsing`): una sesión vencida
      responde 401 y la app vuelve al login.
    - Mis sesiones: `GET /auth/sesiones` (dispositivo, IP, abierta, último uso, vence y cuál es la
      actual, más la política), `DELETE /auth/sesiones/{id}` (cerrar una, solo las propias) y
      `POST /auth/sesiones/cerrar-otras` (todas menos la actual).
    - Administrador: `GET /usuarios/{id}/sesiones` y `DELETE /usuarios/{id}/sesiones/{sesion}`
      (no sobre su propia cuenta; sus sesiones las maneja desde Mi perfil). `GET /usuarios` trae
      `sesiones_activas` de cada cuenta. "Revocar todas" sigue igual.
    - Auditoría: el inicio de sesión guarda el número de la sesión; cerrar una sesión propia
      (`cerrar_sesion_dispositivo`), las demás (`cerrar_otras_sesiones`) o una ajena
      (`revocar_sesion`) queda registrado con el dispositivo y la IP.
    - Limpieza: `php artisan sesiones:limpiar` borra las sesiones vencidas o abandonadas; está
      programado a diario a las 3:30 a. m. (hora de Colombia) en `routes/console.php`. Corre
      solo cuando el cron del VPS ejecute `php artisan schedule:run` cada minuto (Fase 4); sin
      eso las sesiones vencidas igual dejan de servir, solo quedan en la tabla.
    - `App\Support\Navegador` describe el navegador ("Chrome en Android") para la auditoría y las
      sesiones.
    - Frontend: Mi perfil tiene "Sesiones abiertas" (cerrar una o "Cerrar las demás", con la
      política explicada); en Usuarios el botón "Sesiones (n)" reemplaza a "Revocar sesiones" y
      abre la lista de esa cuenta para cerrar una o todas.
31. **Fase 4, tarea 1 (12/10/2026): cola de trabajos y programador de tareas en marcha.** Base de
    RF-M05-016/024 y de todos los avisos de la Fase 4. Migración `2026_10_18_000001` (tablas
    `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks` y `sessions`, cada una solo si no
    existe); 6 pruebas en `ColaYProgramadorTest`.
    - CORREGIDO: el `.env` de ejemplo usa `QUEUE_CONNECTION`, `CACHE_STORE` y `SESSION_DRIVER` en
      `database`, pero esas tablas nunca se habían creado; las pruebas no lo notaban porque usan
      `sync`/`array`. En el servidor, el límite de intentos del login (vive en la caché) y el
      bloqueo del programador habrían fallado.
    - Latido (`EstadoSistemaService`, comando `fragua:latido`, programado cada minuto): deja en la
      caché la hora del programador y encola `App\Jobs\LatidoCola`, que al procesarse deja la
      hora de la cola. `GET /sistema/estado` (solo Administrador) dice si cada proceso está
      activo (latido de hace 3 minutos o menos), los trabajos pendientes y fallidos, y si el
      correo sale de verdad (`MAIL_MAILER` distinto de `log`/`array`).
    - Tareas programadas hoy (`routes/console.php`): `fragua:latido` cada minuto y
      `sesiones:limpiar` a las 3:30 a. m. (hora de Colombia). `php artisan schedule:list` las
      muestra.
    - Despliegue: `backend/deploy/cron-fragua-real` (cron con `schedule:run` cada minuto) y
      `backend/deploy/supervisor-fragua-real-cola.conf` (Supervisor con
      `queue:work database --tries=3 --backoff=60 --max-time=3600`). Pasos en README-ENTREGA,
      "Programador y cola en el VPS".
    - Decisiones del 12/10/2026 para la Fase 4: VPS de Hostinger (D2), correo en V1 y SMS después
      (D3), y no-show 15 min / recordatorio 2 h / mesa Reservada 1 h (D6).
    - Frontend: Configuración > "Tareas automáticas"
      (`components/configuracion/EstadoTareasAutomaticas.vue`).
32. **Fase 4, tarea 2 (12/10/2026): tiempos de las reservas configurables (M05).** RF-M05-017,
    026, RN-M05-07, 08, 13; decisión D6. Sin migración (usa la tabla `configuraciones`); 5 pruebas
    en `TiemposDeReservaTest`.
    - `App\Support\Reservas\PoliticaReservas`: tolerancia antes del no-show
      (`reserva_tolerancia_minutos`, 15 min, rango 5–120), anticipación del recordatorio
      (`reserva_recordatorio_minutos`, 2 h, rango 30 min–48 h) y desde cuándo la mesa se ve
      Reservada (`reserva_ventana_reservada_minutos`, 1 h, rango 15 min–4 h). Lee la tabla
      `configuraciones` y, si la fila no existe, `config/fragua_real.php` (`reservas`).
    - `PUT /configuraciones` acepta las tres claves (opcionales, minutos enteros dentro del rango,
      con mensajes claros) y las audita con su antes → después como el resto. `GET /configuracion`
      (público) las devuelve.
    - Se aplican desde la tarea 3 (no-show automático, mesa Reservada a tiempo y recordatorios);
      por ahora el Cliente ve la tolerancia al reservar ("te esperamos hasta 15 minutos").
    - Frontend: Configuración tiene la tarjeta "Tiempos de las reservas"; Reservar mesa explica la
      tolerancia. CORREGIDO de paso: en Configuración el error de domicilio y depósito salía dos
      veces (BaseInput ya lo muestra).
33. **Fase 4, tarea 3 (13/10/2026): no-show automático, mesa Reservada a tiempo y recordatorio
    (M05).** RF-M05-014, 016, 024, RN-M05-07, 08, 13, 18. Migración `2026_10_18_000002`
    (`reservas.no_show_at`, `no_show_automatico`, `recordatorio_enviado_at` e índice por estado y
    hora); 11 pruebas en `ReservasAutomaticasTest`.
    - `ReservaAutomaticaService`, comando `reservas:procesar` cada minuto (sin solaparse): marca
      no-show las reservas confirmadas cuya hora + tolerancia ya pasó (depósito retenido,
      auditoría `no_show_automatico` sin usuario), pone cada mesa en el estado que le toca y
      encola el recordatorio por correo (`RecordatorioReservaNotification`, D3: correo en V1) N
      minutos antes, una sola vez; no lo envía si la reserva se confirmó ya dentro de ese plazo.
    - `ReservaService::sincronizarMesa()`: una mesa Libre pasa a Reservada solo dentro de la
      ventana previa (1 h) o mientras corre la tolerancia; una Reservada sin reserva cercana vuelve
      a Libre; una Ocupada no se toca. CORREGIDO: antes, confirmar el depósito marcaba la mesa
      Reservada de inmediato aunque la reserva fuera para otro día, e incluso si estaba Ocupada.
      Las mesas que quedaron Reservadas con el criterio anterior se corrigen solas en el primer
      minuto del proceso.
    - No-show manual (`POST /reservas/{id}/no-show`): solo cuando ya venció la tolerancia (422 con
      la hora desde la que se puede); se audita como `marcar_no_show` con quien lo hizo.
    - Llegada: el primer pedido local en una mesa Libre que tiene su reserva dentro de la ventana
      también cuenta como llegada (completa la reserva y abona el depósito), aunque el proceso de
      ese minuto aún no la hubiera marcado Reservada.
    - El envío del recordatorio y su registro de entrega o falla por reserva (RF-M05-027), y los
      avisos de confirmación, cancelación y no-show, son la tarea 4.
    - Frontend: Reservas (Administrador y Mesero) muestra "Si no llega, se libera sola a las …",
      el recordatorio enviado y si el no-show fue automático; "Marcar no-show" solo aparece cuando
      ya pasó la tolerancia.
34. **Fase 4, tarea 4 (13/10/2026): avisos de reserva por correo y cancelación por enlace (M05).**
    RF-M05-018, 023, 025, 027, RN-M05-09, 17, 18, HU-M05-008, 011, 014; decisión D3 (correo en
    V1, SMS después). Migración `2026_10_18_000003` (`reservas.token_publico`, único, y tabla
    `reserva_avisos`); 9 pruebas en `AvisosDeReservaTest`.
    - Cuatro avisos por correo, cada uno con el enlace de la reserva: confirmación (al confirmar
      el depósito en línea o registrar el depósito en el local), recordatorio (tarea 3),
      cancelación (dice si el depósito se devuelve y cómo) y no-show (depósito retenido). Las
      notificaciones comparten `Notifications\Concerns\DescribeReserva` (fecha, hora y valores
      en pesos).
    - `AvisoReservaService::programar()` crea el registro en `reserva_avisos` (tipo, canal
      `correo`, destino, estado `pendiente` → `enviado` | `fallido`, intentos, error, hora de
      envío) y encola `App\Jobs\EnviarAvisoReserva` después del commit. El trabajo hace hasta 3
      intentos con 1 minuto entre uno y otro; si el último falla, el aviso queda `fallido` con el
      error (auditoría `falla_aviso_reserva`) y la reserva sigue normal: un correo que no sale
      nunca rompe la acción que lo generó. Cada envío se audita como `enviar_aviso_reserva`.
    - `POST /reservas/{reserva}/avisos/{aviso}/reintentar` (Administrador y Mesero): vuelve a
      enviar un aviso fallido (422 si ya salió). `GET /reservas` trae los avisos de cada reserva.
    - Enlace público sin sesión (`ReservaPublicaController`, límite 30 por minuto):
      `GET /reservas/enlace/{token}` muestra estado, mesa, fecha, depósito y tolerancia (sin
      correo ni teléfono) y `POST /reservas/enlace/{token}/cancelar` la cancela. El token son 48
      caracteres al azar, se crea con cada reserva (las existentes se rellenaron en la migración)
      y nunca sale en las respuestas del personal (`$hidden`). Solo cancela si la reserva está
      pendiente o confirmada y aún no llega su hora; si el depósito se pagó en efectivo, pide
      llamar o pasar por el local (la devolución en efectivo se hace en caja).
    - `ReservaService::cancelar()` ahora audita `cancelar_reserva` con quien la canceló (o sin
      usuario y `por_enlace` si fue el cliente) y si el depósito se reembolsó.
    - El enlace usa `FRONTEND_URL` (`config/fragua_real.php`): en el VPS debe ser la dirección
      pública del sitio. Para que los correos salgan de verdad hace falta `MAIL_MAILER=smtp` con
      sus credenciales (Configuración > Tareas automáticas avisa si sigue en `log`).
    - Pendiente por la decisión D3: el canal SMS (RF-M05-023/025 quedan a medias hasta tener
      proveedor); la tabla ya guarda el canal de cada aviso.
    - Frontend: página pública `/reserva/:token` (`views/cliente/ReservaEnlaceView.vue`) para ver
      y cancelar; Reservas muestra en cada reserva los avisos ("enviado 3:05 pm", "en cola",
      "falló" con el error) y el botón "Reintentar".
35. **Fase 4, tarea 5 (13/10/2026): aviso «listo para recoger» (M07).** RF-M07-R-012,
    RN-M07-R-03, HU-M07-012; decisión D3. Migración `2026_10_18_000004` (`pedidos.listo_at`,
    `listo_por`, `cliente_invitado_correo` y tabla `pedido_avisos`); 8 pruebas en
    `ListoParaRecogerTest`.
    - No es un estado nuevo (M02 y M07 no tienen LISTO; ambigüedad cerrada en el plan): la
      recogida sigue en PREPARANDO y solo se guarda cuándo y quién la marcó lista. Sigue
      finalizando como siempre, cuando el Mesero confirma la recogida y el pago está completo
      (RN-M07-R-04).
    - `POST /pedidos/{pedido}/listo` (Mesero y Administrador, `PedidoService::marcarListo()`):
      solo para una recogida en preparación, una sola vez; marca todas sus líneas como
      preparadas y programa el aviso por correo. 422 con el motivo en cualquier otro caso.
    - Correo del aviso: el de la cuenta del Cliente o el que deja el invitado, opcional, en el
      Checkout o al registrar la recogida por teléfono (`cliente_invitado_correo`, se guarda en
      minúsculas; solo para invitados de domicilio o recogida). Sin correo no hay aviso y la
      pantalla le pide al Mesero llamar.
    - `AvisoPedidoService` y `App\Jobs\EnviarAvisoPedido` funcionan como los avisos de reserva:
      registro en `pedido_avisos` (pendiente → enviado | fallido, intentos, error), 3 intentos con
      1 minuto entre uno y otro, auditoría `enviar_aviso_pedido` / `falla_aviso_pedido`, y el
      pedido nunca se afecta si el correo falla. `POST /pedidos/{pedido}/avisos/{aviso}/reintentar`
      lo vuelve a enviar. El correo (`ListoParaRecogerNotification`) dice cuánto falta por pagar
      y lleva el enlace de seguimiento del pedido.
    - El historial del pedido muestra "Listo para recoger" con quién lo marcó; `GET /pedidos` y el
      seguimiento por enlace traen `listo_at`.
    - Pendiente por la decisión D3: el SMS (RF-M07-R-012 y RN-M07-R-03 quedan a medias hasta tener
      proveedor).
    - Frontend: `components/pedidos/ListoParaRecoger.vue` en Pedidos del Mesero y del
      Administrador (botón, a qué correo irá el aviso, si salió o falló y "Reintentar aviso");
      el correo del cliente aparece en los datos de entrega; Checkout y Nuevo pedido (recogida)
      piden el correo opcional; Mis pedidos y el seguimiento muestran "Listo para recoger".
36. **Fase 5, tarea 1 (13/10/2026): comprobante de pago (M06, M02).** RF-M06-009, 010, RN-M06-07,
    HU-M06-006, CA-M06-006, RF-M02-L-023, RF-M02-T-013, HU-M02-019. Nueva dependencia
    `barryvdh/laravel-dompdf` ^3.1 (dompdf 3.1). Migración `2026_10_19_000001` (completa la tabla
    `comprobantes`, que existía vacía: `consecutivo`, `detalle` json, `anulado_at`,
    `anulado_motivo`; un pedido puede tener varios comprobantes en su historia); 7 pruebas en
    `ComprobantePagoTest`.
    - `ComprobanteService::sincronizar()` lo llaman los modelos `Pago` y `Pedido` (evento `saved`)
      cada vez que cambia un pago o el total o el estado del pedido. Con el pago completo (pagos
      aprobados y no anulados que suman el total, también si fue mixto) genera el comprobante con
      el siguiente consecutivo (`FR-000001`); si después el pedido deja de estar pagado (pago
      anulado, línea agregada), se cancela o cambian sus cifras, el vigente se anula con el motivo
      (auditoría `anular_comprobante`) y, al volver a estar pagado, sale uno nuevo con otro
      número. Nunca se borra ni se reescribe: `detalle` guarda lo que dice el documento al
      generarse (negocio, pedido, líneas, descuentos, domicilio, total y pagos).
    - "impreso" en pedidos locales: ticket de 80 mm en PDF que se imprime desde el navegador (la
      impresora POS directa es la Fase 6). "digital" en domicilio y recogida: PDF A5, que además
      llega por correo adjunto (`ComprobantePagoNotification`, aviso `comprobante` en
      `pedido_avisos`, con registro y reintento como el de «listo para recoger»).
    - `GET /pedidos/{pedido}/comprobante?formato=ticket|pdf` (personal, o el Cliente dueño) y
      `GET /pedidos/seguimiento/{token}/comprobante` (invitado). Sin formato, el del canal. 404 con
      "Este pedido todavía no tiene comprobante…" si el pago no está completo. Los pedidos traen
      `comprobante` (el vigente: número y tipo; el detalle no viaja en los listados) y el historial
      muestra cada comprobante generado o anulado.
    - Plantillas Blade `resources/views/comprobantes/pdf.blade.php` y `ticket.blade.php` (fuente
      DejaVu, solo las letras usadas: unos 25 KB). Datos del negocio en `config/fragua_real.php`
      (`negocio`): nombre, dirección y WhatsApp del sitio; el NIT se pone en `FRAGUA_REAL_NIT` y,
      vacío, no se imprime. El documento dice que no es factura electrónica (M06 §16).
    - Una mesa con varios pedidos tiene un comprobante por pedido (la tabla es por pedido).
    - CORREGIDO de paso: en este equipo de pruebas faltaba `resources/views/welcome.blade.php`;
      por eso `ExampleTest` fallaba aquí (en el repositorio sí existe). Ahora pasan las 249 pruebas.
    - Frontend: `components/pedidos/BotonComprobante.vue` ("Imprimir comprobante FR-…" o
      "Comprobante FR-… (PDF)", abre el documento en otra pestaña) en Pedidos del Mesero y del
      Administrador, en Cobrar (apenas el cobro completa el pago), en Mis pedidos y en el
      seguimiento del invitado.
37. **Fase 5, tarea 1b (13/10/2026): reimpresión controlada del comprobante.** Decisión del
    usuario del 13/10/2026: todo lo que el sistema imprime se puede volver a imprimir, pero una
    reimpresión no se confunde con el original y queda registrada. Migración `2026_10_19_000002`
    (`comprobantes.impresiones`); 5 pruebas en `ReimpresionComprobanteTest`.
    - Cuando el Mesero o el Administrador abre un comprobante (`ComprobanteService::imprimir()`),
      suma una impresión (con bloqueo de fila) y queda en la auditoría (`imprimir_comprobante`:
      quién, número, qué vez, formato, si estaba anulado). Desde la segunda vez el ticket dice
      "*** REIMPRESIÓN · COPIA n ***" y el PDF "REIMPRESIÓN · copia n", con la fecha y la hora.
    - Lo que abre el Cliente desde Mis pedidos o el invitado con su enlace, y el PDF del correo,
      no cuentan ni se marcan (es su propio comprobante).
    - Solo Administrador (`PedidoPolicy::verComprobantes`): `GET /pedidos/{pedido}/comprobantes`
      (todos, el vigente y los anulados, del más nuevo al más viejo, con motivo e impresiones) y
      `GET /pedidos/{pedido}/comprobantes/{comprobante}` (abre cualquiera; un anulado sale con su
      sello ANULADO y también cuenta como impresión). El Mesero reimprime solo el vigente.
    - La misma regla se aplicará a lo que falta imprimir: cierre diario en PDF, reportes guardados y
      comandas de cocina.
    - Frontend: `BotonComprobante.vue` con `personal` dice "Reimprimir comprobante FR-…" y "Ya se
      imprimió n veces: saldrá marcado «REIMPRESIÓN»"; Administrador > Pedidos tiene
      "Comprobantes" (`ComprobantesPedidoModal.vue`) con la lista y "Abrir"; `utils/documentos.ts`
      abre los PDF en otra pestaña y lee el mensaje de error que llega como Blob.
38. **Fase 5, tarea 2 (13/10/2026): cierre diario impreso y en PDF (M03).** RF-M03-28, 29,
    RNF-M03-09, 10, HU-M03-11, 12. Migración `2026_10_19_000003` (`cierres_diarios.impresiones`);
    5 pruebas en `CierreDiarioPdfTest`.
    - `GET /cierres-diarios/{cierre}/pdf` (solo Administrador, `CierreDiarioService::pdf()`): PDF
      tamaño carta con la plantilla `resources/views/cierres/pdf.blade.php`: negocio, día en
      letras, quién lo cerró y cuándo, los cuatro totales tal como se cerraron, el desglose
      guardado (ventas por tipo y por forma de pago, compras y gastos por categoría y por forma de
      pago), el arqueo de caja, las correcciones posteriores con quién las autorizó y los valores
      actualizados, la nota de resultado no contable (RF-M03-34), líneas de firma ("Cerró" y
      "Revisó") y al pie quién lo imprimió, cuándo y "Página n de m". Un cierre anterior a la Fase 2
      (sin desglose guardado) sale con sus totales y lo explica.
    - Regla de reimpresión: `App\Support\Documentos\RegistroImpresiones` (la usan ahora el
      comprobante y el cierre) suma la impresión con bloqueo de fila, la audita
      (`imprimir_cierre_diario`) y, desde la segunda, el PDF dice "REIMPRESIÓN · copia n".
    - Frontend: en un día cerrado, Compras y gastos > Cierre diario tiene "Imprimir / PDF del
      cierre" (luego "Reimprimir PDF del cierre", con aviso); en un día sin cerrar queda
      "Imprimir vista previa" (impresión del navegador) y la hoja dice que es una vista previa.
39. **Fase 5, tarea 3 (13/10/2026): exportación real de reportes (M08).** RF-M08-011, 012, 014,
    RNF-M08-006, HU-M08-009, 010, CA-M08-009, 010. Nueva dependencia
    `phpoffice/phpspreadsheet` ^5.0. Migración `2026_10_19_000004` (tabla `reporte_exportaciones`);
    7 pruebas en `ExportacionReportesTest` (una solo corre en PostgreSQL).
    - CORREGIDO: el "Excel" era un CSV y el PDF una sola página armada a mano que se cortaba.
    - `ReporteExportService::documento()` arma el documento con el mismo cálculo de la pantalla
      (`ReporteService::calcular()`, que también usan las rutas de cada reporte): título, periodo en
      letras, marca de parcial (RN-M08-05), resumen con nombres en español, la comparación con el
      periodo anterior si la pantalla compara (`comparar`, RF-M08-010) y las tablas del detalle
      (más y menos vendidos con el mismo tope de la pantalla, por categoría, todos los productos,
      por tipo de venta, por método de pago, compras y gastos por categoría, reservas por estado y
      ocupación por mesa). Reemplaza a `ReporteService::aTabla()`.
    - PDF (`resources/views/reportes/pdf.blade.php`, carta): las páginas que haga falta, encabezados
      de tabla repetidos, "Página n de m" (`App\Support\Documentos\PiePagina`, también la usa el
      cierre) y al pie quién lo generó y cuándo.
    - Excel `.xlsx` real: hoja "Resumen" (con la comparación y la nota del resultado operativo) y
      una hoja por tabla, con valores numéricos, formato de pesos, encabezados fijos y filtros.
    - RNF-M08-006: cada archivo se guarda en el disco privado (`storage/app/private/reportes/...`) y
      queda en `reporte_exportaciones` (reporte, formato, periodo, parcial, comparar, archivo,
      quién, descargas). `GET /reportes/exportaciones` las lista y
      `GET /reportes/exportaciones/{id}/descargar` entrega el mismo archivo, sin recalcular; cada
      descarga posterior suma y queda en la auditoría (`descargar_reporte`). Si el archivo ya no
      está en el servidor responde 410 con el motivo. La exportación sigue auditada como
      `exportar_reporte`, ahora con el id del archivo y si comparaba.
    - Sobre la regla de reimpresión: un archivo guardado no se puede marcar "REIMPRESIÓN" sin
      regenerarlo, y RNF-M08-006 pide no regenerarlo. Por eso cada documento dice cuándo y quién lo
      generó, y cada descarga queda registrada.
    - Frontend: Reportes exporta con la comparación de la pantalla y muestra "Exportaciones
      guardadas" con "Descargar"; `utils/documentos.ts` gana `guardarArchivo()`.
40. **Fase 5, tarea 4 (13/10/2026): uso de mesas con pedidos locales (M08).** RF-M08-009,
    HU-M08-007, CA-M08-007. Sin migración; 3 pruebas en `UsoDeMesasTest`. Con esta tarea la Fase 5
    quedó completa.
    - CORREGIDO: la ocupación de cada mesa solo contaba reservas completadas, así que una mesa llena
      de clientes sin reserva salía "sin uso".
    - `ReporteService::usoDeMesas()` cuenta ahora los pedidos locales no cancelados del periodo,
      agrupados en visitas: un pedido abre una visita nueva cuando todos los anteriores de esa mesa
      ya estaban finalizados o cancelados (la mesa se había liberado); los que llegan con la mesa
      ocupada son de la misma visita. Por mesa: visitas, pedidos, ventas (lo finalizado), minutos
      promedio por visita (del primer pedido al cierre del último, solo visitas cerradas) y reservas
      completadas. Lista todas las mesas activas, también las que no se usaron, de la más usada a la
      menos usada. El resumen suma `visitas` y `pedidos_locales`, y `mesas_con_uso` cuenta las
      mesas con visitas o reservas completadas. Las cifras de reservas no cambian (CA-M08-007).
    - La exportación (punto 39) trae la tabla "Ocupación por mesa" con esas columnas.
    - Frontend: Reportes > "Ocupación por mesa" muestra visitas, ventas, minutos promedio y reservas
      de cada mesa, y "Sin uso en el periodo" en las que no se usaron.

41. **Fase 6, tarea 1 (13/10/2026): pasarela Wompi con confirmación automática (M06, M07, M05).**
    Decisión D4: Wompi, desarrollado contra su sandbox, con la aprobación manual de respaldo.
    RF-M06-006, 007, 008, 017, RN-M06-05, RF-M07-013 a 015, RF-M05-029, 030. Migración
    `2026_10_20_000001` (tabla `transacciones_pasarela`; `pagos.confirmado_via` y `confirmado_por`;
    `reservas.deposito_confirmado_via`); 15 pruebas en `PasarelaWompiTest`, con eventos firmados y
    la API de Wompi simulada (`Http::fake`).
    - Interfaz `App\Support\Pasarela\PasarelaPago` y su adaptador `WompiPasarela` (enlazado en
      `AppServiceProvider`). Cambiar de proveedor es escribir otro adaptador.
    - Cobro: el Cliente elige pagar en línea y `PagoService::iniciarPagoEnLinea()` (o
      `ReservaService::iniciarDepositoEnLinea()` para el depósito) deja el pago "pendiente" como
      antes y crea el intento en `transacciones_pasarela`. Ese intento lleva una referencia secreta
      (`FRP-…` o `FRR-…` y 20 caracteres al azar) y vence a los 30 minutos. La respuesta trae
      `checkout.url`: el Web Checkout de Wompi con la firma de integridad (sha256 de referencia +
      monto en centavos + moneda + vencimiento + secreto). Con la pasarela se cobra exactamente lo
      que falta, no lo que mande el navegador.
    - Confirmación: `POST /api/pasarela/wompi/eventos` (público, sin sesión). Solo aplica un evento
      `transaction.updated` cuya firma cuadre: sha256 de los valores de `signature.properties` +
      `timestamp` + el secreto de eventos, y además el encabezado `X-Event-Checksum` si viene.
      Firma mala: 401 y nada cambia. `PasarelaPagoService::aplicar()` es idempotente (un evento
      repetido no hace nada la segunda vez) y aprueba o rechaza el pago o el depósito
      (`confirmado_via = pasarela`, auditado como `confirmar_pago_en_linea` /
      `rechazar_pago_en_linea` / `confirmar_deposito_reserva` con `via`). RN-M06-05 y RF-M06-007
      siguen igual: `PedidoService::confirmar()` no confirma con un pago sin aprobar.
    - Página de retorno: Wompi devuelve al Cliente a `/pago/retorno/{referencia}?id=…`. El frontend
      llama `GET /api/pasarela/transacciones/{referencia}` (público, con límite de intentos), que
      consulta la transacción en Wompi. Un `id` de otra referencia se ignora. La respuesta no trae
      la respuesta cruda de Wompi.
    - RF-M06-008: al reintentar, se pregunta antes por el intento abierto. Si nunca llegó a Wompi
      queda "abandonado" y el pago rechazado; si sigue en proceso (Nequi esperando aprobación) se
      avisa en vez de cobrar dos veces; si ya se aprobó, se dice.
    - Conciliación: `php artisan pasarela:conciliar`, cada 5 minutos con el programador. Pregunta
      por los intentos pendientes de más de 2 minutos, por si un evento se perdió, y cierra como
      abandonados los que vencieron sin pagarse.
    - El sistema no decide solo con el dinero: marca "por revisar" (`requiere_revision`, auditado
      `pago_pasarela_por_revisar`) estos casos:
      - un monto distinto;
      - un aprobado por Wompi que ya estaba rechazado a mano, anulado o abandonado;
      - un rechazo de Wompi a un pago aprobado a mano;
      - un pedido cancelado que ya estaba pagado;
      - un depósito aprobado cuya reserva ya no se puede confirmar (mesa tomada);
      - un reembolso que Wompi no hizo.
      El Administrador los ve en Caja > "Pagos en línea" (`GET /pasarela/estado`,
      `GET /pasarela/transacciones?revision=1`) y los cierra con una nota
      (`POST /pasarela/transacciones/{id}/revisada`, auditado `revisar_pago_pasarela`).
    - Reembolso (RF-M06-017, RN-M05-17): reembolsar un pago en línea en Caja, o cancelar una reserva
      con depósito pagado en línea, se lo pide también a Wompi (`reembolso_pasarela` en la
      auditoría). Wompi solo anula por API los pagos con tarjeta (`POST /transactions/{id}/void`).
      Los de Nequi, botón Bancolombia y PSE quedan "no_soportado", con la indicación de devolverlos
      desde el panel de Wompi. Si la llamada falla, la anulación o la cancelación siguen en pie y
      se reintenta con `POST /pasarela/transacciones/{id}/reembolsar`.
    - Respaldo (D4): sin `PASARELA_PROVEEDOR=wompi` o sin alguna de las cuatro llaves, todo sigue
      como antes: el pago queda en revisión y lo aprueba el Mesero o el Administrador, ahora con
      quién lo hizo en la auditoría. Con la pasarela activa, esa aprobación manual sigue disponible.
    - CORREGIDO (seguridad): `POST /reservas/{id}/deposito/en-linea` aceptaba cualquier número de
      reserva, que es consecutivo. Ahora exige el enlace secreto de la reserva (`token`), que
      `POST /reservas` devuelve una sola vez a quien la crea desde la web. El depósito también se
      paga o se reintenta desde el enlace del correo (`POST /reservas/enlace/{token}/deposito`).
    - Frontend:
      - Checkout, Seguimiento, Mis pedidos, Reservar y Mi reserva mandan al checkout de Wompi
        (`utils/pasarela.ts`).
      - Nueva página `PagoRetornoView.vue`. Se actualiza sola mientras Nequi espera la aprobación y
        lleva al pedido o a la reserva, o a reintentar.
      - Cobrar (Mesero) y Reservas (Administrador) dicen "En proceso en Wompi · se confirma solo" y
        dejan "Aprobar a mano" de respaldo.
      - Caja (Administrador) muestra el estado de la pasarela, la URL de eventos, los pagos por
        revisar (`PagosPasarelaPorRevisar.vue`) y, en cada pago en línea, cómo se aprobó y cómo
        quedó su reembolso.
    - Configuración en el servidor: llaves `WOMPI_*` en `.env` y la URL de eventos en Wompi >
      Desarrolladores (ver README-ENTREGA).
42. **Fase 6, tarea 2 (13/10/2026): comandas de cocina impresas desde el navegador (M02, M07).**
    Decisión D5: en V1 el Mesero imprime el ticket desde el navegador, sin un agente local.
    RF-M02-L-011, RF-M02-T-004 a 007, RN-PED-06, 09, 17 a 19, RNF-M02-001, 004, 005, 011, 014, 016,
    HU-M02-016, 017, RF-M07-020, 022. Migración `2026_10_20_000002` (tabla `comandas`); 7 pruebas
    en `ComandasTest`. Con esta tarea la Fase 6 quedó completa.
    - `ComandaService` arma cada comanda a partir del pedido y la guarda congelada en
      `comandas.contenido` (sin precios). Los tipos son:
      - inicial: al confirmar (RN-PED-06, RF-M07-020). Lleva las líneas, lo que trae cada combo,
        extras y notas.
      - actualización: un cambio con el pedido confirmado (RN-PED-09, RF-M07-022). Lleva solo lo
        que cambió: agregar, quitar o cambiar, con la cantidad anterior.
      - cancelación: se cancela un pedido que la cocina ya tenía.
    - Para no sacar hojas de más:
      - si la cocina todavía no recibió la inicial, un cambio la rehace;
      - si la última hoja de cambios no se ha impreso, el siguiente cambio se suma a ella;
      - si se cancela un pedido que nunca llegó a la cocina, su comanda queda "descartada".
    - Idempotencia (RF-M02-T-007, RNF-M02-004): cada comanda tiene una clave única (`pedido:N:inicial`,
      `pedido:N:modificacion:M`, `pedido:N:cancelacion`). Confirmar dos veces, un doble clic o dos
      pantallas a la vez nunca generan dos comandas.
    - Un fallo nunca deshace el pedido (RN-PED-17, RNF-M02-001/005): si generar la comanda falla, el
      pedido queda confirmado y el error se reporta. `GET /comandas/pendientes` crea la comanda que
      falte a todo pedido confirmado en las últimas 24 h (`asegurarIniciales`).
    - El estado de impresión es independiente del pedido (RN-PED-19, RNF-M02-014):
      - pendiente → impresa al abrir el ticket (`GET /comandas/{id}/ticket`, PDF de 80 mm);
      - "No salió" (`POST /comandas/{id}/no-impresa`, auditado `comanda_no_impresa`) la deja
        "fallida" y la devuelve a pendientes (HU-M02-016);
      - una impresión que salió no se repite sola (RN-PED-18).
    - Regla de reimpresión: la primera impresión que sale es el original. Desde la segunda, el ticket
      dice "REIMPRESIÓN · COPIA n" y cada impresión queda en la auditoría (`imprimir_comanda`, con
      `RegistroImpresiones`). Una que no salió vuelve a imprimirse como original, porque la cocina
      nunca la tuvo.
    - Pantallas:
      - `GET /comandas/pendientes` alimenta la franja "comandas por imprimir" (RNF-M02-011);
      - `GET /pedidos/{id}/comandas` da el historial de comandas de un pedido;
      - el historial del pedido (`historial()`) suma cada comanda enviada e impresa;
      - los pedidos traen sus comandas (`CON_DETALLES`), sin el contenido;
      - `confirmar` responde el pedido con sus comandas.
    - Frontend:
      - Confirmar en Mesero > Pedidos o Administrador > Pedidos abre la comanda para imprimir (la
        pestaña se abre en el clic para que el navegador no la bloquee).
      - Cada pedido tiene "Imprimir comanda" o "Reimprimir comanda" y "¿No salió? Avisar"
        (`BotonComanda.vue`).
      - Todas las pantallas del Mesero, y Administrador > Pedidos, muestran la franja
        `ComandasPendientes.vue`, que se actualiza cada 15 s y al confirmar, editar o cancelar.
      - "Impresión de comandas en este equipo" (`utils/comandas.ts`, guardado en el navegador):
        abrir la comanda al confirmar (prendido) y abrir solas las comandas nuevas (para el equipo
        de la cocina). Con Chrome en modo `--kiosk-printing` salen sin el cuadro de impresión.
    - Lo que queda a medias, por D5: RF-M02-T-006 (reintento automático). En V1, el equipo de la
      cocina con "abrir solas" y Chrome en impresión automática se acerca, pero el navegador no puede
      saber si el papel salió: lo confirma una persona. La impresión 100 % automática necesita un
      agente local junto a la impresora.

## Qué falta a propósito

Cada uno de los puntos abiertos ya está documentado en los documentos oficiales de módulo (no son
brechas de documentación, solo de implementación técnica):

- **Tabla `configuraciones`**: RESUELTO (28/09/2026). RF-M07-030 y RN-M05-08/13 exigen que el
  Administrador edite estos valores "en cualquier momento"; ahora viven en la tabla
  `configuraciones` (`ConfiguracionController`, `GET/PUT /configuraciones`, solo Administrador) y
  `GET /configuracion` (público) los lee de ahí. `config/fragua_real.php` quedó solo como valor de
  respaldo. El valor inicial del depósito de reserva es $20.000 COP
  (confirmado el 06/10/2026 y documentado en RN-M05-14).
- **Pasarela de pago real (Nequi/Bancolombia)**: RESUELTO (13/10/2026, punto 41) con Wompi (D4), que
  cubre Nequi y el botón Bancolombia con un solo contrato. Falta solo lo que no es código: la cuenta
  comercial de producción y sus llaves. Mientras tanto se prueba con las llaves del sandbox, y la
  aprobación manual sigue de respaldo.
- **Impresión 100 % automática en la impresora POS**: pendiente por decisión (D5, 13/10/2026). Las
  comandas existen, con su estado de impresión, y se imprimen desde el navegador del Mesero
  (punto 42). Sin una persona de por medio, hace falta un agente local junto a la impresora,
  porque un servidor en la nube no ve la impresora del local. Queda para después de V1.
- **Alta de Administrador/Mesero**: RESUELTO. `UsuarioController` (solo Administrador) lista, crea
  cuentas de Mesero/Administrador, las activa/desactiva y revoca sus sesiones (RF-M01-001).
  `AuthController::register()` solo expone el autorregistro de Cliente (RF-M01-014).
- **Exportación real de reportes (M08)**: RESUELTO (13/10/2026, punto 39). Antes: documentado como pendiente en
  `M08_Reportes_e_Informes_Fragua_Real.docx` sección 16 ("Definir el formato exacto y la
  plantilla visual de los archivos exportados"), sobre la base de RF-M08-011/012.
  `ReporteExportService` genera un Excel como CSV real (`fputcsv`, Excel lo abre
  nativamente — falta una librería como `phpoffice/phpspreadsheet` para un `.xlsx` con
  formato) y un PDF real de una sola página sin dependencias externas (un reporte más largo
  se trunca — falta paginación real y una librería como `dompdf`).
- **Caché de reportes de periodos ya cerrados**: recomendación de arquitectura explícita en
  `M08...docx` sección 15; la pantalla sigue recalculando cada consulta (los archivos exportados sí
  quedan guardados desde el punto 39).
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
