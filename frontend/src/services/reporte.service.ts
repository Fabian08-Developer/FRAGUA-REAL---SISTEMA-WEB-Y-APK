// M08 — espeja ReporteController del backend (RF-M08-001/010, ADR-006: solo lectura, sin
// tablas propias). Cubre los 6 reportes de ReportesView.vue (ventas totales, por
// producto/categoría, por tipo y método, compras y gastos, resultado operativo, uso de
// mesas — RF-M08-002 a 009), comparar contra el periodo anterior (RF-M08-010) y exportar a
// PDF/Excel con auditoría (RF-M08-011/012/014) — más comprasYGastos(), que el panel de M03
// ya reutilizaba para el total del día.
import api from './api'

// RN-M08-03: 'semana' es el valor por defecto si el Administrador no elige periodo.
export type PeriodoElegido = 'semana' | 'mes' | 'personalizado'

export interface PeriodoReporte {
  desde: string
  hasta: string
  // RF-M08-015/RN-M08-05: true si el periodo incluye hoy y por lo tanto sigue en curso.
  parcial: boolean
}

// RF-M08-010, RN-M08-04: variación absoluta y porcentual de cada métrica del resumen
// contra el periodo equivalente inmediatamente anterior — misma forma para los 6 reportes,
// porque ReporteController::responder() la arma igual para todos.
export interface VariacionMetrica {
  actual: number
  anterior: number
  variacion_absoluta: number
  variacion_porcentual: number | null
}

export interface Comparacion {
  periodo_anterior: PeriodoReporte
  variacion: Record<string, VariacionMetrica>
}

export interface ComprasYGastosResumen {
  total_compras: number
  total_gastos: number
}

export interface ComprasYGastosReporte {
  periodo: PeriodoReporte
  reporte: {
    resumen: ComprasYGastosResumen
    detalle: {
      por_categoria: { tipo: 'compra' | 'gasto'; categoria: string | null; cantidad: number; total: number }[]
    }
  }
  comparacion?: Comparacion
}

// RF-M08-002.
export interface VentasTotalesReporte {
  periodo: PeriodoReporte
  reporte: {
    resumen: { monto_total: number; cantidad_pedidos: number }
  }
  comparacion?: Comparacion
}

// RF-M08-003/004: unidades_vendidas/monto_vendido por producto y por categoría, más/menos
// vendidos dentro del mismo listado de productos.
// Fase 0, tarea 5: los combos también salen como artículo (es_combo); "clave" distingue un
// producto y un combo que compartan id.
export interface VentaPorProducto {
  clave: string
  id: number
  es_combo: boolean
  nombre: string
  unidades_vendidas: number
  monto_vendido: number
}

export interface VentaPorCategoria {
  id: number
  nombre: string
  unidades_vendidas: number
  monto_vendido: number
}

export interface VentasPorProductoReporte {
  periodo: PeriodoReporte
  reporte: {
    resumen: { unidades_totales: number; monto_total: number }
    detalle: {
      por_producto: VentaPorProducto[]
      mas_vendidos: VentaPorProducto[]
      menos_vendidos: VentaPorProducto[]
      por_categoria: VentaPorCategoria[]
    }
  }
  comparacion?: Comparacion
}

// RF-M08-005/006: por tipo de venta (M04) y por método de pago (M06, solo pagos aprobados
// y no anulados — ver ReporteService::ventasPorTipoYMetodo en el backend).
export interface VentaPorTipo {
  tipo_venta: 'bebida' | 'alimento' | 'otro'
  unidades_vendidas: number
  monto_vendido: number
}

export interface VentaPorMetodo {
  metodo: 'efectivo' | 'tarjeta' | 'transferencia' | 'en_linea' | 'deposito_reserva'
  cantidad: number
  monto_total: number
}

export interface VentasPorTipoYMetodoReporte {
  periodo: PeriodoReporte
  reporte: {
    resumen: { monto_total: number }
    detalle: {
      por_tipo: VentaPorTipo[]
      por_metodo: VentaPorMetodo[]
    }
  }
  comparacion?: Comparacion
}

// RF-M08-007: mismo reporte que comprasYGastos() de abajo, pero sobre el periodo elegido
// (semana/mes/personalizado) en vez de un solo día — reutiliza ComprasYGastosReporte.
export type ComprasYGastosDelPeriodoReporte = ComprasYGastosReporte

// RF-M08-008, RN-M08-06: ventas − compras − gastos del periodo (sin detalle: es un
// agregado de los cierres diarios de M03, ver ReporteService::resultadoOperativo).
export interface ResultadoOperativoReporte {
  periodo: PeriodoReporte
  reporte: {
    resumen: {
      total_ventas: number
      total_compras: number
      total_gastos: number
      resultado_operativo: number
    }
  }
  comparacion?: Comparacion
}

// RF-M08-009: reservas creadas/canceladas/no-show y ocupación por mesa del periodo (M05).
export interface UsoDeMesasPorEstado {
  estado: 'pendiente_pago' | 'confirmada' | 'cancelada' | 'no_show' | 'completada'
  cantidad: number
}

export interface OcupacionPorMesa {
  id: number
  nombre: string
  reservas_completadas: number
}

export interface UsoDeMesasReporte {
  periodo: PeriodoReporte
  reporte: {
    resumen: {
      reservas_creadas: number
      reservas_canceladas: number
      reservas_no_show: number
      mesas_con_uso: number
    }
    detalle: {
      por_estado: UsoDeMesasPorEstado[]
      ocupacion_por_mesa: OcupacionPorMesa[]
    }
  }
  comparacion?: Comparacion
}

// RF-M08-011/012: los 6 reportes exportables a PDF/Excel, con el mismo slug que usa
// ReporteExportRequest en el backend.
export type ReporteExportable =
  | 'ventas-totales'
  | 'ventas-por-producto'
  | 'ventas-por-tipo-y-metodo'
  | 'compras-y-gastos'
  | 'resultado-operativo'
  | 'uso-de-mesas'

// Arma los params de periodo que todos los endpoints de /reportes comparten (RF-M08-001),
// más 'comparar' (RF-M08-010) cuando se pide.
function paramsDePeriodo(periodo: PeriodoElegido, desde?: string, hasta?: string, comparar?: boolean) {
  const base = periodo === 'personalizado' ? { periodo, desde, hasta } : { periodo }
  return comparar ? { ...base, comparar: 1 } : base
}

export const reporteService = {
  // periodo 'personalizado' con desde === hasta === una fecha (Y-m-d) da el total de ese
  // día — la columna `fecha` de compras_gastos es una fecha simple (sin hora), así que no
  // hay ambigüedad de huso horario al compararla (a diferencia de los reportes que sí leen
  // columnas datetime, como resultadoOperativo con Pedido.finalizado_at).
  comprasYGastos(params: { desde: string; hasta: string }) {
    return api.get<ComprasYGastosReporte>('/reportes/compras-y-gastos', {
      params: { periodo: 'personalizado', desde: params.desde, hasta: params.hasta },
    })
  },

  ventasTotales(periodo: PeriodoElegido, desde?: string, hasta?: string, comparar?: boolean) {
    return api.get<VentasTotalesReporte>('/reportes/ventas-totales', {
      params: paramsDePeriodo(periodo, desde, hasta, comparar),
    })
  },

  ventasPorProducto(periodo: PeriodoElegido, desde?: string, hasta?: string, limite = 10, comparar?: boolean) {
    return api.get<VentasPorProductoReporte>('/reportes/ventas-por-producto', {
      params: { ...paramsDePeriodo(periodo, desde, hasta, comparar), limite },
    })
  },

  ventasPorTipoYMetodo(periodo: PeriodoElegido, desde?: string, hasta?: string, comparar?: boolean) {
    return api.get<VentasPorTipoYMetodoReporte>('/reportes/ventas-por-tipo-y-metodo', {
      params: paramsDePeriodo(periodo, desde, hasta, comparar),
    })
  },

  comprasYGastosDelPeriodo(periodo: PeriodoElegido, desde?: string, hasta?: string, comparar?: boolean) {
    return api.get<ComprasYGastosDelPeriodoReporte>('/reportes/compras-y-gastos', {
      params: paramsDePeriodo(periodo, desde, hasta, comparar),
    })
  },

  resultadoOperativo(periodo: PeriodoElegido, desde?: string, hasta?: string, comparar?: boolean) {
    return api.get<ResultadoOperativoReporte>('/reportes/resultado-operativo', {
      params: paramsDePeriodo(periodo, desde, hasta, comparar),
    })
  },

  usoDeMesas(periodo: PeriodoElegido, desde?: string, hasta?: string, comparar?: boolean) {
    return api.get<UsoDeMesasReporte>('/reportes/uso-de-mesas', {
      params: paramsDePeriodo(periodo, desde, hasta, comparar),
    })
  },

  // RF-M08-011/012, RF-M08-014: exporta el reporte ya mostrado en pantalla a PDF o Excel —
  // el backend deja auditoría de cada exportación (usuario, reporte, periodo, formato).
  // 'limite' solo aplica a 'ventas-por-producto' y debe calzar con lo mostrado en pantalla
  // (CA-M08-009/010: el archivo exportado refleja exactamente lo mostrado).
  exportar(
    reporte: ReporteExportable,
    formato: 'pdf' | 'excel',
    periodo: PeriodoElegido,
    desde?: string,
    hasta?: string,
    limite?: number
  ) {
    return api.post(
      '/reportes/exportar',
      {
        reporte,
        formato,
        ...paramsDePeriodo(periodo, desde, hasta),
        ...(limite ? { limite } : {}),
      },
      { responseType: 'blob' }
    )
  },
}
