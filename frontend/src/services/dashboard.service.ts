// Espeja DashboardController::resumen() — KPIs del día para la primera pantalla
// del panel de Administrador (Cronograma_Fragua_Real.xlsx, Semana 2 Lunes).
import api from './api'

export interface ResumenDashboard {
  ventas_hoy: {
    monto_total: number
    cantidad_pedidos: number
  }
  pedidos_activos: {
    total: number
    local: number
    domicilio: number
    recogida: number
  }
  mesas: {
    ocupadas: number
    total: number
  }
}

export const dashboardService = {
  resumen() {
    return api.get<ResumenDashboard>('/dashboard/resumen')
  },
}
