<script setup lang="ts">
// M05 (RF-M05-005/006) — Modal del QR de una mesa: lo pinta como imagen real (no el UUID
// en texto plano), permite descargarlo en PDF e imprimirlo directamente, y permite
// regenerarlo (invalida el anterior). El QR codifica la URL pública que el Cliente
// recorre al escanear: /mesa/:qr_code (RF-M02-L-025, ver ClienteQrView.vue).
//
// Las librerías de QR (qrcode) y PDF (jspdf) son dependencias del proyecto (package.json) y se
// cargan con import() dinámico: Vite las separa en su propio archivo y solo se descargan
// cuando se abre este modal. Antes se traían de un CDN (esm.sh), lo que rompía `vue-tsc -b`
// (no hay tipos para una URL) y hacía que el QR dependiera de un servidor externo.
import { ref, watch } from 'vue'
import { Download, Printer, RefreshCw } from 'lucide-vue-next'
import BaseButton from './BaseButton.vue'
import BaseModal from './BaseModal.vue'
import { mesaService } from '@/services/mesa.service'
import { mensajeDeError } from '@/services/errors'
import type { Mesa } from '@/types/models'

const props = defineProps<{ mesa: Mesa | null }>()
const emit = defineEmits<{
  (e: 'update:mesa', value: Mesa | null): void
}>()

const qrDataUrl = ref('')
const generando = ref(false)
const errorQr = ref('')
const regenerando = ref(false)
const confirmandoRegenerar = ref(false)

function urlPublica(mesa: Mesa): string {
  return `${window.location.origin}/mesa/${mesa.qr_code}`
}

async function generarQr() {
  if (!props.mesa?.qr_code) return
  generando.value = true
  errorQr.value = ''
  qrDataUrl.value = ''
  try {
    const { toDataURL } = await import('qrcode')
    qrDataUrl.value = await toDataURL(urlPublica(props.mesa), { width: 360, margin: 1 })
  } catch (e) {
    errorQr.value = 'No pudimos generar el código QR. Recarga la página e inténtalo de nuevo.'
  } finally {
    generando.value = false
  }
}

watch(
  () => props.mesa,
  (mesa) => {
    confirmandoRegenerar.value = false
    if (mesa) generarQr()
  },
  { immediate: true }
)

function cerrar() {
  emit('update:mesa', null)
}

// ---- Descargar como PDF ----
async function descargarPdf() {
  if (!props.mesa || !qrDataUrl.value) return
  const { jsPDF } = await import('jspdf')
  const doc = new jsPDF({ unit: 'mm', format: 'a4' })
  const anchoPagina = doc.internal.pageSize.getWidth()

  doc.setFontSize(22)
  doc.text('Fragua Real Burger', anchoPagina / 2, 30, { align: 'center' })
  doc.setFontSize(16)
  doc.text(props.mesa.nombre, anchoPagina / 2, 42, { align: 'center' })

  const tamanoQr = 100
  doc.addImage(qrDataUrl.value, 'PNG', (anchoPagina - tamanoQr) / 2, 55, tamanoQr, tamanoQr)

  doc.setFontSize(11)
  doc.text('Escanea este código con tu celular para ver el menú y pedir', anchoPagina / 2, 55 + tamanoQr + 10, {
    align: 'center',
  })

  const nombreArchivo = `qr-${props.mesa.nombre.toLowerCase().replace(/\s+/g, '-')}.pdf`
  doc.save(nombreArchivo)
}

// ---- Imprimir directamente (sin descargar nada) ----
// El nombre de la mesa lo escribe el Administrador: se escapa antes de ponerlo en el HTML.
function escaparHtml(texto: string): string {
  const entidades: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }
  return texto.replace(/[&<>"']/g, (c) => entidades[c] ?? c)
}

function imprimir() {
  if (!props.mesa || !qrDataUrl.value) return
  const nombreMesa = escaparHtml(props.mesa.nombre)
  const iframe = document.createElement('iframe')
  iframe.style.position = 'fixed'
  iframe.style.right = '0'
  iframe.style.bottom = '0'
  iframe.style.width = '0'
  iframe.style.height = '0'
  iframe.style.border = '0'
  document.body.appendChild(iframe)

  const doc = iframe.contentWindow?.document
  if (!doc) {
    document.body.removeChild(iframe)
    return
  }
  doc.open()
  doc.write(`
    <!doctype html>
    <html>
      <head>
        <meta charset="utf-8" />
        <title>QR ${nombreMesa}</title>
        <style>
          body { font-family: Arial, sans-serif; text-align: center; padding: 48px; }
          h1 { font-size: 16px; color: #92400e; margin: 0 0 4px; }
          h2 { font-size: 22px; margin: 0 0 24px; }
          img { width: 280px; height: 280px; }
          p { color: #555; font-size: 13px; margin-top: 20px; }
        </style>
      </head>
      <body>
        <h1>Fragua Real Burger</h1>
        <h2>${nombreMesa}</h2>
        <img src="${qrDataUrl.value}" />
        <p>Escanea este código con tu celular para ver el menú y pedir</p>
      </body>
    </html>
  `)
  doc.close()

  setTimeout(() => {
    iframe.contentWindow?.focus()
    iframe.contentWindow?.print()
    setTimeout(() => document.body.removeChild(iframe), 1000)
  }, 300)
}

// ---- Regenerar QR (RF-M05-006): invalida el anterior, exige confirmar ----
async function confirmarRegenerar() {
  if (!props.mesa) return
  regenerando.value = true
  errorQr.value = ''
  try {
    const { data } = await mesaService.regenerarQr(props.mesa.id)
    confirmandoRegenerar.value = false
    emit('update:mesa', data)
  } catch (e) {
    errorQr.value = mensajeDeError(e, 'No pudimos regenerar el QR.')
  } finally {
    regenerando.value = false
  }
}
</script>

<template>
  <BaseModal :model-value="!!mesa" :title="mesa ? `QR de ${mesa.nombre}` : ''" @update:model-value="cerrar">
    <div v-if="mesa" class="flex flex-col items-center gap-4">
      <div v-if="generando" class="flex h-64 w-64 items-center justify-center text-sm text-gray-400">
        Generando QR…
      </div>
      <img v-else-if="qrDataUrl" :src="qrDataUrl" alt="Código QR de la mesa" class="h-64 w-64 rounded-lg border border-gray-200" />

      <p v-if="errorQr" class="w-full rounded-lg bg-red-50 px-3 py-2 text-center text-xs text-red-700">{{ errorQr }}</p>

      <p class="break-all text-center text-xs text-gray-400">{{ urlPublica(mesa) }}</p>

      <div class="flex w-full flex-wrap justify-center gap-2">
        <BaseButton variant="primary" :disabled="!qrDataUrl" @click="descargarPdf">
          <Download :size="14" class="mr-1.5 inline-block" />
          Descargar PDF
        </BaseButton>
        <BaseButton variant="ghost" :disabled="!qrDataUrl" @click="imprimir">
          <Printer :size="14" class="mr-1.5 inline-block" />
          Imprimir
        </BaseButton>
      </div>

      <div class="mt-2 w-full border-t border-gray-100 pt-4">
        <button
          v-if="!confirmandoRegenerar"
          type="button"
          class="mx-auto flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-gray-700"
          @click="confirmandoRegenerar = true"
        >
          <RefreshCw :size="13" /> Regenerar QR
        </button>
        <div v-else class="text-center">
          <p class="text-xs text-red-700">
            El QR actual dejará de funcionar (si está impreso en la mesa, tendrás que reemplazarlo). ¿Continuar?
          </p>
          <div class="mt-2 flex justify-center gap-2">
            <BaseButton variant="ghost" :disabled="regenerando" @click="confirmandoRegenerar = false">
              Cancelar
            </BaseButton>
            <BaseButton variant="danger" :disabled="regenerando" @click="confirmarRegenerar">
              {{ regenerando ? 'Regenerando…' : 'Sí, regenerar' }}
            </BaseButton>
          </div>
        </div>
      </div>
    </div>
  </BaseModal>
</template>
