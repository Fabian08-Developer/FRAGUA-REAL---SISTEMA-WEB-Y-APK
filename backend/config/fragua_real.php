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
];
