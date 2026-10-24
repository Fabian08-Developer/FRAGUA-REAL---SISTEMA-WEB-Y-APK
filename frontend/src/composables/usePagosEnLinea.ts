// Pago directo (22/10/2026, RF-M06-030): cómo se paga en línea ahora mismo. Con Wompi activo
// («automatico») el Cliente paga en la pasarela; sin Wompi transfiere a las cuentas que dejó el
// SuperAdmin y sube la captura. Si no hay ninguna de las dos, no se ofrece el pago en línea.
// GET /configuracion se pide una vez por visita y lo comparten todas las pantallas.
import { computed, onMounted, ref } from 'vue'
import { configuracionService, type PagosDirectosPublico } from '@/services/configuracion.service'

let pendiente: Promise<PagosDirectosPublico | null> | null = null

function cargarUnaVez(): Promise<PagosDirectosPublico | null> {
  pendiente ??= configuracionService
    .obtener()
    .then(({ data }) => data.pagos_directos ?? null)
    .catch(() => {
      pendiente = null // se reintenta en la próxima pantalla
      return null
    })
  return pendiente
}

export function usePagosEnLinea() {
  const pagos = ref<PagosDirectosPublico | null>(null)
  const cargado = ref(false)

  onMounted(async () => {
    pagos.value = await cargarUnaVez()
    cargado.value = true
  })

  const disponible = computed(() => !!pagos.value && (pagos.value.automatico || pagos.value.activo))
  const directo = computed(() => !!pagos.value && !pagos.value.automatico && pagos.value.activo)

  return { pagos, cargado, disponible, directo }
}
