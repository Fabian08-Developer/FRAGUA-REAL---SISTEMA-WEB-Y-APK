<script setup lang="ts">
// M01 — RF-M01-013: restablecer contraseña con el token de
// solicitarRecuperacion(). El backend ya envía el correo real
// (App\Notifications\RecuperarPasswordNotification) con un enlace a esta
// vista que trae ?email=&token= — los dos campos de abajo se precargan
// solos desde la URL, pero se dejan editables por si el usuario prefiere
// pegar el código a mano.
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, KeyRound, Lock, Mail } from 'lucide-vue-next'
import BaseInput from '@/components/common/BaseInput.vue'
import BaseButton from '@/components/common/BaseButton.vue'
import { authService } from '@/services/auth.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'

const route = useRoute()
const router = useRouter()

const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const token = ref(typeof route.query.token === 'string' ? route.query.token : '')
const password = ref('')
const passwordConfirmation = ref('')
const cargando = ref(false)
const errorGeneral = ref('')
const erroresCampo = ref<Record<string, string>>({})

async function onSubmit() {
  errorGeneral.value = ''
  erroresCampo.value = {}
  cargando.value = true
  try {
    await authService.restablecerContrasena({
      email: email.value,
      token: token.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    router.push({ name: 'login', query: { restablecida: '1' } })
  } catch (e) {
    erroresCampo.value = erroresDeValidacion(e)
    if (Object.keys(erroresCampo.value).length === 0) {
      errorGeneral.value = mensajeDeError(e, 'El token no es válido o expiró.')
    }
  } finally {
    cargando.value = false
  }
}
</script>

<template>
  <div class="mb-8">
    <span class="text-[11px] font-medium tracking-[2px] text-brand-700">ÚLTIMO PASO</span>
    <h1 class="mt-2 font-display text-3xl font-medium tracking-tight text-ink">Restablecer contraseña</h1>
    <p class="mt-2 text-sm text-[#6c655c]">Pega el código que recibiste y elige una nueva contraseña.</p>
  </div>
  <form class="space-y-5" @submit.prevent="onSubmit">
    <BaseInput
      v-model="email"
      type="email"
      label="Correo electrónico"
      placeholder="tu@correo.com"
      :icon="Mail"
      :error="erroresCampo.email"
    />
    <BaseInput
      v-model="token"
      label="Código de recuperación"
      placeholder="Pega aquí el código recibido"
      :icon="KeyRound"
      :error="erroresCampo.token"
    />
    <BaseInput
      v-model="password"
      type="password"
      label="Nueva contraseña"
      placeholder="Mínimo 8 caracteres"
      :icon="Lock"
      :error="erroresCampo.password"
    />
    <BaseInput
      v-model="passwordConfirmation"
      type="password"
      label="Confirmar nueva contraseña"
      placeholder="••••••••"
      :icon="Lock"
    />
    <p v-if="errorGeneral" class="text-sm text-red-600">{{ errorGeneral }}</p>
    <BaseButton type="submit" :disabled="cargando" class="w-full">
      <span class="flex w-full items-center justify-between">
        {{ cargando ? 'Guardando…' : 'Restablecer contraseña' }}
        <ArrowRight :size="16" />
      </span>
    </BaseButton>
  </form>
</template>
