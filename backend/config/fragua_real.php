<?php

/**
 * ACTUALIZADO (28/09/2026): estos dos valores ya viven de verdad en la tabla
 * `configuraciones`, editable por el Administrador vía ConfiguracionController
 * (GET/PUT /configuraciones) — ver app/Models/Configuracion.php. Este archivo ahora es
 * solo el valor de RESPALDO (fallback) que usan Configuracion::obtenerNumerico() en
 * CatalogoController/PedidoController/ReservaController cuando todavía no existe la fila
 * en la tabla (por ejemplo, justo después de `migrate` sin haber corrido el seeder).
 *
 * Importante (corrección de seguridad frente al documento original): el Controller SIEMPRE
 * lee estos valores del servidor (tabla `configuraciones`, con este archivo como respaldo),
 * nunca del body que envía el Cliente — de lo contrario cualquier request podría fijar su
 * propio valor_domicilio o deposito_valor.
 */
return [
    // RN business: valor de domicilio fijo $2.000 COP, editable por el Administrador.
    'valor_domicilio_default' => (float) env('FRAGUA_REAL_VALOR_DOMICILIO', 2000),

    // Depósito de reserva de mesa (RF-M05-028, RN-M05-14): valor inicial oficial $20.000 COP
    // (confirmado por el usuario el 06/10/2026), editable por el Administrador.
    'deposito_reserva_default' => (float) env('FRAGUA_REAL_DEPOSITO_RESERVA', 20000),

    // Fase 5, tarea 1 (RF-M06-009/010): datos del negocio que encabezan el comprobante de pago.
    // Nombre, dirección y WhatsApp son los que ya muestra el sitio; el NIT se pone en el .env del
    // servidor (FRAGUA_REAL_NIT) y, mientras esté vacío, el comprobante no lo imprime.
    'negocio' => [
        'nombre' => env('FRAGUA_REAL_NOMBRE', 'Fragua Real Burger'),
        'nit' => env('FRAGUA_REAL_NIT', ''),
        'direccion' => env('FRAGUA_REAL_DIRECCION', 'Esquina frente al malecón turístico, etapa 1'),
        'telefono' => env('FRAGUA_REAL_TELEFONO', '322 725 9965'),
    ],

    // RF-M01-012/013: base del enlace que RecuperarPasswordNotification arma hacia
    // /restablecer-password?email=&token= (ver RestablecerPasswordView.vue, que ya sabe
    // precargar esos dos query params). Default = puerto del servidor de Vite en este
    // proyecto; solo hace falta FRONTEND_URL en .env si el frontend corre en otro puerto/host.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    // Fase 1, tarea 4: cuánto tiempo ocupa una reserva su mesa, para no aceptar dos reservas que
    // se crucen. M05 no fija este valor (el plan lo deja para "ventana de reserva configurable",
    // Fase 4); mientras tanto es un supuesto de trabajo de 2 horas.
    'duracion_reserva_minutos' => (int) env('FRAGUA_REAL_DURACION_RESERVA_MINUTOS', 120),

    // Fase 2, tarea 4 — M03, sección 9 ("Tipos y categorías"): categorías V1 de compras y gastos,
    // simples y con "Otros". Viven aquí para poder ampliarlas sin rediseñar nada (RNF-M03-12).
    'categorias_compras_gastos' => [
        'compra' => ['Alimentos', 'Bebidas', 'Empaques', 'Insumos', 'Otros'],
        'gasto' => ['Transporte', 'Limpieza', 'Servicios', 'Mantenimiento', 'Papelería', 'Otros'],
    ],

    // RF-M03-07, RNF-M03-06: soporte de una compra o gasto — tamaño máximo en KB.
    'soporte_max_kb' => (int) env('FRAGUA_REAL_SOPORTE_MAX_KB', 5120),

    // Fase 4, tarea 2 (RF-M05-017/026, RN-M05-07/08/13, decisión D6 del 12/10/2026): valores
    // iniciales de las reservas. El Administrador los cambia en Configuración (tabla
    // `configuraciones`); estos solo se usan si esa fila aún no existe.
    'reservas' => [
        'tolerancia_minutos' => (int) env('FRAGUA_REAL_RESERVA_TOLERANCIA_MINUTOS', 15),
        'recordatorio_minutos' => (int) env('FRAGUA_REAL_RESERVA_RECORDATORIO_MINUTOS', 120),
        'ventana_reservada_minutos' => (int) env('FRAGUA_REAL_RESERVA_VENTANA_MINUTOS', 60),
    ],

    // Fase 3, tarea 4 (RF-M01-010, RNF-M01-005, decisión de seguridad): política de las sesiones.
    // Una sesión se cierra sola si pasa este tiempo sin usarse (inactividad) o, aunque se use
    // todos los días, al cumplir la duración máxima; después hay que volver a iniciar sesión.
    // Valores iniciales propuestos: 7 días sin uso y 30 días como máximo. Se cambian en el .env.
    'sesiones' => [
        'inactividad_minutos' => (int) env('FRAGUA_REAL_SESION_INACTIVIDAD_MINUTOS', 60 * 24 * 7),
        'duracion_maxima_minutos' => (int) env('FRAGUA_REAL_SESION_DURACION_MAXIMA_MINUTOS', 60 * 24 * 30),
    ],

    // Fase 6, tarea 1 (RF-M06-006, decisión D4 del 13/10/2026): pasarela de pago en línea.
    // 'wompi' manda al Cliente al checkout de Wompi (Nequi, botón Bancolombia y lo demás que Wompi
    // ofrezca) y el pago se confirma solo con el evento firmado. 'manual' es el respaldo: el pago
    // queda "en revisión" y lo aprueba el Mesero o el Administrador, como antes. Si se pide 'wompi'
    // pero faltan llaves en config/services.php, el sistema sigue en manual (no se rompe el cobro).
    'pasarela' => [
        'proveedor' => env('PASARELA_PROVEEDOR', 'manual'),
        // Cuánto vale el enlace del checkout. Pasado este tiempo, un pago que nunca llegó a la
        // pasarela se da por abandonado y el Cliente puede intentar de nuevo.
        'minutos_checkout' => (int) env('PASARELA_MINUTOS_CHECKOUT', 30),
    ],
];
