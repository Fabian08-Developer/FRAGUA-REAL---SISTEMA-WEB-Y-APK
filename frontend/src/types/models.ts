// Interfaces TypeScript que reflejan los modelos Eloquent del backend
// (app/Models/*.php). Se amplían a medida que cada vista los necesita —
// esto es un punto de partida, no el modelo completo de cada tabla.

export type Rol = 'administrador' | 'mesero' | 'cliente'

// Espeja app/Models/User.php: los nombres de campo son los del backend
// (role, email, nombre, telefono, activo) — no se traducen en el frontend
// para no tener que mapear cada respuesta de la API.
export interface Usuario {
  id: number
  nombre: string
  email: string
  telefono: string | null
  role: Rol
  activo: boolean
}

// Espeja app/Models/Mesa.php. OJO: el backend usa "nombre" y "qr_code" (no
// "numero"/"qr_token" como tenía esta interfaz antes de M05) — corregido para
// que GET /mesas se pueda consumir directo, sin mapear campos.
export interface Mesa {
  id: number
  nombre: string
  estado: 'libre' | 'ocupada' | 'reservada'
  qr_code: string | null
  activa: boolean
  // Fase 1, tarea 3: inicio de la visita en curso (null si la mesa no está ocupada).
  ocupada_desde?: string | null
  // Solo para el personal y solo en mesas ocupadas (GET /mesas): resumen de la cuenta.
  cuenta_total?: number
  cuenta_saldo?: number
  cuenta_pedidos?: number
}

// Espeja app/Models/Variante.php — presentación/tamaño con su propio precio
// fijo (no un delta sobre precio_base).
export interface Variante {
  id: number
  producto_id: number
  nombre: string
  precio: number
  activa: boolean
  // Fase 1: agotada temporalmente (RF-M04-016); "activa" es la baja del catálogo.
  disponible?: boolean
}

// Espeja app/Models/Extra.php — "general" aplica a cualquier producto,
// "especifico" solo al producto_id indicado (RN-M04-05).
export interface Extra {
  id: number
  nombre: string
  precio: number
  alcance: 'general' | 'especifico'
  producto_id: number | null
  activo: boolean
  // Fase 2, tarea 3 (RF-M04-011): productos a los que se asoció un extra general (vacío = todo el menú).
  productos?: Pick<Producto, 'id' | 'nombre'>[]
}

// Espeja app/Models/Producto.php tal cual — precio_base (no "precio"),
// imagen_url, activo/disponible son campos separados (RN-M04-08).
export interface Producto {
  id: number
  categoria_id: number
  nombre: string
  descripcion: string | null
  precio_base: number
  tipo_venta: 'bebida' | 'alimento' | 'otro'
  disponible: boolean
  activo: boolean
  imagen_url: string | null
  categoria?: Categoria
  variantes?: Variante[]
  extras_especificos?: Extra[]
  // Fase 2, tarea 3 (RF-M04-010/011): lo que se ofrece con este producto en Menú, QR y Mesero
  // (específicos activos + generales que le aplican); y los generales asociados, para el formulario.
  extras_disponibles?: Extra[]
  extras_generales?: Pick<Extra, 'id' | 'nombre'>[]
  // Respuesta de editar un producto: combos que se desactivaron al darlo de baja (RN-M04-12).
  combos_desactivados?: string[]
  // Fase 2, tarea 1 (RF-M04-035): promociones automáticas de producto o categoría vigentes ahora.
  promociones_vigentes?: PromocionVigente[]
}

export interface PromocionVigente {
  nombre: string
  tipo_valor: 'porcentaje' | 'fijo'
  valor: number
}

// Fase 2, tarea 1: lo que rebajó cada promoción (o el descuento manual) en un pedido.
export interface PromocionAplicada {
  promocion_id: number | null
  nombre: string
  monto: number
}

// Regla del descuento manual guardada con el pedido (RF-M04-036).
export interface ReglaDescuento {
  promocion_id: number | null
  nombre: string
  alcance: 'producto' | 'categoria' | 'total'
  tipo_valor: 'porcentaje' | 'fijo'
  valor: number
}

export interface Categoria {
  id: number
  nombre: string
  activo: boolean
  productos?: Producto[]
}

// Espeja app/Models/Combo.php. `disponible()` es un método calculado en el
// backend (RN-M04-11), no una columna — por eso no aparece serializado aquí.
export interface Combo {
  id: number
  nombre: string
  precio: number
  tipo_venta: 'bebida' | 'alimento' | 'otro'
  vigencia_inicio: string | null
  vigencia_fin: string | null
  franja_inicio: string | null
  franja_fin: string | null
  dias_semana: number[] | null
  activo: boolean
  productos?: (Producto & { pivot: { cantidad: number } })[]
}

// Espeja app/Models/Promocion.php — RN-M04-19 a 23: vigencia_inicio/vigencia_fin es cómo
// se modelan fechas especiales (Navidad, Amor y Amistad, etc.), sin lógica de negocio
// especial por fecha; franja_inicio/franja_fin y dias_semana son filtros adicionales
// opcionales. alcance decide si productos o categorias aplica (nunca ambos a la vez).
export interface Promocion {
  id: number
  nombre: string
  tipo: 'automatica' | 'manual'
  alcance: 'producto' | 'categoria' | 'total'
  tipo_valor: 'porcentaje' | 'fijo'
  valor: number
  vigencia_inicio: string | null
  vigencia_fin: string | null
  franja_inicio: string | null
  franja_fin: string | null
  dias_semana: number[] | null
  activo: boolean
  productos?: Producto[]
  categorias?: Categoria[]
}

// M02/M07 — tabla `pedidos` unificada (ADR-001 del backend): el canal
// distingue Local, Domicilio y Recogida.
export type TipoPedido = 'local' | 'domicilio' | 'recogida'
export type EstadoPedido = 'pendiente' | 'confirmado' | 'preparando' | 'finalizado' | 'cancelado'

// Espeja app/Models/PedidoDetalle.php — precio_unitario_congelado es el precio
// fijado al momento del pedido (ADR-002), no el precio_base actual del producto.
export interface PedidoDetalle {
  id: number
  producto_id: number | null
  combo_id: number | null
  variante_id: number | null
  cantidad: number
  precio_unitario_congelado: number
  // Fase 2, tarea 1: parte del descuento del pedido que le tocó a esta línea.
  descuento?: number
  preparado: boolean
  observaciones: string | null
  producto?: Producto
  variante?: Variante
  combo?: Combo
  extras?: (Extra & { pivot: { precio_congelado: number } })[]
}

// Espeja app/Models/Pago.php. OJO: las relaciones del backend se llaman
// "registradoPorUsuario"/"anuladoPorUsuario" (no "registradoPor"/"anuladoPor" a secas)
// porque Eloquent serializa una relación cargada con Str::snake(nombre) —
// "registradoPor" colisionaría con la columna "registrado_por" y la sobrescribiría en el
// JSON (bug real, visto en vivo al construir CajaView admin). Str::snake también aplica
// al nombre largo, así que la llave JSON real es "registrado_por_usuario" (snake_case),
// no "registradoPorUsuario" camelCase — ojo al consumir la respuesta.
export interface Pago {
  id: number
  pedido_id: number
  // Fase 2, tarea 2 (RF-M05-034, D7): "deposito" = abono del depósito de una reserva (sin turno de caja).
  tipo?: 'cobro' | 'deposito'
  reserva_id?: number | null
  caja_turno_id: number | null
  metodo: 'efectivo' | 'tarjeta' | 'transferencia' | 'en_linea' | null
  pasarela: 'nequi' | 'bancolombia' | null
  monto: number
  estado: 'pendiente' | 'aprobado' | 'rechazado'
  registrado_por: number | null
  anulado: boolean
  // RF-M06-017: si el reverso fue anulación o reembolso (null si nunca se reversó).
  // Fase 2, tarea 2: "ajuste_abono" = el sistema redujo el abono del depósito porque el pedido bajó o se canceló.
  reverso_tipo?: 'anulacion' | 'reembolso' | 'ajuste_abono' | null
  anulado_motivo: string | null
  anulado_por: number | null
  anulado_at: string | null
  created_at: string
  pedido?: Pedido
  registrado_por_usuario?: Usuario | null
  anulado_por_usuario?: Usuario | null
}

// Espeja app/Models/CajaTurno.php (M06) — RN-M06-03: un solo turno de caja por día.
// abierto_por_usuario/cerrado_por_usuario: mismo motivo que arriba (evitar colisión con
// las columnas abierto_por/cerrado_por al serializar; la llave JSON es snake_case).
export type EstadoCajaTurno = 'abierta' | 'cerrada'

export interface CajaTurno {
  id: number
  fecha: string
  base_inicial: number
  efectivo_esperado: number
  efectivo_contado: number | null
  diferencia: number | null
  justificacion_descuadre: string | null
  abierto_por: number
  abierto_at: string
  cerrado_por: number | null
  cerrado_at: string | null
  estado: EstadoCajaTurno
  abierto_por_usuario?: Usuario
  cerrado_por_usuario?: Usuario | null
  pagos?: Pago[]
  // Solo en GET /caja/turnos/{id} (CajaController::show, Fase 0 tarea 3): pagos en línea del
  // día (no llevan caja_turno_id), compras y gastos en efectivo que restan (RF-M06-013) y
  // ajustes extraordinarios hechos con la caja ya cerrada (RF-M06-019).
  pagos_en_linea?: Pago[]
  gastos_efectivo?: number
  ajustes?: AjusteCaja[]
  // Fase 2, tarea 2 (RN-M05-16/17, RN-M06-08): depósitos de reserva recibidos y devueltos en el turno.
  depositos_reserva?: Reserva[]
  depositos_devueltos?: Reserva[]
  depositos_efectivo?: number
  reembolsos_deposito_efectivo?: number
}

// Espeja app/Models/AjusteCaja.php — RF-M06-019, RN-M06-10.
export interface AjusteCaja {
  id: number
  caja_turno_id: number
  pago_id: number
  tipo: 'anulacion' | 'reembolso'
  metodo: string
  monto: number
  motivo: string
  autorizado_por: number
  created_at: string
  autorizado_por_usuario?: Usuario | null
}

// Espeja app/Models/Pedido.php (tabla unificada Local/Domicilio/Recogida, ADR-001).
export interface Pedido {
  id: number
  tipo: TipoPedido
  origen: 'mesero' | 'qr' | 'cliente_web'
  estado: EstadoPedido
  mesa_id: number | null
  cliente_id: number | null
  cliente_invitado_nombre: string | null
  cliente_invitado_telefono: string | null
  // RF-M07-004, RF-M07-R-003: teléfono para llamar al cliente (domicilio y recogida).
  telefono_contacto?: string | null
  direccion_entrega: string | null
  barrio: string | null
  referencia: string | null
  // RF-M07-010: observaciones de entrega del pedido completo.
  observaciones_entrega?: string | null
  // RF-M07-013/015, RF-M07-R-007/008: cómo dijo el cliente que iba a pagar.
  metodo_pago_preferido?: 'contra_entrega' | 'en_linea' | null
  pasarela_preferida?: 'nequi' | 'bancolombia' | null
  valor_domicilio: number | null
  subtotal: number
  // Fase 2, tarea 1 (RF-M04-035 a 038): total = subtotal − descuento + valor_domicilio.
  descuento?: number
  promociones_aplicadas?: PromocionAplicada[] | null
  descuento_manual?: ReglaDescuento | null
  descuento_manual_motivo?: string | null
  descuento_manual_at?: string | null
  descuento_manual_por?: { id: number; nombre: string } | null
  total: number
  created_at: string
  // RF-M06-005: solo llega en la respuesta de crear el pedido (el backend lo oculta después).
  token_publico?: string
  // RF-M07-025 / RF-M07-R-013: el Mesero confirma la entrega (domicilio) o la recogida.
  entregado_at?: string | null
  recogido_at?: string | null
  finalizado_at?: string | null
  cancelado_motivo?: string | null
  mesa?: Mesa
  cliente?: Usuario | null
  detalles?: PedidoDetalle[]
  pagos?: Pago[]
}

// Espeja app/Models/Reserva.php — sin cliente_id ni "número de personas": la reserva
// se hace sobre UNA mesa concreta y solo pide datos de contacto (RN-M05-12), esté o no
// autenticado quien reserva.
export type EstadoReserva = 'pendiente_pago' | 'confirmada' | 'cancelada' | 'no_show' | 'completada'
export type EstadoDepositoReserva = 'pendiente' | 'aprobado' | 'rechazado' | null

export interface Reserva {
  id: number
  mesa_id: number
  cliente_nombre: string
  cliente_telefono: string
  cliente_correo: string
  fecha_hora: string
  estado: EstadoReserva
  deposito_valor: number
  deposito_pagado: boolean
  deposito_metodo: 'efectivo' | 'tarjeta' | 'transferencia' | 'en_linea' | null
  deposito_pasarela: 'nequi' | 'bancolombia' | null
  deposito_estado: EstadoDepositoReserva
  deposito_reembolsado: boolean
  deposito_aplicado_abono: boolean
  // Fase 1, tarea 4: el Administrador también registra reservas manuales (RN-M05-06), y
  // registrada_por dice quién del personal la registró.
  creado_por: 'cliente_web' | 'mesero' | 'administrador'
  registrada_por?: number | null
  registrada_por_usuario?: Pick<Usuario, 'id' | 'nombre'> | null
  // Fase 2, tarea 2 (RF-M05-034): cuánto del depósito ya se abonó a los pedidos de la visita y cuándo llegó.
  pedido_id?: number | null
  deposito_abonado?: number
  llegada_at?: string | null
  deposito_caja_turno_id?: number | null
  deposito_reembolso_caja_turno_id?: number | null
  mesa?: Mesa
}

// Espeja app/Models/CompraGasto.php (M03). 'estado' en vez de "anulado": el backend lo
// expone como 'activo' | 'anulado', nunca se borra físicamente (RN-M03-05).
export interface CompraGasto {
  id: number
  tipo: 'compra' | 'gasto'
  categoria: string | null
  descripcion: string
  valor: number
  metodo_pago: 'efectivo' | 'transferencia' | 'tarjeta' | 'otro'
  proveedor: string | null
  numero_factura: string | null
  soporte_url: string | null
  fecha: string
  estado: 'activo' | 'anulado'
  anulado_motivo: string | null
  registrado_por_usuario?: Usuario
  // Fase 2, tarea 4 (RF-M03-03/04/07/19): datos informativos, soporte privado y trazabilidad.
  cantidad?: number | null
  unidad?: string | null
  observaciones?: string | null
  tiene_soporte?: boolean
  soporte_nombre?: string | null
  soporte_mime?: string | null
  created_at?: string
  editado_at?: string | null
  editado_por_usuario?: Pick<Usuario, 'id' | 'nombre'> | null
  anulado_at?: string | null
  anulado_por_usuario?: Pick<Usuario, 'id' | 'nombre'> | null
}

// Espeja app/Models/CierreDiario.php (M03) — la "foto" contable del día (RN-M03-09/10),
// generada una sola vez por fecha (CierreDiarioService::generar()).
export interface CierreDiario {
  id: number
  fecha: string
  total_ventas: number
  total_compras: number
  total_gastos: number
  resultado_operativo: number
  cerrado_por: number
  cerrado_por_usuario?: Usuario
  created_at?: string
  // Fase 2, tarea 5: la foto del desglose al cerrar, el arqueo que la alimentó y cuántas
  // correcciones tuvo después (RF-M03-22 a 27, RN-M06-11, HU-M03-09).
  detalle?: CalculoCierre | null
  caja_turno_id?: number | null
  ajustes_count?: number
}

// Fase 2, tarea 5 — CierreDiarioService::calcular(): el día con su desglose (RF-M03-21 a 24).
export interface CalculoCierre {
  fecha: string
  ventas: {
    total: number
    pedidos: number
    por_tipo: { tipo_venta: 'bebida' | 'alimento' | 'otro'; monto: number }[]
    por_metodo: { metodo: string; monto: number }[]
  }
  compras: { total: number; por_categoria: { categoria: string; monto: number; cantidad: number }[] }
  gastos: { total: number; por_categoria: { categoria: string; monto: number; cantidad: number }[] }
  compras_gastos_por_forma_de_pago: { metodo: string; compras: number; gastos: number; total: number }[]
  resultado_operativo: number
  caja: {
    id: number
    estado: 'abierta' | 'cerrada'
    base_inicial: number
    efectivo_esperado: number
    efectivo_contado: number | null
    diferencia: number | null
    justificacion: string | null
  } | null
  nota: string
}

// GET /cierres-diarios/previa: el cálculo de hoy y si ya se cerró (RF-M03-26).
export interface PreviaCierre extends CalculoCierre {
  cerrado: boolean
  cierre_id: number | null
  puede_cerrar: boolean
  motivo_no_cierra: string | null
}

// Corrección autorizada a un día cerrado (RF-M03-20, RN-M03-08, HU-M03-09).
export interface CierreAjuste {
  id: number
  accion: 'registrar' | 'editar' | 'anular'
  compra_gasto_id: number | null
  delta_compras: number
  delta_gastos: number
  detalle: Record<string, unknown> | null
  motivo: string
  created_at: string
  autorizado_por_usuario?: Pick<Usuario, 'id' | 'nombre'>
  compra_gasto?: Pick<CompraGasto, 'id' | 'descripcion' | 'tipo'> | null
}

// GET /cierres-diarios/{id}: el cierre tal como se hizo, sus ajustes y los valores actualizados.
export interface ConsultaCierre {
  cierre: CierreDiario
  ajustes: CierreAjuste[]
  actualizado: { total_ventas: number; total_compras: number; total_gastos: number; resultado_operativo: number }
  calculo_hoy: CalculoCierre
  nota: string
}
