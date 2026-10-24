<script setup lang="ts">
// M01 — RF-M01-012, RN-USR-11: solicitar recuperación de contraseña. El
// backend siempre responde el mismo mensaje exista o no la cuenta (no
// revela si el correo está registrado), así que esta vista no distingue
// éxito de "no existe" — solo muestra la respuesta tal cual.
import { ref } from 'vue'
import { ArrowLeft, ArrowRight, Key, Mail } from 'lucide-vue-next'
import BaseInput from '@/components/common/BaseInput.vue'
import BaseButton from '@/components/common/BaseButton.vue'
import { authService } from '@/services/auth.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'

const email = ref('')
const cargando = ref(false)
const enviado = ref(false)
const mensaje = ref('')
const erroresCampo = ref<Record<string, string>>({})

async function onSubmit() {
  erroresCampo.value = {}
  cargando.value = true
  try {
    const { data } = await authService.solicitarRecuperacion(email.value)
    mensaje.value = data.message
    enviado.value = true
  } catch (e) {
    erroresCampo.value = erroresDeValidacion(e)
    if (Object.keys(erroresCampo.value).length === 0) {
      mensaje.value = mensajeDeError(e)
    }
  } finally {
    cargando.value = false
  }
}
</script>

<template>
  <div v-if="enviado" class="space-y-4 text-center">
    <span class="mx-auto flex size-11 items-center justify-center rounded-xl bg-brand-500/15 text-brand-700" aria-hidden="true">
      <Mail :size="20" />
    </span>
    <span class="text-[11px] font-medium tracking-[2px] text-brand-700">LISTO</span>
    <h1 class="font-display text-3xl font-medium tracking-tight text-ink">Revisa tu correo</h1>
    <p class="text-sm text-[#6c655c]">{{ mensaje }}</p>
    <router-link to="/restablecer-password" class="inline-block text-sm font-medium text-brand-700 hover:text-brand-800">
      Ya tengo un código, restablecer contraseña
    </router-link>
  </div>
  <form v-else class="space-y-5" @submit.prevent="onSubmit">
    <div class="mb-2">
      <span class="mb-4 flex size-11 items-center justify-center rounded-xl bg-brand-500/15 text-brand-700" aria-hidden="true">
        <Key :size="20" />
      </span>
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">RECUPERAR ACCESO</span>
      <h1 class="mt-2 font-display text-3xl font-medium tracking-tight text-ink">¿Olvidaste tu contraseña?</h1>
    </div>
    <p class="text-sm text-[#6c655c]">
      Escribe el correo de tu cuenta y te enviaremos un enlace para crear una nueva contraseña.
    </p>
    <BaseInput formato="correo"
      v-model="email"
      type="email"
      label="Correo electrónico"
      placeholder="tu@correo.com"
      :icon="Mail"
      :error="erroresCampo.email"
    />
    <p v-if="mensaje" class="text-sm text-red-600">{{ mensaje }}</p>
    <BaseButton type="submit" :disabled="cargando" class="w-full">
      <span class="flex w-full items-center justify-between">
        {{ cargando ? 'Enviando…' : 'Enviar enlace de recuperación' }}
        <ArrowRight :size="16" />
      </span>
    </BaseButton>
    <router-link
      to="/login"
      class="flex items-center justify-center gap-2 pt-1 text-sm font-medium text-[#6c655c] hover:text-brand-700"
    >
      <ArrowLeft :size="15" />
      Volver a iniciar sesión
    </router-link>
  </form>
</template>
