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

## 8. Reportes (M08)
- [ ] Los 6 reportes cargan con la semana actual por defecto (RN-M08-03).
- [ ] Cambiar a mes y a rango personalizado.
- [ ] Comparación contra el periodo anterior equivalente (RF-M08-010).
- [ ] Periodo en curso aparece marcado como parcial (RN-M08-05).
- [ ] Resultado operativo = ventas − compras − gastos (RN-M08-06), con y sin cierre diario del día.
- [ ] Exportar a Excel (CSV) y PDF; la exportación queda en auditoría (RF-M08-011/012/014).
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

## 9b. Invitado (sin cuenta)
- [ ] Pedir una recogida sin iniciar sesión: el Checkout muestra el enlace de seguimiento.
- [ ] El enlace muestra el pedido "Recibido"; al confirmarlo el Mesero, en máximo 30 segundos pasa a
      "Confirmado" sin recargar (RF-M07-027).
- [ ] Desde el enlace: pagar en línea queda "en revisión"; cancelar solo aparece mientras esté pendiente.
- [ ] Un enlace inventado muestra "No encontramos ese pedido".
- [ ] Con una promoción vigente, el carrito y el Checkout muestran la rebaja y el mismo total que
      tendrá el pedido; el seguimiento muestra la promoción aplicada.

## 10. Transversal
- [ ] Cerrar sesión y volver a entrar; el token anterior ya no sirve.
- [ ] Desactivar un Mesero con sesión abierta: en su siguiente acción la app lo saca (RF-M01-023).
- [ ] Usuarios: cada desactivación queda registrada y se ve en Auditoría (sección 2b).
- [ ] Cada pantalla en teléfono (ancho ~390 px): sin scroll horizontal ni botones cortados.
- [ ] Errores del servidor (422/500) muestran un mensaje legible, no una pantalla en blanco.
