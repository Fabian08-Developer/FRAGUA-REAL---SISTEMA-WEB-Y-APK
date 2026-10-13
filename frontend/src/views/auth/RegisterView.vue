<script setup lang="ts">
// M01 — RF-M01-014: autorregistro, exclusivo para Cliente. RN-USR-04: correo
// único (la validación del lado servidor es la que manda; aquí solo se
// refleja el error 422 que devuelva).
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRight, Lock, Mail, Phone, User } from 'lucide-vue-next'
import BaseInput from '@/components/common/BaseInput.vue'
import BaseButton from '@/components/common/BaseButton.vue'
import { useAuth } from '@/composables/useAuth'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'

const router = useRouter()
const { registrar } = useAuth()

const nombre = ref('')
const email = ref('')
const telefono = ref('')
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
    await registrar({
      nombre: nombre.value,
      email: email.value,
      telefono: telefono.value || undefined,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    router.push('/')
  } catch (e) {
    erroresCampo.value = erroresDeValidacion(e)
    if (Object.keys(erroresCampo.value).length === 0) {
      errorGeneral.value = mensajeDeError(e, 'No se pudo crear la cuenta.')
    }
  } finally {
    cargando.value = false
  }
}
</script>

<template>
  <div class="mb-6">
    <span class="text-[11px] font-medium tracking-[2px] text-brand-700">EMPIEZA AQUÍ</span>
    <h1 class="mt-2 font-display text-3xl font-medium tracking-tight text-ink">Crea tu cuenta</h1>
    <p class="mt-2 text-sm text-[#6c655c]">Unos pocos datos y todo listo para pedir.</p>
  </div>
  <form class="space-y-4" @submit.prevent="onSubmit">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <BaseInput
        v-model="nombre"
        label="Nombre completo"
        placeholder="Tu nombre y apellido"
        :icon="User"
        :error="erroresCampo.nombre"
      />
      <BaseInput
        v-model="telefono"
        type="tel"
        label="Teléfono (opcional)"
        placeholder="Tu número de celular"
        :icon="Phone"
        :error="erroresCampo.telefono"
      />
    </div>
    <BaseInput
      v-model="email"
      type="email"
      label="Correo electrónico"
      placeholder="tu@correo.com"
      :icon="Mail"
      :error="erroresCampo.email"
    />
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
      <BaseInput
        v-model="password"
        type="password"
        label="Contraseña"
        placeholder="Mínimo 8 caracteres"
        :icon="Lock"
        :error="erroresCampo.password"
      />
      <BaseInput
        v-model="passwordConfirmation"
        type="password"
        label="Confirmar contraseña"
        placeholder="••••••••"
        :icon="Lock"
      />
    </div>
    <p v-if="errorGeneral" class="text-sm text-red-600">{{ errorGeneral }}</p>
    <BaseButton type="submit" :disabled="cargando" class="w-full">
      <span class="flex w-full items-center justify-between">
        {{ cargando ? 'Creando cuenta…' : 'Crear cuenta' }}
        <ArrowRight :size="16" />
      </span>
    </BaseButton>
    <p class="text-center text-sm text-[#6c655c]">
      ¿Ya tienes cuenta?
      <router-link to="/login" class="font-medium text-brand-700 hover:text-brand-800">Inicia sesión</router-link>
    </p>

    <div class="flex items-center gap-3 pt-1">
      <span class="h-px flex-1 bg-[#e9e3d8]" aria-hidden="true" />
      <span class="text-xs text-[#a39c8f]">o</span>
      <span class="h-px flex-1 bg-[#e9e3d8]" aria-hidden="true" />
    </div>
    <router-link
      to="/"
      class="flex items-center justify-center gap-2 text-sm font-medium text-ink transition hover:text-brand-700"
    >
      Continuar como invitado
      <ArrowRight :size="15" />
    </router-link>
  </form>
</template>
