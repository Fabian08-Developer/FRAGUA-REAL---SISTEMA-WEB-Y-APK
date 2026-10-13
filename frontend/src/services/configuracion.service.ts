// Valores vigentes de domicilio y depósito de reserva.
//  · obtener()    → GET /configuracion  (público, solo lectura): lo usan Checkout y Reservar para
//                   mostrarle el valor al Cliente. El servidor sigue fijando el valor real al crear
//                   el Pedido/la Reserva.
//  · actualizar() → PUT /configuraciones (solo Administrador, RF-M07-030 y RF-M05-028): guarda
//                   ambos valores en la tabla `configuraciones`; aplican a los pedidos/reservas
//                   creados después del cambio.
import api from './api'

export interface Configuracion {
  valor_domicilio: number
  deposito_reserva: number
}

// Fase 4, tarea 1: estado del programador de tareas (cron) y de la cola de trabajos en el
// servidor, con su último latido. GET /sistema/estado, solo Administrador.
export interface EstadoSistema {
  programador: { ultimo_latido: string | null; activo: boolean }
  cola: {
    conexion: string
    sincronica: boolean
    ultimo_latido: string | null
    activa: boolean
    pendientes: number | null
    fallidos: number | null
  }
  correo: { transporte: string; envia_de_verdad: boolean }
  minutos_activo: number
}

export const configuracionService = {
  estadoSistema() {
    return api.get<EstadoSistema>('/sistema/estado')
  },
  obtener() {
    return api.get<Configuracion>('/configuracion')
  },
  actualizar(payload: Configuracion) {
    return api.put('/configuraciones', payload)
  },
}
