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

export const configuracionService = {
  obtener() {
    return api.get<Configuracion>('/configuracion')
  },
  actualizar(payload: Configuracion) {
    return api.put('/configuraciones', payload)
  },
}
