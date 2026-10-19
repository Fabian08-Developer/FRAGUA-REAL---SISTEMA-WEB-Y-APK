# API — Fragua Real Burger

Referencia de los 157 endpoints de `backend/routes/api.php`, agrupados por módulo. Todas las rutas van bajo `/api` y responden JSON, salvo los PDF, tickets, fotos y archivos exportados.

Generada desde el código el 13/10/2026 (`php artisan route:list --path=api`). Si cambia una ruta, actualiza esta tabla.

## Cómo leer la tabla

- **Quién**: el rol que deja pasar la ruta (middleware `role`). «Pública» no pide sesión; «Con sesión» acepta cualquier rol.
- Algunas reglas finas las decide la Policy, no la ruta. Por ejemplo, cobrar, finalizar y confirmar entrega o recogida pasan el middleware con Administrador y Mesero, pero la Policy solo deja al Mesero (ADR-004). Esas filas lo dicen.
- **Límite**: peticiones por minuto desde una misma dirección IP (`throttle`), solo en las rutas públicas que se pueden abusar. Al pasarlo, la respuesta es 429 hasta que termina ese minuto.
- Autenticación: `Authorization: Bearer <token>` con el token que devuelve `POST /auth/login`. Una cuenta desactivada recibe 403 («Tu cuenta está desactivada») en cualquier ruta con sesión.
- Errores: 401 sin sesión válida, 403 sin permiso, 404 si no existe (o no es tuyo), 422 con los mensajes de validación en español, 429 por exceso de intentos.

## Índice

- [Cuenta y sesión (M01)](#cuenta-y-sesión-m01) (12)
- [Usuarios y auditoría (M01)](#usuarios-y-auditoría-m01) (10)
- [Catálogo (M04)](#catálogo-m04) (25)
- [Pedidos (M02 y M07)](#pedidos-m02-y-m07) (21)
- [Comandas de cocina (M02)](#comandas-de-cocina-m02) (4)
- [Mesas (M05)](#mesas-m05) (12)
- [Reservas (M05)](#reservas-m05) (16)
- [Caja y pagos (M06)](#caja-y-pagos-m06) (22)
- [Compras, gastos y cierre diario (M03)](#compras-gastos-y-cierre-diario-m03) (12)
- [Reportes (M08)](#reportes-m08) (9)
- [Configuración, sistema y tablero](#configuración-sistema-y-tablero) (6)

## Cuenta y sesión (M01)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `POST` | `/auth/login` | Pública | Inicia sesión con el correo o el nombre de usuario y devuelve el token. | 10/min |
| `POST` | `/auth/logout` | Con sesión (cualquier rol) | Cierra la sesión actual. |  |
| `POST` | `/auth/logout-todos` | Con sesión (cualquier rol) | Cierra todas las sesiones de la cuenta. |  |
| `GET` | `/auth/me` | Con sesión (cualquier rol) | Datos de la cuenta con sesión (rol, permisos). |  |
| `POST` | `/auth/recuperar` | Pública | Envía el correo para recuperar la contraseña. No revela si el correo existe. | 10/min |
| `POST` | `/auth/register` | Pública | Crea la cuenta de un Cliente (autorregistro). Solo crea Clientes. | 10/min |
| `POST` | `/auth/restablecer` | Pública | Cambia la contraseña con el enlace del correo y cierra todas las sesiones. | 10/min |
| `GET` | `/auth/sesiones` | Con sesión (cualquier rol) | Sesiones abiertas de la cuenta: dispositivo, IP, último uso y vencimiento. |  |
| `POST` | `/auth/sesiones/cerrar-otras` | Con sesión (cualquier rol) | Cierra todas las sesiones menos la actual. |  |
| `DELETE` | `/auth/sesiones/{sesion}` | Con sesión (cualquier rol) | Cierra una sesión propia. |  |
| `PUT` | `/perfil` | Con sesión (cualquier rol) | Edita nombre, teléfono y nombre de usuario propios. |  |
| `POST` | `/perfil/password` | Con sesión (cualquier rol) | Cambia la contraseña propia (pide la actual). |  |

## Usuarios y auditoría (M01)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/auditorias` | Administrador | Historial de auditoría con filtros (fechas, usuario, grupo, acción, registro, IP, texto). Solo lectura. |  |
| `GET` | `/auditorias/opciones` | Administrador | Listas para los filtros de Auditoría y resumen de seguridad (intentos fallidos). |  |
| `GET` | `/usuarios` | Administrador | Usuarios con filtros por rol y texto (nombre, correo o usuario). |  |
| `POST` | `/usuarios` | Administrador | Crea una cuenta de Mesero o Administrador. |  |
| `PUT` | `/usuarios/{usuario}` | Administrador | Edita datos, rol y el permiso de compras y gastos (cambiar el rol cierra sus sesiones). |  |
| `POST` | `/usuarios/{usuario}/activar` | Administrador | Activa una cuenta. |  |
| `POST` | `/usuarios/{usuario}/desactivar` | Administrador | Desactiva una cuenta y cierra sus sesiones. |  |
| `POST` | `/usuarios/{usuario}/revocar-sesiones` | Administrador | Cierra todas las sesiones de una cuenta. |  |
| `GET` | `/usuarios/{usuario}/sesiones` | Administrador | Sesiones abiertas de una cuenta. |  |
| `DELETE` | `/usuarios/{usuario}/sesiones/{sesion}` | Administrador | Cierra una sesión de una cuenta. |  |

## Catálogo (M04)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/categorias` | Pública | Categorías activas del menú. |  |
| `POST` | `/categorias` | Administrador | Crea una categoría. |  |
| `PUT` | `/categorias/{categoria}` | Administrador | Edita o desactiva una categoría (desactivarla oculta sus productos y combos). |  |
| `GET` | `/combos` | Pública | Combos disponibles ahora (vigencia, franja y días). |  |
| `POST` | `/combos` | Administrador | Crea un combo (al menos dos productos). |  |
| `GET` | `/combos-admin` | Administrador | Todos los combos, también inactivos, con el ahorro frente a sus productos. |  |
| `PUT` | `/combos/{combo}` | Administrador | Edita o desactiva un combo. |  |
| `GET` | `/extras` | Administrador | Extras (generales y específicos). |  |
| `POST` | `/extras` | Administrador | Crea un extra. |  |
| `PUT` | `/extras/{extra}` | Administrador | Edita un extra o los productos que lo ofrecen. |  |
| `GET` | `/productos` | Pública | Productos con presentaciones, extras y foto. Sin sesión o como Cliente, solo lo que se puede pedir; con sesión del personal, todo el catálogo. Filtros `q` y `categoria_id`. |  |
| `POST` | `/productos` | Administrador | Crea un producto. |  |
| `GET` | `/productos/importacion/plantilla` | Administrador | Descarga la plantilla de Excel para importar productos (hoja «Productos» e «Instrucciones»). |  |
| `POST` | `/productos/importacion/vista-previa` | Administrador | Lee el archivo (`archivo`: .xlsx, .xls o .csv, hasta 2 MB y 500 filas; `actualizar`: 1 o 0) y devuelve qué haría cada fila (crear, actualizar, sin cambios o error). No guarda nada. |  |
| `POST` | `/productos/importacion` | Administrador | Importa el mismo archivo: crea y actualiza todo en una transacción, o nada si alguna fila tiene error (422). |  |
| `PUT` | `/productos/{producto}` | Administrador | Edita un producto (darlo de baja desactiva sus combos). |  |
| `POST` | `/productos/{producto}/disponibilidad` | Administrador, Mesero | Marca un producto agotado o disponible. |  |
| `GET` | `/productos/{producto}/foto` | Pública | Foto del producto (WebP, caché larga). Pública para el Menú. | 600/min |
| `POST` | `/productos/{producto}/foto` | Administrador | Sube o cambia la foto del producto (JPG, PNG o WebP; se reduce a 900 px). |  |
| `DELETE` | `/productos/{producto}/foto` | Administrador | Quita la foto del producto. |  |
| `GET` | `/productos/{producto}/promociones` | Pública | Promociones vigentes de un producto. |  |
| `GET` | `/promociones` | Administrador | Promociones. |  |
| `POST` | `/promociones` | Administrador | Crea una promoción (producto, categoría o total; automática o manual). |  |
| `GET` | `/promociones/manuales` | Administrador, Mesero | Promociones de tipo manual (para el descuento en Pedidos). |  |
| `PUT` | `/promociones/{promocion}` | Administrador | Edita o desactiva una promoción. |  |
| `POST` | `/productos/{producto}/oferta` | Administrador | «Poner en oferta»: `modo` (`precio` o `porcentaje`), `precio_oferta` o `porcentaje`, `variante_id` (opcional; obligatoria con precio y presentaciones), `hasta` (opcional). Crea una promoción automática de producto y reemplaza la oferta anterior. |  |
| `DELETE` | `/productos/{producto}/oferta` | Administrador | Quita la oferta del producto (queda inactiva, con su historial). |  |
| `POST` | `/variantes` | Administrador | Crea una presentación (tamaño) de un producto. |  |
| `PUT` | `/variantes/{variante}` | Administrador | Edita una presentación (incluida su `descripcion`, hasta 160 caracteres). |  |
| `GET` | `/variantes/{variante}/foto` | Pública | Foto propia de la presentación (WebP, caché larga). | 600/min |
| `POST` | `/variantes/{variante}/foto` | Administrador | Sube o cambia la foto de la presentación (mismas reglas que la del producto). |  |
| `DELETE` | `/variantes/{variante}/foto` | Administrador | Quita la foto de la presentación (el Menú vuelve a mostrar la del producto). |  |
| `POST` | `/variantes/{variante}/disponibilidad` | Administrador, Mesero | Marca una presentación agotada o disponible. |  |

## Pedidos (M02 y M07)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `POST` | `/pedidos` | Pública | Crea un pedido: Cliente o invitado (domicilio o recogida), QR de mesa, o el personal (local o por teléfono). Devuelve el enlace de seguimiento al invitado. | 30/min |
| `GET` | `/pedidos` | Administrador, Mesero, Cliente | Pedidos con filtros (tipo, estado, mesa, activos). El Cliente ve solo los suyos. |  |
| `POST` | `/pedidos/cotizacion` | Pública | Calcula el total con promociones antes de crear el pedido. | 60/min |
| `GET` | `/pedidos/seguimiento/{token}` | Pública | Pedido del invitado por su enlace (sin datos de contacto ni del personal). | 30/min |
| `POST` | `/pedidos/seguimiento/{token}/cancelar` | Pública | El invitado cancela su pedido pendiente con su enlace. | 30/min |
| `GET` | `/pedidos/{pedido}` | Administrador, Mesero, Cliente | Detalle de un pedido. El Cliente solo los suyos. |  |
| `POST` | `/pedidos/{pedido}/avisos/{aviso}/reintentar` | Administrador, Mesero | Reintenta un aviso del pedido que falló. |  |
| `POST` | `/pedidos/{pedido}/cancelar` | Administrador, Mesero, Cliente | Cancela con motivo. El Cliente, su domicilio o recogida mientras esté pendiente; el Mesero, uno pendiente (confirmado: con la contraseña de un Administrador). |  |
| `POST` | `/pedidos/{pedido}/confirmar` | Administrador, Mesero | Confirma el pedido (un pago en línea pendiente lo impide) y genera la comanda. |  |
| `POST` | `/pedidos/{pedido}/descuento` | Administrador, Mesero | Aplica un descuento manual con motivo (reemplaza las promociones automáticas). |  |
| `DELETE` | `/pedidos/{pedido}/descuento` | Administrador, Mesero | Quita el descuento manual. |  |
| `POST` | `/pedidos/{pedido}/detalles` | Administrador, Mesero | Agrega un producto o combo a un pedido pendiente o confirmado. |  |
| `PATCH` | `/pedidos/{pedido}/detalles/{detalle}` | Administrador, Mesero | Cambia cantidad u observaciones de una línea no preparada. |  |
| `DELETE` | `/pedidos/{pedido}/detalles/{detalle}` | Administrador, Mesero | Retira una línea no preparada. |  |
| `POST` | `/pedidos/{pedido}/detalles/{detalle}/preparado` | Administrador, Mesero | Marca una línea como preparada. |  |
| `POST` | `/pedidos/{pedido}/entregar` | Administrador, Mesero | Confirma la entrega de un domicilio. Solo el Mesero. |  |
| `POST` | `/pedidos/{pedido}/finalizar` | Administrador, Mesero | Finaliza un pedido en preparación, pagado y, si aplica, entregado o recogido. Solo el Mesero. |  |
| `GET` | `/pedidos/{pedido}/historial` | Administrador, Mesero | Historial del pedido: quién lo creó, confirmó, cambió, cobró y sus comandas. |  |
| `POST` | `/pedidos/{pedido}/listo` | Administrador, Mesero | Marca una recogida «lista para recoger» y avisa al cliente por correo. |  |
| `POST` | `/pedidos/{pedido}/preparando` | Administrador, Mesero | Pasa el pedido a PREPARANDO. |  |
| `POST` | `/pedidos/{pedido}/recoger` | Administrador, Mesero | Confirma que el cliente recogió el pedido. Solo el Mesero. |  |

## Comandas de cocina (M02)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/comandas/pendientes` | Administrador, Mesero | Comandas por imprimir (para la franja amarilla del Mesero). |  |
| `POST` | `/comandas/{comanda}/no-impresa` | Administrador, Mesero | «¿No salió?»: devuelve la comanda a la lista por imprimir. |  |
| `GET` | `/comandas/{comanda}/ticket` | Administrador, Mesero | Ticket de 80 mm de la comanda; desde la segunda impresión sale marcado REIMPRESIÓN. |  |
| `GET` | `/pedidos/{pedido}/comandas` | Administrador, Mesero | Comandas de un pedido (inicial, cambios y cancelación). |  |

## Mesas (M05)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/mesas` | Pública | Mesas con su estado. Sin sesión, solo las activas; el personal ve también las inactivas. |  |
| `POST` | `/mesas` | Administrador | Crea una mesa con su QR. |  |
| `GET` | `/mesas/qr/{qr_code}` | Pública | Mesa por el código de su QR (para el pedido desde la mesa). |  |
| `PUT` | `/mesas/{mesa}` | Administrador | Cambia el nombre de la mesa (el QR no cambia). |  |
| `POST` | `/mesas/{mesa}/abrir` | Administrador, Mesero | Marca la mesa ocupada sin pedido. |  |
| `POST` | `/mesas/{mesa}/activar` | Administrador | Reactiva una mesa. |  |
| `POST` | `/mesas/{mesa}/cobrar` | Administrador, Mesero | Cobra la cuenta de la mesa (uno o varios pagos). Solo el Mesero. |  |
| `GET` | `/mesas/{mesa}/cuenta` | Administrador, Mesero | Cuenta de la mesa: pedidos de la visita, abono del depósito y saldo. |  |
| `POST` | `/mesas/{mesa}/desactivar` | Administrador | Desactiva una mesa (con historial no se borra). |  |
| `POST` | `/mesas/{mesa}/liberar` | Administrador, Mesero | Libera la mesa si no tiene pedidos activos. |  |
| `POST` | `/mesas/{mesa}/liberar-forzada` | Administrador, Mesero | Libera la mesa aunque tenga pedidos activos, con motivo. Queda en Auditoría. |  |
| `POST` | `/mesas/{mesa}/regenerar-qr` | Administrador | Genera un QR nuevo (el anterior deja de servir). |  |

## Reservas (M05)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/mesas/disponibles` | Pública | Mesas libres a una fecha y hora (sin datos de nadie). | 60/min |
| `GET` | `/mis-reservas` | Cliente | Reservas del Cliente (por su correo). |  |
| `POST` | `/reservas` | Pública | Crea una reserva (web o del personal); devuelve el enlace secreto. | 30/min |
| `GET` | `/reservas` | Administrador, Mesero | Reservas con filtros, sus avisos y su depósito. |  |
| `GET` | `/reservas/depositos-retenidos` | Administrador | Depósitos retenidos por no-show. |  |
| `GET` | `/reservas/enlace/{token}` | Pública | Reserva por su enlace secreto (sin cuenta). | 30/min |
| `POST` | `/reservas/enlace/{token}/cancelar` | Pública | Cancela la reserva desde su enlace antes de la hora. | 30/min |
| `POST` | `/reservas/enlace/{token}/deposito` | Pública | Paga el depósito en línea desde el enlace de la reserva. | 30/min |
| `POST` | `/reservas/{reserva}/avisos/{aviso}/reintentar` | Administrador, Mesero | Reintenta un aviso de la reserva que falló. |  |
| `POST` | `/reservas/{reserva}/cancelar` | Administrador, Mesero, Cliente | Cancela una reserva; antes de la hora devuelve el depósito. El Cliente solo las suyas, y no si pagó el depósito en efectivo. |  |
| `POST` | `/reservas/{reserva}/completar` | Administrador, Mesero | Confirma la llegada: abre la mesa y abona el depósito. |  |
| `POST` | `/reservas/{reserva}/deposito/en-linea` | Pública | Inicia el pago en línea del depósito (pide el enlace secreto de la reserva). | 30/min |
| `POST` | `/reservas/{reserva}/deposito/en-linea/confirmar` | Administrador, Mesero | Aprueba a mano el depósito en línea (respaldo). |  |
| `POST` | `/reservas/{reserva}/deposito/en-linea/rechazar` | Administrador, Mesero | Rechaza a mano el depósito en línea. |  |
| `POST` | `/reservas/{reserva}/deposito/manual` | Administrador, Mesero | Registra el depósito en efectivo, tarjeta o transferencia (con la caja abierta). |  |
| `POST` | `/reservas/{reserva}/no-show` | Administrador, Mesero | Marca no-show cuando ya pasó la tolerancia; el depósito se retiene. |  |

## Caja y pagos (M06)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `POST` | `/caja/abrir` | Administrador | Abre la caja del día con la base inicial. Un solo turno por día. |  |
| `GET` | `/caja/estado` | Administrador, Mesero | Estado de la caja de hoy (sin abrir, abierta o cerrada), quién la abrió y el efectivo esperado. Solo lectura. |  |
| `GET` | `/caja/turno-actual` | Administrador | Turno abierto hoy con su `resumen` (ingresos por método, desglose del efectivo esperado, depósitos de reserva del día y anulados), o nulo. |  |
| `GET` | `/caja/turnos` | Administrador | Historial de turnos de caja. |  |
| `GET` | `/caja/turnos/{turno}` | Administrador | Detalle de un turno: pagos, pagos y depósitos en línea del día (`depositos_en_linea`), gastos, ajustes y `resumen`. |  |
| `POST` | `/caja/turnos/{turno}/cerrar` | Administrador | Cierra el turno con el efectivo contado y guarda la diferencia. |  |
| `POST` | `/pagos/{pago}/anular` | Administrador | Anula o reembolsa un pago; con la caja del día cerrada pide contraseña y queda como ajuste. |  |
| `POST` | `/pagos/{pago}/confirmar-en-linea` | Administrador, Mesero | Aprueba a mano un pago en línea (respaldo de la pasarela). |  |
| `POST` | `/pagos/{pago}/rechazar-en-linea` | Administrador, Mesero | Rechaza a mano un pago en línea. |  |
| `GET` | `/pasarela/estado` | Administrador | Si la pasarela está activa y en qué ambiente; URL de eventos. |  |
| `GET` | `/pasarela/transacciones` | Administrador | Pagos en línea por revisar. |  |
| `GET` | `/pasarela/transacciones/{referencia}` | Pública | Estado del pago al volver de Wompi (página «Tu pago»). | 60/min |
| `POST` | `/pasarela/transacciones/{transaccion}/reembolsar` | Administrador | Pide el reembolso a Wompi. |  |
| `POST` | `/pasarela/transacciones/{transaccion}/revisada` | Administrador | Marca un pago por revisar como resuelto, con nota. |  |
| `POST` | `/pasarela/wompi/eventos` | Pública | Eventos firmados de Wompi (webhook). Verifica la firma y aplica cada evento una sola vez. | 240/min |
| `GET` | `/pedidos/seguimiento/{token}/comprobante` | Pública | Comprobante del pedido para el invitado, con su enlace. | 30/min |
| `POST` | `/pedidos/seguimiento/{token}/pagar-en-linea` | Pública | Inicia el pago en línea del invitado con su enlace. | 30/min |
| `POST` | `/pedidos/{pedido}/cobrar` | Administrador, Mesero | Registra un cobro presencial (efectivo, tarjeta o transferencia). Solo el Mesero, con la caja abierta. |  |
| `GET` | `/pedidos/{pedido}/comprobante` | Administrador, Mesero, Cliente | Comprobante vigente del pedido: ticket de 80 mm o PDF. El Cliente solo el de sus pedidos; cada impresión del personal se cuenta. |  |
| `GET` | `/pedidos/{pedido}/comprobantes` | Administrador, Mesero, Cliente | Todos los comprobantes del pedido, también los anulados. Solo el Administrador (Policy). |  |
| `GET` | `/pedidos/{pedido}/comprobantes/{comprobante}` | Administrador, Mesero, Cliente | Abre un comprobante puntual (el anulado sale con su sello). Solo el Administrador (Policy). |  |
| `POST` | `/pedidos/{pedido}/pagar-en-linea` | Administrador, Mesero, Cliente | Inicia el pago en línea del Cliente dueño del pedido (Wompi o revisión manual). |  |

## Compras, gastos y cierre diario (M03)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `POST` | `/cierres-diarios` | Administrador | Cierra el día (exige la caja de ese día cerrada) y guarda el desglose. |  |
| `GET` | `/cierres-diarios` | Administrador | Historial de cierres diarios. |  |
| `GET` | `/cierres-diarios/previa` | Administrador | Vista previa del cierre de un día con su desglose. |  |
| `GET` | `/cierres-diarios/{cierre}` | Administrador | Detalle de un cierre con sus correcciones posteriores. |  |
| `GET` | `/cierres-diarios/{cierre}/pdf` | Administrador | Cierre diario en PDF (o vista previa de un día sin cerrar). |  |
| `GET` | `/compras-gastos` | Administrador, Mesero | Compras y gastos con filtros y totales. El Mesero con permiso ve solo los suyos. |  |
| `POST` | `/compras-gastos` | Administrador, Mesero | Registra una compra o un gasto. El Mesero con permiso no registra en días cerrados. |  |
| `GET` | `/compras-gastos/categorias` | Administrador, Mesero | Categorías de compras y de gastos. |  |
| `PUT` | `/compras-gastos/{compraGasto}` | Administrador | Corrige un registro (en un día cerrado pide contraseña y motivo). |  |
| `POST` | `/compras-gastos/{compraGasto}/anular` | Administrador | Anula un registro con motivo (en un día cerrado pide contraseña). |  |
| `POST` | `/compras-gastos/{compraGasto}/soporte` | Administrador, Mesero | Adjunta la foto o el PDF del soporte (almacenamiento privado). |  |
| `GET` | `/compras-gastos/{compraGasto}/soporte` | Administrador, Mesero | Descarga el soporte. El Mesero con permiso solo los suyos. |  |

## Reportes (M08)

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/reportes/compras-y-gastos` | Administrador | Reporte de compras y gastos por categoría. |  |
| `GET` | `/reportes/exportaciones` | Administrador | Exportaciones guardadas. |  |
| `GET` | `/reportes/exportaciones/{exportacion}/descargar` | Administrador | Descarga una exportación guardada tal cual (sin regenerarla). |  |
| `POST` | `/reportes/exportar` | Administrador | Exporta el reporte en pantalla a PDF o Excel (.xlsx) y lo guarda. |  |
| `GET` | `/reportes/resultado-operativo` | Administrador | Ventas − compras − gastos del periodo (usa los cierres diarios). |  |
| `GET` | `/reportes/uso-de-mesas` | Administrador | Reservas por estado y ocupación por mesa (visitas, ventas, minutos). |  |
| `GET` | `/reportes/ventas-por-producto` | Administrador | Ventas por producto y categoría, más y menos vendidos. |  |
| `GET` | `/reportes/ventas-por-tipo-y-metodo` | Administrador | Ventas por tipo de venta y por método de pago. |  |
| `GET` | `/reportes/ventas-totales` | Administrador | Monto y cantidad de pedidos finalizados del periodo. |  |

## Configuración, sistema y tablero

| Método | Ruta | Quién | Para qué | Límite |
|---|---|---|---|---|
| `GET` | `/configuracion` | Pública | Valores públicos: valor del domicilio, depósito de reserva y tiempos de reserva. |  |
| `GET` | `/configuraciones` | Administrador | Valores editables del sistema. |  |
| `PUT` | `/configuraciones` | Administrador | Edita domicilio, depósito y tiempos de reserva. Queda en Auditoría. |  |
| `GET` | `/dashboard/resumen` | Administrador | Resumen del día para el Administrador. |  |
| `GET` | `/sistema/estado` | Administrador | Estado del programador de tareas, la cola y el correo. |  |
| `GET` | `/sistema/revision-produccion` | Administrador | Revisión antes de producción (la misma de fragua:revisar-produccion). |  |
