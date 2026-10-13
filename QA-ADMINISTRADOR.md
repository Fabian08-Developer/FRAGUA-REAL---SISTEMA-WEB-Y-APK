# QA del Administrador — lista de pruebas por pantalla

Cierra el hueco que `README-ENTREGA.md` reconoce: las pantallas del Administrador se probaron
de forma puntual, no en una pasada completa. Marca cada casilla al probarla y anota la fecha.
Antes de empezar: backend y frontend corriendo, base de datos con `php artisan migrate --seed`
y sesión iniciada como `admin@fraguareal.com`. Para los puntos que cruzan roles, ten abierta
otra ventana con el Mesero.

Cada punto cita el requisito oficial en el que se apoya.

## 0. Configuración (RF-M07-030, RF-M05-028)

La pantalla Administrador > Configuración se construyó el 06/10/2026 (antes el backend ya
permitía editar los valores, pero no había dónde hacerlo).

- [ ] Se abre desde el menú ("Configuración") y muestra $2.000 de domicilio y $20.000 de depósito en una base recién sembrada.
- [ ] Cambiar el domicilio y guardar: un domicilio nuevo en Checkout cobra el valor nuevo; uno ya creado conserva el anterior.
- [ ] Cambiar el depósito y guardar: Reservar mesa pide el valor nuevo; una reserva ya creada conserva el anterior.
- [ ] Valor vacío o con letras: error bajo el campo, sin llamar al servidor.
- [ ] "Guardar cambios" queda desactivado mientras no haya cambios.
- [ ] Un Mesero no puede abrir `/admin/configuracion` ni llamar `PUT /configuraciones` (403).
- [ ] "Tiempos de las reservas" (Fase 4, tarea 2 — RF-M05-017/026): muestra 15, 120 y 60 en una
      base nueva, con su texto ("se avisa al cliente 2 horas antes"). Valores fuera de rango (0,
      3000, letras) muestran el error bajo el campo sin guardar. Al guardar, Reservar mesa del
      Cliente dice la nueva tolerancia y Auditoría registra el cambio.
- [ ] "Tareas automáticas" (Fase 4, tarea 1): sin cron ni trabajador, dice "detenido" en ambos.
      Con `php artisan schedule:work` y `php artisan queue:work` corriendo (o el cron y
      Supervisor en el VPS), al minuto ambos dicen "activo". Muestra pendientes, fallidos y si el
      correo se envía de verdad. El botón de recargar vuelve a consultar.

## 1. Dashboard
- [ ] Muestra ventas del día, pedidos activos por canal y mesas ocupadas.
- [ ] Con un pedido finalizado hoy, las ventas coinciden con Reportes > Ventas totales del mismo día (RNF-M08-002).
- [ ] Con la base vacía no falla (ceros, no errores).

## 2. Usuarios (M01)
- [ ] Listar con búsqueda por nombre/correo y filtro por rol.
- [ ] Crear Mesero y Administrador (RF-M01-001); correo repetido → mensaje claro.
- [ ] Desactivar una cuenta: esa persona ya no puede iniciar sesión.
- [ ] Activar de nuevo: vuelve a entrar.
- [ ] Revocar sesiones: el token abierto en otra ventana deja de funcionar (RF-M01-010/023).
- [ ] Un Mesero no puede abrir `/admin` ni llamar `/usuarios` (403).
- [ ] "Editar" (Fase 3, tarea 2 — RF-M01-003/005): cambiar nombre, correo y teléfono de un
      Mesero; el correo de otra cuenta muestra "Ese correo ya lo usa otra cuenta". Su sesión
      sigue abierta.
- [ ] Cambiar el rol de un Mesero a Administrador: el modal avisa del cierre de sesiones; al
      guardar, la ventana donde él estaba conectado lo saca al login, y al entrar ve el panel del
      Administrador. En Auditoría aparecen "Edición de datos de usuario" y "Cambio de rol de
      usuario" con el antes → después.
- [ ] Un Cliente puede pasar a Mesero. En tu propia cuenta el rol aparece bloqueado. La
      contraseña no aparece en el formulario.
- [ ] Nombre de usuario (Fase 3, tarea 3 — RF-M01-007/011): ponerle "mario.m" a un Mesero al
      crearlo o editarlo; la lista lo muestra como "@mario.m" y la búsqueda lo encuentra. Uno
      repetido (también con mayúsculas, "Mario.M") o con tilde, espacio o "@" se rechaza con un
      mensaje claro. Dejarlo vacío lo quita.
- [ ] En el login, "mario.m" (o "MARIO.M") y la contraseña entran; su correo también. Usuario o
      contraseña equivocados muestran el mismo mensaje. En Auditoría el inicio de sesión dice por
      dónde entró.
- [ ] Mi perfil: elegir o cambiar el propio nombre de usuario y entrar con él.
- [ ] Sesiones (Fase 3, tarea 4 — RF-M01-010/024): entrar como Mesero en el celular y en el PC.
      En Usuarios, su botón dice "Sesiones (2)"; la ventana muestra cada dispositivo con IP,
      último uso y vencimiento. "Cerrar" en el celular: en el celular la app vuelve al login y el
      PC sigue. "Cerrar todas sus sesiones" lo saca de todos. Queda en Auditoría.
- [ ] Mi perfil > Sesiones abiertas: la actual dice "Esta sesión"; "Cerrar las demás" deja solo
      la actual; cambiar la contraseña también cierra las demás y la lista se actualiza.
- [ ] "Actividad" en un usuario abre Auditoría filtrada por él; el aviso azul permite ver también
      lo que pasó sobre su cuenta (intentos fallidos con su correo, desactivación…).

## 2b. Auditoría (M01 — Fase 3, tarea 1)
RF-M01-017/024/025, HU-M01-015/020, RNF-TRANS-022/023, RN-USR-12/13.
- [ ] Se abre desde el menú ("Auditoría") y muestra los últimos 7 días, del más reciente al más
      antiguo.
- [ ] En otra ventana, intentar entrar con una contraseña mala: aparece "Intento de inicio de
      sesión fallido" en rojo, sin usuario, con el correo usado, el motivo y la IP; la tarjeta
      "Inicios fallidos (24 h)" sube. La contraseña escrita no aparece en ningún lado.
- [ ] 3 intentos fallidos desde el mismo equipo: aparece el aviso de la IP y "Ver intentos" la
      filtra.
- [ ] Entrar bien, cerrar sesión, cambiar la contraseña en Mi perfil y recuperar la contraseña
      por correo: cada paso aparece con su texto (inicio, cierre, cambio, solicitud y
      restablecimiento).
- [ ] Crear un Mesero y cambiar el valor del domicilio en Configuración: aparecen "Alta de usuario
      del personal" y "Cambio de configuración" con el antes → después.
- [ ] Filtros: fechas, usuario, grupo (la lista de acciones se reduce al grupo), acción, "Sobre
      qué" + número (p. ej. Pedido 12), IP y texto (prueba un motivo con tilde). "Limpiar" vuelve
      a los últimos 7 días. "Solo seguridad y sesiones" deja solo ese grupo.
- [ ] Abrir un registro muestra sus detalles legibles (motivo, antes → después, navegador
      completo) y el número del registro de auditoría.
- [ ] No hay botones para editar ni borrar registros. Un Mesero no puede abrir
      `/admin/auditoria` ni llamar `GET /auditorias` (403).
- [ ] En el teléfono (~390 px) la pantalla no tiene scroll horizontal.

## 3. Catálogo (M04)
- [ ] Crear, editar y desactivar categoría y producto.
- [ ] Variantes: crear con precio propio; el Cliente la ve en el menú.
- [ ] Extras: uno general y uno específico de producto (RN-M04-05).
- [ ] Un extra general sin productos elegidos aparece en el Menú, el QR y "Nuevo pedido" del
      Mesero con cualquier producto; al marcarlo en "Extras generales que ofrece" de un producto,
      solo aparece con ese producto (RF-M04-010/011).
- [ ] Dar de baja un producto que está en un combo: aparece el aviso del combo desactivado y el
      combo ya no se ofrece; activarlo de nuevo con ese producto se rechaza; reemplazándolo, sí (RN-M04-12).
- [ ] Desactivar una categoría: sus productos y los combos que los usan desaparecen del Menú y del
      QR; al reactivarla vuelven (RF-M04-003).
- [ ] Precio en cero o un nombre de producto o categoría repetido: mensaje claro bajo el campo.
- [ ] Cambiar el precio de un producto queda en la auditoría con el valor anterior y el nuevo (RF-M04-022).
- [ ] Combos: crear con vigencia; se oculta si un componente está agotado (RN-M04-11).
- [ ] Promociones: crear, editar y desactivar; cada cambio queda en la auditoría (RF-M04-039/040).
- [ ] Promoción automática de un producto con 20 %: el menú la marca "−20 %" con el precio tachado,
      y un pedido nuevo de ese producto sale con el descuento (RF-M04-035).
- [ ] Una promoción de almuerzo (12:00 a 15:00) no se aplica a las 9:00 a. m. y sí a la 1:00 p. m.
      (hora de Colombia); una de "jueves" solo los jueves.
- [ ] Dos promociones vigentes a la vez (una de producto y una al total) se suman en el mismo
      pedido; el domicilio no se rebaja (RF-M04-038, RN-M04-20).
- [ ] Una promoción de tipo "Manual" no se aplica sola: aparece en el botón "Descuento" del pedido.
- [ ] Alternar agotado/disponible y verlo reflejado de inmediato en el menú del Cliente (RF-M04-016/017).
- [ ] Tipo de venta Bebida/Alimento/Otro queda guardado y aparece en Reportes.
- [ ] Catálogo > "Agotados y disponibilidad": buscar por nombre, filtrar por categoría y "Solo
      agotados"; agotar un tamaño (variante): el Cliente ya no lo ve y un pedido con él se rechaza
      (RF-M04-016/018/025). Volver a disponible lo devuelve al menú (RF-M04-017).
- [ ] Con el Mesero: la pestaña Menú hace lo mismo; cada cambio aparece en la auditoría.

## 4. Mesas (M05)
- [ ] Crear mesa: queda Libre con su QR; nombre repetido → error (CA-M05-001).
- [ ] "Cambiar nombre": el QR sigue siendo el mismo; un nombre de otra mesa se rechaza (RF-M05-002).
- [ ] Desactivar (con historial no se borra) y reactivar; todo queda en la auditoría (RF-M05-021).
- [ ] Regenerar QR: el anterior deja de funcionar (CA-M05-004).
- [ ] Ver QR de una mesa: se ve la imagen del QR; "Descargar PDF" baja el PDF con el nombre de la
      mesa y "Imprimir" abre la impresión, sin depender de internet (librerías locales).
- [ ] Liberar mesa con pedidos activos: bloqueado.
- [ ] Liberación forzada: exige motivo y deja registro en auditoría (RF-M05-011). Probar también con el Mesero, que tiene el botón "Forzar liberación…" en Mesero > Mesas (decidido el 06/10).

## 5. Reservas (M05)
- [ ] Listado con filtro por estado.
- [ ] Reserva web nueva queda en pendiente de pago hasta confirmar el depósito (RN-M05-15).
- [ ] Confirmar y rechazar un depósito en línea; el rechazo permite reintentar.
- [ ] Registrar depósito manual en una reserva por teléfono (RN-M05-16).
- [ ] Cancelar antes de la hora: el depósito queda reembolsado (RN-M05-17).
- [ ] Marcar no-show: el botón solo aparece cuando ya pasó la hora + la tolerancia (15 min); el
      depósito se retiene y aparece en "depósitos retenidos" (RN-M05-07/18, HU-M05-015).
- [ ] Confirmar llegada (cliente llegó): la mesa pasa a Ocupada; al tomar el primer pedido, la
      cuenta de la mesa ya descuenta el depósito ("Incluye $20.000 del depósito de la reserva")
      y Reservas dice "abonado $20.000 a la cuenta" (RF-M05-034, RN-M05-19).
- [ ] Sin pulsar "Confirmar llegada": el primer pedido en la mesa reservada completa la reserva y
      abona el depósito.
- [ ] Un pedido de $10.000 con un depósito de $20.000: se abonan $10.000 y Reservas dice que
      quedan $10.000; el pedido siguiente de esa mesa recibe el resto.
- [ ] Bajar la cantidad de un pedido cubierto por el depósito: el abono baja solo y el historial
      del pedido muestra el ajuste; cancelar ese pedido pasa su abono a otro pedido de la mesa.
- [ ] Registrar un depósito presencial con la caja cerrada: rechazado con un mensaje claro.
- [ ] "Nueva reserva": al elegir fecha y hora, el selector solo muestra mesas libres a esa hora;
      la reserva queda "Registrada por" tu nombre y se abre el depósito manual (RF-M05-013).
- [ ] Otra reserva de la misma mesa una hora después: rechazada; dos horas y media después: aceptada.
- [ ] El depósito manual solo ofrece efectivo, tarjeta y transferencia (RN-M05-16).
- [ ] Con el Mesero: la pestaña Reservas hace lo mismo, sin el botón "Depósitos retenidos" (RF-M05-035).

### 5b. Reservas automáticas (Fase 4, tarea 3 — RF-M05-014/016/024, RN-M05-07)
Requiere el programador corriendo (`php artisan schedule:work` en desarrollo, o el cron en el
VPS) y, para el correo, la cola (`php artisan queue:work`). Configuración > Tareas automáticas
debe decir "activo".
- [ ] Confirmar el depósito de una reserva para mañana: la mesa sigue Libre (antes quedaba
      Reservada de inmediato). Cada reserva confirmada dice "Si no llega, se libera sola a las …".
- [ ] Una reserva para dentro de 50 minutos: en el siguiente minuto la mesa pasa a Reservada.
- [ ] Una mesa Ocupada con una reserva cercana sigue Ocupada; al liberarla, en el siguiente
      minuto pasa a Reservada.
- [ ] Sin llegar el cliente: a la hora + 15 min la reserva pasa sola a "No asistió", el depósito
      queda retenido, la mesa vuelve a Libre y Auditoría muestra el no-show automático sin usuario.
- [ ] Recordatorio: una reserva confirmada con más de 2 h de anticipación recibe el correo 2 h
      antes (una sola vez) y Reservas dice "Recordatorio enviado a las …". Una reserva hecha 1 h
      antes no recibe recordatorio.
- [ ] Si el cliente pide en una mesa que aún está Libre pero tiene su reserva dentro de la hora
      previa, ese primer pedido cuenta como su llegada y se abona el depósito.
- [ ] Cambiar la tolerancia a 30 en Configuración: la siguiente reserva se libera a los 30 min.

### 5c. Avisos de reserva y cancelación por enlace (Fase 4, tarea 4 — RF-M05-018/023/025/027)
Requiere la cola corriendo y un `MAIL_MAILER` que envíe (con `log` los correos quedan en
`storage/logs/laravel.log`). Usar un correo de cliente al que tengas acceso.
- [ ] Confirmar el depósito de una reserva web: llega "Reserva confirmada" con fecha, hora, mesa,
      depósito y el botón "Ver o cancelar mi reserva". En Reservas la reserva dice
      "Confirmación · enviado h:mm".
- [ ] Registrar un depósito en el local (reserva por teléfono con correo): también llega la
      confirmación.
- [ ] Cancelar una reserva desde Reservas: llega "Reserva cancelada" y dice cómo se devuelve el
      depósito. Auditoría muestra "Cancelación de reserva" con tu nombre.
- [ ] Un no-show (manual o automático): llega el aviso de "No asistió" con el depósito retenido.
- [ ] Con el correo mal configurado (p. ej. contraseña SMTP errada): la reserva igual se confirma;
      tras 3 intentos el aviso dice "falló" con el motivo. Corregir el SMTP y pulsar "Reintentar":
      pasa a "enviado". Auditoría muestra la falla y el envío.
- [ ] Abrir el enlace del correo en una ventana privada (sin sesión): muestra estado, fecha, mesa,
      depósito y tolerancia, sin datos de contacto. "Cancelar reserva" pide confirmar, la deja
      Cancelada con el depósito reembolsado y Auditoría la muestra "por enlace", sin usuario.
- [ ] El mismo enlace después de la hora de la reserva: ya no ofrece cancelar.
- [ ] Una reserva con depósito en efectivo: el enlace no la cancela y pide llamar o pasar por el
      local.
- [ ] Un enlace inventado o incompleto: "No encontramos esa reserva".

## 6. Caja (M06)
- [ ] Abrir turno (un solo turno por día, RN-M06-03); intentar abrir otro → rechazado.
- [ ] Sin turno abierto, la pantalla dice "sin turno" y el Mesero no puede cobrar (bug del 01/10).
- [ ] Un depósito de reserva en efectivo sube el efectivo esperado del día; el detalle del turno
      lo lista en "Depósitos de reserva". Cancelar esa reserva (devolver el depósito) lo resta.
- [ ] El día de la visita, el abono del depósito no vuelve a sumar al esperado: solo suma lo que
      el Mesero cobra encima (RN-M06-08).
- [ ] Con turno abierto, el Mesero cobra efectivo, tarjeta, transferencia y pago mixto.
- [ ] El Administrador **no** puede registrar un cobro (RF-M06-003/004).
- [ ] Anular un pago con motivo; el pedido recupera su saldo (RF-M06-017).
- [ ] Registrar un reembolso (no anulación) y ver la marca "Reembolsado" (RF-M06-017).
- [ ] Registrar un gasto en efectivo: el efectivo esperado del turno baja en ese valor (RF-M06-013).
- [ ] Cerrar turno: cifras de efectivo y ventas por método cuadran con los cobros hechos.
- [ ] Con el turno ya cerrado, anular un pago pide tu contraseña y aparece en "Ajustes
      extraordinarios"; el esperado y la diferencia del turno no cambian (RF-M06-019).
- [ ] Un pago en línea del día aparece en "Pagos en línea del día" del detalle del turno y se puede anular.
- [ ] Con el Mesero: dos pedidos en la misma mesa; Mesas muestra la suma y "Ver cuenta" lista ambos
      (RF-M02-L-019/020). En Cobrar, "Cobrar mesa" con una parte en efectivo y el resto con tarjeta
      deja tres pagos y la mesa libre; el turno suma el efectivo correcto.
- [ ] La cuenta de una mesa liberada y vuelta a ocupar no trae los pedidos del cliente anterior.
- [ ] Historial de turnos incluye el turno cerrado.

### 6b. Comprobante de pago (Fase 5, tarea 1 — RF-M06-009/010, RN-M06-07, HU-M02-019)
Antes de probar: `composer update barryvdh/laravel-dompdf --with-all-dependencies` y
`php artisan migrate` en `backend`.
- [ ] Con el Mesero, un pedido local de $40.000: cobrar $15.000 no da comprobante; cobrar el resto
      con tarjeta muestra "Imprimir comprobante FR-000001" en Cobrar y en Pedidos.
- [ ] El botón abre en otra pestaña un ticket de 80 mm con el negocio, el número, la mesa, las
      líneas, el subtotal, los descuentos, el total y los dos pagos (efectivo y tarjeta); se imprime
      con Ctrl+P (en la impresora POS, papel de 80 mm). Dice "No es factura electrónica".
- [ ] El siguiente pedido pagado toma el número FR-000002 (consecutivo sin saltos).
- [ ] Anular el pago de un pedido con comprobante: el botón desaparece, "Ver historial" dice
      "Comprobante FR-… anulado: El pedido ya no está pagado por completo." y Auditoría muestra
      "Comprobante de pago anulado". Volver a cobrar saca un comprobante con un número nuevo.
- [ ] Un domicilio o una recogida pagados: "Comprobante FR-… (PDF)" abre un PDF media página con
      cliente, teléfono, dirección de entrega, domicilio y pagos. Si el cliente tiene correo
      (cuenta o correo opcional del invitado), le llega el PDF adjunto.
- [ ] Con un pago en línea: mientras está "en revisión" no hay comprobante; al aprobarlo, sí.
- [ ] El Cliente con cuenta lo descarga desde Mis pedidos (detalle del pedido) y el invitado desde
      su enlace de seguimiento. Otro cliente no puede descargarlo.
- [ ] Con `FRAGUA_REAL_NIT` en el `.env` del servidor, el comprobante muestra el NIT; vacío, no.
- [ ] Reimpresión: la primera vez el ticket sale normal; el botón cambia a "Reimprimir comprobante"
      y avisa "Ya se imprimió 1 vez". La segunda vez el ticket dice "*** REIMPRESIÓN · COPIA 2 ***"
      con la hora. Auditoría muestra las dos impresiones con quién las hizo.
- [ ] El comprobante que descarga el Cliente desde Mis pedidos (o el invitado con su enlace) nunca
      dice REIMPRESIÓN y no suma impresiones.
- [ ] Administrador > Pedidos > "Comprobantes" de un pedido al que se le anuló un pago: lista el
      vigente y el anulado con su motivo y cuántas veces se imprimió cada uno; "Abrir" en el anulado
      muestra el sello ANULADO. Con el Mesero no aparece esa lista.

### 6c. Pago en línea con Wompi (Fase 6, tarea 1 — RF-M06-006/007/008/017, RN-M06-05, RF-M05-030)
Antes de probar: `php artisan migrate`. Poner en el `.env` del backend las llaves del sandbox de
Wompi (`WOMPI_*`), `PASARELA_PROVEEDOR=wompi` y `FRONTEND_URL`, y correr `php artisan config:clear`.
Para que lleguen los eventos al equipo de desarrollo hace falta una URL pública (por ejemplo un
túnel); sin ella, la página de retorno y `php artisan pasarela:conciliar` igual confirman el pago.
- [ ] Sin llaves (o con `PASARELA_PROVEEDOR=manual`): Caja > "Pagos en línea" dice "Aprobación
      manual" y el pago en línea queda en revisión como antes; el Mesero lo aprueba en Cobrar y
      Auditoría dice "a mano" con su nombre.
- [ ] Con las llaves: Caja > "Pagos en línea" dice "Wompi activo (pruebas)" y muestra la URL de
      eventos, que es la que se pone en Wompi > Desarrolladores.
- [ ] Como invitado, una recogida pagada en línea con Nequi: el Checkout lleva a la página de pago
      de Wompi con el monto del pedido y los datos del invitado ya puestos.
- [ ] Pagar con el Nequi de prueba que Wompi aprueba: se vuelve a "Pago aprobado", con la
      referencia FRP-…. El pedido queda con el pago aprobado sin que nadie lo apruebe, ya se
      puede confirmar y tiene su comprobante en PDF.
- [ ] En Caja, ese pago dice "Confirmado por Wompi · Nequi · ref. FRP-…". En Auditoría aparece
      "Pago en línea aprobado" sin usuario.
- [ ] Pagar con el Nequi de prueba que Wompi rechaza: "El pago no se completó" con "Intentar de
      nuevo". Desde el seguimiento se paga otra vez sobre el mismo pedido (no se crea otro).
- [ ] Abrir el pago y cerrar la página de Wompi sin pagar: en el seguimiento sale "¿No terminaste
      el pago? Puedes intentarlo de nuevo" y el reintento abre otro pago. El anterior queda "Sin
      pagar (vencido)".
- [ ] Mientras Nequi espera la aprobación, la página "Tu pago está en proceso" se actualiza sola.
      En Cobrar el pago dice "En proceso en Wompi · se confirma solo", con "Aprobar a mano" de
      respaldo.
- [ ] Reserva web: después de crearla, "Pagar depósito" lleva a Wompi; al aprobarse, la reserva
      queda confirmada y llega el correo. En Reservas dice "Wompi: Aprobado · ref. FRR-…".
- [ ] Depósito rechazado: la página de retorno ofrece volver a la reserva y "Mi reserva" (el
      enlace) tiene "Pagar el depósito" para reintentar.
- [ ] Reembolsar en Caja un pago aprobado con tarjeta de prueba: queda "Reembolso solicitado a la
      pasarela" y en Wompi aparece anulado. Con Nequi: "Devolverlo desde el panel de la pasarela"
      y aparece en "Pagos en línea por revisar". Hacerlo en Wompi y "Marcar como revisado" con la
      nota de lo que se hizo.
- [ ] Cancelar una reserva con depósito pagado por Wompi: se pide la devolución (con Nequi queda
      por revisar).
- [ ] Cancelar un pedido ya pagado por Wompi: aparece en "Pagos en línea por revisar" con
      "reembólsalo desde Caja".
- [ ] Rechazar a mano en Cobrar un pago que luego Wompi aprueba: queda por revisar ("ya estaba
      rechazado").
- [ ] Enviar al webhook un evento con la firma cambiada (por ejemplo con Postman): responde 401 y
      no cambia nada.
- [ ] Un Mesero no puede abrir `GET /pasarela/estado` ni `/pasarela/transacciones` (403).

## 7. Compras y gastos (M03)
- [ ] Registrar una compra y un gasto; editar uno activo (RF-M03-14).
- [ ] Anular: nunca se borra y un anulado ya no se puede editar (RN-M03-04).
- [ ] La categoría se elige de la lista según el tipo (Compra: Alimentos, Bebidas, Empaques,
      Insumos, Otros; Gasto: Transporte, Limpieza, Servicios, Mantenimiento, Papelería, Otros);
      sin categoría no deja guardar.
- [ ] Anotar cantidad y unidad (por ejemplo "5 kilos") y observaciones; se ven en la lista.
- [ ] Adjuntar una foto JPG, una PNG y un PDF; "Ver soporte" los abre. Un Word o un archivo de
      más de 5 MB se rechaza con un mensaje claro (RF-M03-07, RNF-M03-06).
- [ ] Copiar la dirección del soporte y abrirla en una ventana sin sesión: no se ve (RNF-M03-11).
- [ ] Una fecha de compra futura se rechaza; una anterior a hoy sí se acepta.
- [ ] Filtros: por fechas, categoría, forma de pago, proveedor y estado; los totales de arriba de
      la lista cambian con el filtro y no cuentan los anulados (RF-M03-13).
- [ ] Corregir el valor de un registro: la lista dice quién lo corrigió y la auditoría guarda el
      valor anterior y el nuevo; anular muestra quién y cuándo (RF-M03-19).
- [ ] "Ver cálculo" de hoy: ventas, compras, otros gastos y resultado, con el desglose de ventas
      por tipo y por forma de pago, compras y gastos por categoría y por forma de pago, y el arqueo
      de la caja (RF-M03-21 a 26, RN-M06-12). Dice que no es la utilidad contable (RF-M03-34).
- [ ] Con la caja del día abierta, "Cerrar el día" está desactivado y lo explica (RN-M06-11);
      después de cerrar la caja, se cierra el día y los totales coinciden con lo registrado.
- [ ] No se puede cerrar dos veces el mismo día ni un día futuro.
- [ ] "Cierres anteriores" lista el cierre; al abrirlo se ve igual que al cerrarlo (RF-M03-27).
- [ ] Registrar un gasto con la fecha de un día cerrado: el formulario pide la contraseña y el
      motivo; con una contraseña equivocada no se guarda; con la correcta, el cierre muestra la
      corrección y los valores actualizados, y el cierre original no cambia (RF-M03-20, HU-M03-09).
- [ ] Corregir el valor y anular un registro de un día cerrado también piden la contraseña.

### 7b. Cierre diario impreso y en PDF (Fase 5, tarea 2 — RF-M03-28/29, RNF-M03-09/10, HU-M03-11/12)
- [ ] En un día cerrado, "Imprimir / PDF del cierre" abre en otra pestaña un PDF tamaño carta con
      el negocio, el día ("Martes 13 de octubre de 2026"), quién lo cerró y a qué hora, los cuatro
      totales, ventas por tipo y por forma de pago, compras y gastos por categoría y por forma de
      pago, el arqueo de caja, la nota de que no es la utilidad contable, las líneas de firma
      ("Cerró" y "Revisó") y al pie quién lo imprimió y "Página 1 de 1". Se imprime con Ctrl+P o se
      guarda.
- [ ] Un día con correcciones después del cierre: el PDF trae el cuadro "Correcciones después del
      cierre" (fecha, quién autorizó, qué cambió, motivo y montos) y los valores actualizados; los
      totales del cierre original no cambian.
- [ ] La segunda vez el botón dice "Reimprimir PDF del cierre" y avisa que ya se imprimió; el PDF
      dice "REIMPRESIÓN · copia 2". Auditoría muestra cada impresión ("Impresión o reimpresión del
      cierre diario") con quién la hizo.
- [ ] Un día sin cerrar solo ofrece "Imprimir vista previa": la hoja impresa dice "VISTA PREVIA —
      este día todavía no está cerrado".
- [ ] Abrir un cierre viejo desde "Cierres anteriores" y sacar su PDF también funciona.

## 8. Reportes (M08)
- [ ] Los 6 reportes cargan con la semana actual por defecto (RN-M08-03).
- [ ] Cambiar a mes y a rango personalizado.
- [ ] Comparación contra el periodo anterior equivalente (RF-M08-010).
- [ ] Periodo en curso aparece marcado como parcial (RN-M08-05).
- [ ] Resultado operativo = ventas − compras − gastos (RN-M08-06), con y sin cierre diario del día.
- [ ] Exportar a Excel y PDF; la exportación queda en auditoría (RF-M08-011/012/014).

### 8b. Exportación real (Fase 5, tarea 3 — RF-M08-011/012, RNF-M08-006, HU-M08-009/010)
Antes de probar: `composer update phpoffice/phpspreadsheet --with-all-dependencies` y
`php artisan migrate` en `backend`.
- [ ] "Exportar Excel" descarga un `.xlsx` (no un CSV) que Excel abre sin avisos: hoja "Resumen"
      con el título, el periodo en letras, quién lo generó y, si es la semana o el mes en curso,
      "Periodo en curso: las cifras son parciales"; y una hoja por tabla (por ejemplo "Por tipo de
      venta" y "Por método de pago"), con encabezados fijos, filtros y los valores como números en
      pesos (se pueden sumar).
- [ ] "Exportar PDF" de "Ventas por producto" con muchos productos: el PDF tiene las páginas que
      haga falta (no se corta), repite los encabezados de la tabla en cada página y dice "Página n
      de m" al pie.
- [ ] Con "Comparar con el periodo anterior" marcado, el PDF y el Excel traen la tabla de
      comparación (este periodo, anterior, variación y %), igual que la pantalla (CA-M08-009/010).
- [ ] Debajo de los reportes aparece "Exportaciones guardadas" con cada archivo (reporte, formato,
      periodo, "con comparación", "parcial", quién y cuándo). "Descargar" baja el mismo archivo
      aunque después haya ventas nuevas: no se regenera (RNF-M08-006). Auditoría muestra
      "Descarga de un reporte ya exportado".

### 8c. Uso de mesas con pedidos locales (Fase 5, tarea 4 — RF-M08-009, HU-M08-007)
- [ ] Atender una mesa sin reserva con dos pedidos (el segundo con la mesa todavía ocupada),
      cobrar, finalizar y liberarla; más tarde, otro pedido en la misma mesa. En Reportes >
      "Ocupación por mesa" esa mesa dice "2 visitas", las ventas y los minutos promedio.
- [ ] Una mesa con una reserva completada suma "1 reserva"; una mesa que no se usó dice "Sin uso
      en el periodo".
- [ ] Un pedido cancelado no cuenta como visita. Las cifras de reservas (creadas, canceladas,
      no-show) no cambian.
- [ ] Exportar "Uso de mesas" a Excel: la hoja "Ocupación por mesa" trae Mesa, Visitas, Pedidos,
      Ventas, Minutos promedio por visita y Reservas completadas.
- [ ] Un Mesero no puede abrir Reportes (403).

## 9. Pedidos (supervisión)
- [ ] Filtros por estado y canal; paginación con más de 20 pedidos.
- [ ] Confirmar, marcar preparando y cancelar con motivo (el Administrador cancela también lo confirmado).
- [ ] Con el Mesero: cancelar un pedido confirmado pide la contraseña de un Administrador; con una
      clave equivocada no se cancela (RN-M07-05).
- [ ] No hay botón de cobrar.
- [ ] El Administrador no ve "Confirmar entrega", "Confirmar recogida" ni "Finalizar pedido"
      (son del Mesero, RF-M07-025, RF-M02-T-016).
- [ ] Editar un pedido PENDIENTE: agregar un producto, cambiar una cantidad y quitar una línea;
      el total se recalcula en cada paso (RN-PED-07, RF-M02-L-004/005).
- [ ] Editar un pedido CONFIRMADO con una línea marcada como preparada: esa línea no se puede
      cambiar ni quitar; las demás sí (RN-PED-08, RN-PED-10).
- [ ] Un pedido en PREPARANDO, FINALIZADO o CANCELADO no muestra "Editar pedido".
- [ ] Quitar la única línea de un pedido: el sistema pide cancelarlo con motivo.
- [ ] Bajar la cantidad de un pedido ya pagado por debajo de lo pagado: rechazado con un mensaje claro.
- [ ] "Ver historial" muestra creación, confirmación, cada cambio con quién y la hora, y los pagos (HU-M02-020).
- [ ] Un domicilio muestra nombre, teléfono, dirección, barrio, referencia e indicaciones; una
      recogida, nombre y teléfono (RF-M07-003 a 007, RF-M07-010).
- [ ] Un pedido web pagado en línea muestra "Paga: En línea (Nequi)" o "(Bancolombia)"; uno
      contra entrega o por teléfono, "Contra entrega" o "Al recoger" (RF-M07-013/015).
- [ ] Con el Mesero: "Nuevo pedido" → Domicilio, sin barrio o sin referencia no deja continuar;
      con los datos completos crea el pedido con el valor de domicilio vigente (RF-M07-001).
- [ ] Con el Mesero: "Nuevo pedido" → Recogida crea el pedido sin costo de domicilio (RF-M07-R-001).
- [ ] "Descuento" en un pedido activo: 10 % con motivo; el total baja y aparece "Descuento manual
      10 %" con el motivo y quién lo aplicó (RF-M04-036, HU-M04-015).
- [ ] Sin motivo, con 0, con un valor negativo o con 120 % no deja aplicar (RN-M04-22/23).
- [ ] En un pedido con promociones automáticas, el descuento manual las reemplaza (no se suman);
      "Quitar descuento" las devuelve (RF-M04-037, RN-M04-21).
- [ ] Un descuento que dejaría el total por debajo de lo ya pagado se rechaza con un mensaje claro.
- [ ] El descuento aparece en "Ver historial" y en la auditoría con usuario, motivo y monto (RF-M04-039).
- [ ] Con el Mesero: el mismo botón "Descuento" funciona igual; "Nuevo pedido" muestra las
      promociones vigentes y el total con descuento antes de crear el pedido.
- [ ] Reportes > Ventas por producto: un pedido con descuento suma lo cobrado, no el precio lleno.
- [ ] Reportes > Ventas por tipo y método: el abono de un depósito sale como "Depósito de reserva".

### 9a. Listo para recoger (Fase 4, tarea 5 — RF-M07-R-012, RN-M07-R-03, HU-M07-012)
Requiere la cola corriendo y un `MAIL_MAILER` que envíe (con `log` el correo queda en
`storage/logs/laravel.log`). Usar un correo al que tengas acceso.
- [ ] Una recogida web de invitado con correo, confirmada y en preparación: los datos del cliente
      muestran el correo y aparece "¿Ya está listo? Al marcarlo le avisamos por correo a …".
- [ ] Pulsar "Listo para recoger": el pedido sigue en Preparando, todas sus líneas quedan marcadas
      como preparadas y el recuadro dice "Listo para recoger desde las …" y "Aviso enviado a … a las …".
- [ ] Llega el correo "Tu pedido #N está listo para recoger", con lo que falta por pagar (o que ya
      está pagado) y el botón "Ver mi pedido"; el enlace abre el seguimiento con el paso
      "Listo para recoger" (RN-M07-R-03).
- [ ] Una recogida sin correo (invitado que no lo dejó): se marca lista y la pantalla dice
      "Sin correo: avísale al cliente por teléfono".
- [ ] Una recogida de un Cliente con cuenta: el aviso llega al correo de su cuenta y Mis pedidos
      muestra "Listo para recoger".
- [ ] Con el correo mal configurado: tras 3 intentos el aviso dice que no se pudo enviar, con el
      motivo; corregir el SMTP y pulsar "Reintentar aviso": pasa a enviado. Auditoría muestra la
      falla y el envío ("Aviso de pedido enviado al cliente").
- [ ] Un domicilio o un pedido local no muestran el botón; tampoco una recogida ya marcada lista.
- [ ] Cobrar y "Confirmar recogida" (Mesero) finalizan el pedido como siempre; "Ver historial"
      muestra "Listo para recoger" con quién lo marcó.
- [ ] Con el Mesero: "Nuevo pedido" → Recogida pide el correo opcional; con un correo mal escrito
      no deja continuar.

### 9c. Comandas de cocina (Fase 6, tarea 2 — RN-PED-06/09/17 a 19, RF-M02-T-004 a 007, HU-M02-016/017)
Antes de probar: `php artisan migrate`. Permitir las ventanas emergentes del sitio en el navegador.
- [ ] Con el Mesero, un pedido local con 2 Clásicas "sin cebolla" y un combo: "Confirmar pedido"
      abre en otra pestaña la comanda de 80 mm (pedido, mesa, hora, líneas, lo que trae el combo,
      la nota). No lleva precios. Se imprime con Ctrl+P.
- [ ] El pedido muestra "Reimprimir comanda 12-1"; reimprimirla dice "*** REIMPRESIÓN · COPIA 2 ***".
      Auditoría muestra las dos impresiones con quién las hizo.
- [ ] Debajo dice "¿No salió de la impresora? Avisar": al avisar, la franja amarilla de arriba
      muestra "1 comanda por imprimir · 1 no salió" y al imprimirla de nuevo sale como original.
- [ ] Apagar "Abrir la comanda al confirmar" (en "Impresión de comandas en este equipo"):
      confirmar ya no abre nada y la franja dice "1 comanda por imprimir"; "Imprimir" desde la
      franja la abre y la franja desaparece.
- [ ] Con el pedido confirmado y su comanda impresa, editar: agregar 2 Papas y cambiar la
      Clásica a 3. Sale una sola hoja "Cambios al pedido" (hoja 2) con "[AGREGAR] 2 × Papas" y
      "[CAMBIAR] 3 × Clásica (antes 2)", no las líneas que no cambiaron.
- [ ] Editar un pedido confirmado cuya comanda todavía no se imprimió: no sale hoja de cambios;
      la comanda inicial sale ya con el cambio.
- [ ] Cancelar (con contraseña de Administrador) un pedido cuya comanda ya salió: sale "PEDIDO
      CANCELADO" con el motivo. Cancelar uno cuya comanda no se imprimió: no sale nada y la franja
      ya no la muestra.
- [ ] Un domicilio web confirmado: la comanda dice "DOMICILIO" y el nombre del cliente.
- [ ] "Ver historial" del pedido muestra "Comanda 12-1 enviada a la cocina" e "impresa" con quién.
- [ ] Equipo de la cocina: prender "Abrir solas las comandas nuevas" y, desde otro equipo,
      confirmar un pedido. En menos de 15 s se abre sola la comanda. Con Chrome abierto con
      `--kiosk-printing` sale sin el cuadro de impresión.
- [ ] Un Cliente no puede abrir `/api/comandas/pendientes` ni el ticket de una comanda (403).

## 9b. Invitado (sin cuenta)
- [ ] Pedir una recogida sin iniciar sesión: el Checkout muestra el enlace de seguimiento.
- [ ] El enlace muestra el pedido "Recibido"; al confirmarlo el Mesero, en máximo 30 segundos pasa a
      "Confirmado" sin recargar (RF-M07-027).
- [ ] Desde el enlace: pagar en línea queda "en revisión"; cancelar solo aparece mientras esté pendiente.
- [ ] Un enlace inventado muestra "No encontramos ese pedido".
- [ ] Con una promoción vigente, el carrito y el Checkout muestran la rebaja y el mismo total que
      tendrá el pedido; el seguimiento muestra la promoción aplicada.
- [ ] Reservar mesa sin cuenta y pagar el depósito en línea: al confirmarlo el personal, el correo
      trae el enlace de la reserva y desde ahí se puede cancelar antes de la hora (punto 5c).
- [ ] En el Checkout de una recogida, el correo es opcional ("Te avisamos por correo cuando tu
      pedido esté listo para recoger"); un correo mal escrito muestra "Escribe un correo válido o
      déjalo vacío". Cuando el local lo marca listo, llega el correo y el seguimiento cambia solo a
      "¡Tu pedido está listo! Pasa a recogerlo en el local." (punto 9a).

## 10. Transversal
- [ ] Cerrar sesión y volver a entrar; el token anterior ya no sirve.
- [ ] Desactivar un Mesero con sesión abierta: en su siguiente acción la app lo saca (RF-M01-023).
- [ ] Usuarios: cada desactivación queda registrada y se ve en Auditoría (sección 2b).
- [ ] Cada pantalla en teléfono (ancho ~390 px): sin scroll horizontal ni botones cortados.
- [ ] Errores del servidor (422/500) muestran un mensaje legible, no una pantalla en blanco.
