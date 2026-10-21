// Mejora (19/10/2026): dirección, teléfono y logo del negocio, que el Administrador cambia en
// Configuración (GET /configuracion, campo `negocio`). Se piden una sola vez por visita; mientras
// llegan se muestran los mismos valores de siempre, así el Inicio y el pie de página no saltan.
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { configuracionService, type DatosNegocio } from '@/services/configuracion.service'

const POR_DEFECTO: DatosNegocio = {
  nombre: 'Fragua Real Burger',
  nit: '',
  direccion: 'Esquina frente al malecón turístico, etapa 1',
  telefono: '322 725 9965',
  whatsapp_url: 'https://wa.me/573227259965',
  latitud: null,
  longitud: null,
  logo_url: null,
}

export const useNegocioStore = defineStore('negocio', () => {
  const datos = ref<DatosNegocio>({ ...POR_DEFECTO })
  let pedido: Promise<void> | null = null

  function cargar(forzar = false): Promise<void> {
    if (pedido && !forzar) return pedido
    pedido = configuracionService
      .obtener()
      .then(({ data }) => {
        if (data.negocio) datos.value = data.negocio
      })
      .catch(() => {
        // Sin conexión se quedan los valores de siempre; se vuelve a intentar en la próxima carga.
        pedido = null
      })
    return pedido
  }

  /** Lo que devuelve el servidor al guardar en Configuración: se ve de inmediato en todo el sitio. */
  function fijar(nuevos: DatosNegocio) {
    datos.value = nuevos
  }

  // M09 (20/10/2026): «Cómo llegar» abre Google Maps en el punto que marcó el SuperAdmin (lo arma el
  // servidor); sin punto, busca la dirección.
  const mapaUrl = computed(
    () => datos.value.mapa_url ?? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(datos.value.direccion)}`,
  )
  const tienePunto = computed(() => datos.value.latitud != null && datos.value.longitud != null)
  const whatsappUrl = computed(() => datos.value.whatsapp_url ?? POR_DEFECTO.whatsapp_url!)

  return { datos, cargar, fijar, mapaUrl, whatsappUrl, tienePunto }
})
