// Corrección (19/10/2026): horario de reservas (4:00 p. m. a 11:00 p. m.) y anticipación mínima
// (3 horas), los mismos valores que valida el servidor (PoliticaReservas). Las horas se ofrecen
// cada 30 minutos y se calculan en hora de Colombia (UTC−5, sin horario de verano), aunque el
// teléfono del cliente esté en otra zona.

export interface HorarioReservas {
  inicio: string // "HH:MM"
  fin: string // "HH:MM", incluida
  anticipacionMinutos: number
}

export const HORARIO_POR_DEFECTO: HorarioReservas = { inicio: '16:00', fin: '23:00', anticipacionMinutos: 180 }

export interface HoraReserva {
  valor: string // "HH:MM"
  texto: string // "4:00 p. m."
  disponible: boolean
}

const DESFASE_BOGOTA_MS = 5 * 60 * 60 * 1000
const PASO_MINUTOS = 30

function aMinutos(hhmm: string): number {
  const [h, m] = hhmm.split(':').map(Number)
  return h * 60 + m
}

export function textoHora(hhmm: string): string {
  const total = aMinutos(hhmm)
  const h = Math.floor(total / 60)
  const m = total % 60
  const h12 = h % 12 === 0 ? 12 : h % 12
  return `${h12}:${String(m).padStart(2, '0')} ${h < 12 ? 'a. m.' : 'p. m.'}`
}

/** El instante (ms) de una fecha "YYYY-MM-DD" y hora "HH:MM" en hora de Colombia. */
function instante(fecha: string, hhmm: string): number {
  const [a, mes, d] = fecha.split('-').map(Number)
  const total = aMinutos(hhmm)
  return Date.UTC(a, mes - 1, d, Math.floor(total / 60), total % 60) + DESFASE_BOGOTA_MS
}

/** "YYYY-MM-DD" de hoy (o de dentro de `dias`) en Colombia. */
function fechaBogota(ms: number): string {
  return new Date(ms - DESFASE_BOGOTA_MS).toISOString().slice(0, 10)
}

/** Las horas del horario para ese día; las que no cumplen la anticipación quedan no disponibles. */
export function horasDelDia(fecha: string, horario: HorarioReservas, ahora = Date.now()): HoraReserva[] {
  if (!fecha) return []
  const minimo = ahora + horario.anticipacionMinutos * 60 * 1000
  const horas: HoraReserva[] = []
  for (let t = aMinutos(horario.inicio); t <= aMinutos(horario.fin); t += PASO_MINUTOS) {
    const valor = `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`
    horas.push({ valor, texto: textoHora(valor), disponible: instante(fecha, valor) >= minimo })
  }
  return horas
}

/** El primer día que todavía tiene alguna hora disponible (hoy o mañana). */
export function fechaMinimaReserva(horario: HorarioReservas, ahora = Date.now()): string {
  const hoy = fechaBogota(ahora)
  return horasDelDia(hoy, horario, ahora).some((h) => h.disponible) ? hoy : fechaBogota(ahora + 24 * 60 * 60 * 1000)
}

/** "Las reservas son de 4:00 p. m. a 11:00 p. m., con al menos 3 horas de anticipación." */
export function textoHorario(horario: HorarioReservas): string {
  const horas = horario.anticipacionMinutos / 60
  const anticipacion = Number.isInteger(horas) ? `${horas} ${horas === 1 ? 'hora' : 'horas'}` : `${horario.anticipacionMinutos} minutos`
  return `Las reservas son de ${textoHora(horario.inicio)} a ${textoHora(horario.fin)}, con al menos ${anticipacion} de anticipación.`
}
