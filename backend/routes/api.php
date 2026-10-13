<?php

use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CajaController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\CompraGastoController;
use App\Http\Controllers\Api\ComprobanteController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MesaController;
use App\Http\Controllers\Api\PagoController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\PromocionController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\ReservaController;
use App\Http\Controllers\Api\ReservaPublicaController;
use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| routes/api.php — agrupadas por rol (sección 6 del documento de
| arquitectura). El middleware role:xxx filtra a nivel de ruta; la
| autorización fina sobre un registro puntual vive en las Policies, y se
| aplica dentro de cada Controller ($this->authorize(...)).
|--------------------------------------------------------------------------
*/

// ---- Público (sin autenticar) ----
// Fase 0, tarea 6 (09/10/2026): límite de intentos por IP para que no se pueda probar
// contraseñas ni tokens de recuperación a fuerza bruta (RNF-M01, M00 seguridad).
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    // RF-M01-012/013: recuperación de contraseña.
    Route::post('/auth/recuperar', [AuthController::class, 'solicitarRecuperacion']);
    Route::post('/auth/restablecer', [AuthController::class, 'restablecerContrasena']);
});

// Catálogo público (Cliente web, incluido invitado)
Route::get('/categorias', [CatalogoController::class, 'categorias']);
Route::get('/productos', [CatalogoController::class, 'productos']);
Route::get('/productos/{producto}/promociones', [CatalogoController::class, 'promocionesDeProducto']);
Route::get('/combos', [CatalogoController::class, 'combos']);
Route::get('/mesas', [MesaController::class, 'index']);
// RF-M02-L-025: resolver la mesa de un QR escaneado (pedido por QR, sin cuenta).
Route::get('/mesas/qr/{qr_code}', [MesaController::class, 'porQrCode']);

// Valores vigentes de solo lectura (domicilio, depósito de reserva) que el frontend
// necesita mostrar antes de crear el Pedido/la Reserva — ver CatalogoController::configuracion().
Route::get('/configuracion', [CatalogoController::class, 'configuracion']);

// Pedido de invitado (domicilio/recogida sin cuenta) y pago en línea de invitado.
// El Controller distingue "cliente_id" (autenticado) de "cliente_invitado_*" — StorePedidoRequest
// exige uno de los dos.
Route::post('/pedidos', [PedidoController::class, 'store'])->middleware('throttle:30,1');
// Fase 2, tarea 1 (RF-M04-035): total con promociones antes de confirmar; no guarda nada.
Route::post('/pedidos/cotizacion', [PedidoController::class, 'cotizar'])->middleware('throttle:60,1');

// RF-M06-005, HU-M07-002/011 (Fase 0, tarea 4): el invitado consulta y paga su pedido con el
// token secreto que recibió al crearlo. Con límite de intentos para que no se puedan adivinar.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/pedidos/seguimiento/{token}', [PedidoController::class, 'seguimiento']);
    // Fase 5, tarea 1 (RF-M06-010): el invitado descarga el comprobante con su enlace.
    Route::get('/pedidos/seguimiento/{token}/comprobante', [ComprobanteController::class, 'porEnlace']);
    Route::post('/pedidos/seguimiento/{token}/pagar-en-linea', [PagoController::class, 'pagarEnLineaConToken']);
    // Fase 1, tarea 6 (RF-M07-016, RF-M07-R-009): el invitado cancela su pedido pendiente.
    Route::post('/pedidos/seguimiento/{token}/cancelar', [PedidoController::class, 'cancelarConToken']);
});

// Reserva pública (Cliente web) — StoreReservaRequest exige los datos de contacto (RN-M05-12).
// RN-M05-14: depósito obligatorio; el Cliente lo paga en línea por Nequi/Bancolombia.
Route::post('/reservas', [ReservaController::class, 'store'])->middleware('throttle:30,1');
// Fase 1, tarea 4 (RN-M05-07): mesas libres de reservas a una fecha y hora.
Route::get('/mesas/disponibles', [ReservaController::class, 'mesasDisponibles'])->middleware('throttle:60,1');
Route::post('/reservas/{reserva}/deposito/en-linea', [ReservaController::class, 'registrarDepositoEnLinea'])->middleware('throttle:30,1');
// Fase 4, tarea 4 (RF-M05-018, RN-M05-09): ver y cancelar una reserva con el enlace del correo, sin cuenta.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/reservas/enlace/{token}', [ReservaPublicaController::class, 'ver']);
    Route::post('/reservas/enlace/{token}/cancelar', [ReservaPublicaController::class, 'cancelar']);
});

// 'activo' (Fase 0, tarea 6): una cuenta desactivada no usa ninguna ruta con sesión.
Route::middleware(['auth:sanctum', 'activo'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    // RF-M01-010/023: cerrar sesión en todos los dispositivos (revoca todos los tokens).
    Route::post('/auth/logout-todos', [AuthController::class, 'logoutTodos']);
    // Fase 3, tarea 4 (RF-M01-010/024, HU-M01-010): mis sesiones abiertas.
    Route::get('/auth/sesiones', [AuthController::class, 'sesiones']);
    Route::post('/auth/sesiones/cerrar-otras', [AuthController::class, 'cerrarOtrasSesiones']);
    Route::delete('/auth/sesiones/{sesion}', [AuthController::class, 'cerrarSesion'])->whereNumber('sesion');
    Route::get('/auth/me', [AuthController::class, 'me']);
    // RF-M01: el usuario autenticado edita su propio perfil / cambia su propia contraseña
    // (cualquier rol, no solo Cliente — Mesero y Administrador también tienen esta pantalla).
    Route::put('/perfil', [AuthController::class, 'actualizarPerfil']);
    Route::post('/perfil/password', [AuthController::class, 'cambiarPassword']);

    // ---- Pedidos: Administrador y Mesero gestionan; Cliente ve/paga/cancela los suyos ----
    Route::middleware('role:administrador,mesero,cliente')->group(function () {
        Route::get('/pedidos', [PedidoController::class, 'index']);
        Route::get('/pedidos/{pedido}', [PedidoController::class, 'show']);
        // Fase 5, tarea 1 (RF-M06-009/010, HU-M02-019): comprobante vigente, ticket o PDF.
        Route::get('/pedidos/{pedido}/comprobante', [ComprobanteController::class, 'show']);
        Route::post('/pedidos/{pedido}/pagar-en-linea', [PagoController::class, 'pagarEnLinea']); // solo Cliente dueño (PagoPolicy)
        // RN-M07-04: el Cliente puede cancelar su propio domicilio mientras esté PENDIENTE
        // (PedidoPolicy::cancelar() filtra esto; Administrador/Mesero cancelan cualquiera).
        Route::post('/pedidos/{pedido}/cancelar', [PedidoController::class, 'cancelar']);
    });

    Route::middleware('role:administrador,mesero')->group(function () {
        Route::post('/pedidos/{pedido}/confirmar', [PedidoController::class, 'confirmar']);
        Route::post('/pedidos/{pedido}/preparando', [PedidoController::class, 'marcarPreparando']);
        // Fase 4, tarea 5 (RF-M07-R-012): la recogida está lista y se avisa al cliente por correo.
        Route::post('/pedidos/{pedido}/listo', [PedidoController::class, 'marcarListo']);
        Route::post('/pedidos/{pedido}/avisos/{aviso}/reintentar', [PedidoController::class, 'reintentarAviso']);
        Route::post('/pedidos/{pedido}/detalles/{detalle}/preparado', [PedidoController::class, 'marcarLineaPreparada']);
        // Fase 1, tarea 1: editar un pedido PENDIENTE o las líneas no preparadas de uno
        // CONFIRMADO (RN-PED-07/08/10), con historial (RN-PED-12, HU-M02-020).
        Route::post('/pedidos/{pedido}/detalles', [PedidoController::class, 'agregarLinea']);
        Route::patch('/pedidos/{pedido}/detalles/{detalle}', [PedidoController::class, 'actualizarLinea']);
        Route::delete('/pedidos/{pedido}/detalles/{detalle}', [PedidoController::class, 'retirarLinea']);
        Route::get('/pedidos/{pedido}/historial', [PedidoController::class, 'historial']);
        // Fase 2, tarea 1 (RF-M04-036/037, HU-M04-015): descuento manual con motivo, y las
        // promociones de tipo "manual" que el Administrador dejó listas para aplicar a mano.
        Route::post('/pedidos/{pedido}/descuento', [PedidoController::class, 'aplicarDescuento']);
        Route::delete('/pedidos/{pedido}/descuento', [PedidoController::class, 'quitarDescuento']);
        Route::get('/promociones/manuales', [PromocionController::class, 'manuales']);
        // RN-PED-32: entregar, recoger y finalizar. PedidoPolicy deja estas tres solo al Mesero
        // (RF-M07-025, RF-M07-R-013, RF-M02-T-016); el Administrador recibe 403.
        Route::post('/pedidos/{pedido}/entregar', [PedidoController::class, 'confirmarEntrega']);
        Route::post('/pedidos/{pedido}/recoger', [PedidoController::class, 'confirmarRecogida']);
        Route::post('/pedidos/{pedido}/finalizar', [PedidoController::class, 'finalizar']);

        // RF-M06-003/004, RNF-M06-004: PedidoPolicy::cobrar() decide si este actor puede
        // cobrar ESTE pedido (siempre el Mesero; el grupo de rutas también deja pasar al
        // Administrador, pero la Policy lo rechaza — autorización fina en profundidad).
        Route::post('/pedidos/{pedido}/cobrar', [PagoController::class, 'cobrar']);

        // Confirmar/rechazar manualmente un pago en línea (mismo patrón que el depósito de
        // reserva de abajo) — hasta que exista el webhook real de la pasarela. Sin esto, un
        // Pedido pagado en línea quedaba en "pendiente" para siempre: PedidoService::confirmar()
        // exige que no tenga un pago en línea sin aprobar (RN-M06-05) y no había forma de
        // aprobarlo.
        Route::post('/pagos/{pago}/confirmar-en-linea', [PagoController::class, 'confirmarEnLinea']);
        Route::post('/pagos/{pago}/rechazar-en-linea', [PagoController::class, 'rechazarEnLinea']);

        // M05 (Admin/Mesero): listado de reservas con filtro opcional ?estado=.
        Route::get('/reservas', [ReservaController::class, 'index']);

        // RN-M05-16: depósito de reserva — en línea (confirmar/rechazar, hoy manual hasta
        // que exista el webhook real; ver README-BACKEND.md) o manual (cualquier método de M06).
        Route::post('/reservas/{reserva}/deposito/en-linea/confirmar', [ReservaController::class, 'confirmarDepositoEnLinea']);
        Route::post('/reservas/{reserva}/deposito/en-linea/rechazar', [ReservaController::class, 'rechazarDepositoEnLinea']);
        Route::post('/reservas/{reserva}/deposito/manual', [ReservaController::class, 'registrarDepositoManual']);

        Route::post('/reservas/{reserva}/no-show', [ReservaController::class, 'marcarNoShow']);
        Route::post('/reservas/{reserva}/completar', [ReservaController::class, 'completar']);
        // Fase 4, tarea 4 (RF-M05-027): volver a enviar un aviso que falló.
        Route::post('/reservas/{reserva}/avisos/{aviso}/reintentar', [ReservaController::class, 'reintentarAviso']);

        // RF-M05-009/010: abrir/liberar una mesa manualmente (sin pedido de por medio).
        Route::post('/mesas/{mesa}/abrir', [MesaController::class, 'abrir']);
        Route::post('/mesas/{mesa}/liberar', [MesaController::class, 'liberar']);
        // RF-M05-011 / HU-M05-007: liberación forzada (salta la validación de pedidos activos),
        // con motivo obligatorio y auditoría — Mesero o Administrador (M05, tabla de actores).
        Route::post('/mesas/{mesa}/liberar-forzada', [MesaController::class, 'liberarForzada']);
        // Fase 1, tarea 3: cuenta acumulada de la mesa (RF-M02-L-019/020) y cobro por mesa
        // (solo el Mesero cobra, RF-M06-003; el Administrador recibe 403).
        Route::get('/mesas/{mesa}/cuenta', [MesaController::class, 'cuenta']);
        Route::post('/mesas/{mesa}/cobrar', [MesaController::class, 'cobrar']);

        // RF-M04-016/017: alternar agotado/disponible no requiere el permiso de edición
        // completa de updateProducto() (exclusivo Administrador).
        Route::post('/productos/{producto}/disponibilidad', [CatalogoController::class, 'toggleDisponible']);
        // Fase 1, tarea 5: una variante agotada (RF-M04-016/017).
        Route::post('/variantes/{variante}/disponibilidad', [CatalogoController::class, 'toggleDisponibleVariante']);
    });

    Route::middleware('role:administrador,mesero,cliente')->group(function () {
        Route::post('/reservas/{reserva}/cancelar', [ReservaController::class, 'cancelar']);
    });

    // "Mis pedidos y reservas" (Cliente): historial de reservas propias — identificadas por
    // correo de contacto, ya que Reserva no tiene cliente_id (RN-M05-12). Ver
    // ReservaController::misReservas().
    Route::middleware('role:cliente')->group(function () {
        Route::get('/mis-reservas', [ReservaController::class, 'misReservas']);
    });

    // ---- Exclusivo Administrador ----
    Route::middleware('role:administrador')->group(function () {
        // Dashboard: KPIs resumen del día (ventas, pedidos activos, mesas ocupadas).
        Route::get('/dashboard/resumen', [DashboardController::class, 'resumen']);

        // Catálogo (M04)
        Route::post('/categorias', [CatalogoController::class, 'storeCategoria']);
        Route::put('/categorias/{categoria}', [CatalogoController::class, 'updateCategoria']);
        Route::post('/productos', [CatalogoController::class, 'storeProducto']);
        Route::put('/productos/{producto}', [CatalogoController::class, 'updateProducto']);

        // Catálogo (M04) parte 2: variantes, extras, combos y promociones.
        Route::post('/variantes', [CatalogoController::class, 'storeVariante']);
        Route::put('/variantes/{variante}', [CatalogoController::class, 'updateVariante']);

        Route::get('/extras', [CatalogoController::class, 'extras']);
        Route::post('/extras', [CatalogoController::class, 'storeExtra']);
        Route::put('/extras/{extra}', [CatalogoController::class, 'updateExtra']);

        // combos-admin trae TODOS los combos (incluidos inactivos/vencidos) para gestión —
        // distinto de GET /combos (público), que solo lista los disponibles hoy.
        Route::get('/combos-admin', [CatalogoController::class, 'combosAdmin']);
        Route::post('/combos', [CatalogoController::class, 'storeCombo']);
        Route::put('/combos/{combo}', [CatalogoController::class, 'updateCombo']);

        // RN-M04-19 a 23: fechas especiales (Navidad, Amor y Amistad, etc.) son solo una
        // promoción más con vigencia_inicio/vigencia_fin — no hay lógica especial por fecha.
        Route::get('/promociones', [PromocionController::class, 'index']);
        Route::post('/promociones', [PromocionController::class, 'store']);
        Route::put('/promociones/{promocion}', [PromocionController::class, 'update']);

        // Mesas (M05)
        Route::post('/mesas', [MesaController::class, 'store']);
        // Fase 1, tarea 6 (RF-M05-002): corregir el nombre de una mesa.
        Route::put('/mesas/{mesa}', [MesaController::class, 'update']);
        Route::post('/mesas/{mesa}/desactivar', [MesaController::class, 'desactivar']);
        Route::post('/mesas/{mesa}/activar', [MesaController::class, 'activar']);
        // RF-M05-006: regenerar el QR de una mesa (invalida el anterior).
        Route::post('/mesas/{mesa}/regenerar-qr', [MesaController::class, 'regenerarQr']);

        // RF-M05-035, HU-M05-015: depósitos de reserva retenidos por no-show (RN-M05-18).
        Route::get('/reservas/depositos-retenidos', [ReservaController::class, 'depositosRetenidos']);

        // Usuarios (M01): listar, crear Mesero/Administrador, activar/desactivar, revocar sesiones.
        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        // Fase 3, tarea 2 (RF-M01-003/005): editar datos y rol de una cuenta.
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update']);
        Route::post('/usuarios/{usuario}/activar', [UsuarioController::class, 'activar']);
        Route::post('/usuarios/{usuario}/desactivar', [UsuarioController::class, 'desactivar']);
        Route::post('/usuarios/{usuario}/revocar-sesiones', [UsuarioController::class, 'revocarSesiones']);
        // Fase 3, tarea 4: ver y cerrar una sesión en particular de una cuenta.
        Route::get('/usuarios/{usuario}/sesiones', [UsuarioController::class, 'sesiones']);
        Route::delete('/usuarios/{usuario}/sesiones/{sesion}', [UsuarioController::class, 'revocarSesion'])->whereNumber('sesion');

        // Fase 3, tarea 1 (RF-M01-025, HU-M01-020, RNF-TRANS-023): consulta de la auditoría y
        // de los eventos de seguridad. Solo lectura.
        Route::get('/auditorias', [AuditoriaController::class, 'index']);
        Route::get('/auditorias/opciones', [AuditoriaController::class, 'opciones']);

        // RF-M07-030, RN-M05-08 a 13: valor de domicilio y depósito de reserva, editables por
        // el Administrador "en cualquier momento" — tabla `configuraciones` (clave/valor).
        Route::get('/configuraciones', [ConfiguracionController::class, 'index']);
        Route::put('/configuraciones', [ConfiguracionController::class, 'update']);
        // Fase 4, tarea 1: estado del programador de tareas y de la cola de trabajos.
        Route::get('/sistema/estado', [ConfiguracionController::class, 'estadoSistema']);

        // Caja (M06)
        Route::get('/caja/turnos', [CajaController::class, 'index']);
        Route::get('/caja/turnos/{turno}', [CajaController::class, 'show']);
        Route::get('/caja/turno-actual', [CajaController::class, 'turnoActual']);
        Route::post('/caja/abrir', [CajaController::class, 'abrir']);
        Route::post('/caja/turnos/{turno}/cerrar', [CajaController::class, 'cerrar']);

        // Pagos: anular (control financiero)
        Route::post('/pagos/{pago}/anular', [PagoController::class, 'anular']);

        // Compras/Gastos/Cierre Diario (M03)
        Route::get('/compras-gastos', [CompraGastoController::class, 'index']);
        // Fase 2, tarea 4: categorías por tipo y soporte (RF-M03-07, RNF-M03-11).
        Route::get('/compras-gastos/categorias', [CompraGastoController::class, 'categorias']);
        Route::post('/compras-gastos/{compraGasto}/soporte', [CompraGastoController::class, 'adjuntarSoporte']);
        Route::get('/compras-gastos/{compraGasto}/soporte', [CompraGastoController::class, 'verSoporte']);
        Route::post('/compras-gastos', [CompraGastoController::class, 'store']);
        // RF-M03-14: editar un registro activo.
        Route::put('/compras-gastos/{compraGasto}', [CompraGastoController::class, 'update']);
        Route::post('/compras-gastos/{compraGasto}/anular', [CompraGastoController::class, 'anular']);
        Route::post('/cierres-diarios', [CompraGastoController::class, 'generarCierreDiario']);
        // Fase 2, tarea 5 (RF-M03-26/27, HU-M03-09): consulta previa, historial y detalle con ajustes.
        Route::get('/cierres-diarios/previa', [CompraGastoController::class, 'previaCierre']);
        Route::get('/cierres-diarios', [CompraGastoController::class, 'cierres']);
        Route::get('/cierres-diarios/{cierre}', [CompraGastoController::class, 'verCierre']);

        // Reportes (M08) — los 6 reportes reales del documento (RF-M08-002 a 009), cada
        // uno con periodo (RF-M08-001), comparación (RF-M08-010) y exportación
        // (RF-M08-011/012/014) vía /reportes/exportar.
        Route::get('/reportes/ventas-totales', [ReporteController::class, 'ventasTotales']);
        Route::get('/reportes/ventas-por-producto', [ReporteController::class, 'ventasPorProducto']);
        Route::get('/reportes/ventas-por-tipo-y-metodo', [ReporteController::class, 'ventasPorTipoYMetodo']);
        Route::get('/reportes/compras-y-gastos', [ReporteController::class, 'comprasYGastos']);
        Route::get('/reportes/resultado-operativo', [ReporteController::class, 'resultadoOperativo']);
        Route::get('/reportes/uso-de-mesas', [ReporteController::class, 'usoDeMesas']);
        Route::post('/reportes/exportar', [ReporteController::class, 'exportar']);
    });
});
