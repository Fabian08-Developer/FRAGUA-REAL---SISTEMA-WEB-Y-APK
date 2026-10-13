<script setup lang="ts">
// M01 — el usuario autenticado (Cliente, Mesero o Administrador) edita sus propios
// datos y cambia su propia contraseña. Dos formularios independientes: cada uno
// envía y valida por separado (AuthController::actualizarPerfil / cambiarPassword).
import { reactive, ref, watch } from 'vue'
import { CheckCircle2, KeyRound, Lock, Mail, Phone, User } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseInput from '@/components/common/BaseInput.vue'
import { useAuthStore } from '@/stores/auth'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'

const auth = useAuthStore()

const ROLES: Record<string, string> = {
  cliente: 'Cliente',
  mesero: 'Mesero',
  administrador: 'Administrador',
}

// ---- Datos del perfil ----
const datos = reactive({ nombre: '', telefono: '' })

watch(
  () => auth.usuario,
  (usuario) => {
    if (usuario) {
      datos.nombre = usuario.nombre
      datos.telefono = usuario.telefono ?? ''
    }
  },
  { immediate: true },
)

const guardandoDatos = ref(false)
const erroresDatos = ref<Record<string, string>>({})
const errorDatos = ref('')
const exitoDatos = ref(false)

async function guardarDatos() {
  guardandoDatos.value = true
  erroresDatos.value = {}
  errorDatos.value = ''
  exitoDatos.value = false
  try {
    await auth.actualizarPerfil({ nombre: datos.nombre, telefono: datos.telefono || undefined })
    exitoDatos.value = true
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) {
      erroresDatos.value = campos
    } else {
      errorDatos.value = mensajeDeError(error, 'No pudimos guardar tus datos. Intenta de nuevo.')
    }
  } finally {
    guardandoDatos.value = false
  }
}

// ---- Cambiar contraseña ----
const password = reactive({ actual: '', nueva: '', confirmacion: '' })
const guardandoPassword = ref(false)
const erroresPassword = ref<Record<string, string>>({})
const errorPassword = ref('')
const exitoPassword = ref(false)

async function cambiarPassword() {
  guardandoPassword.value = true
  erroresPassword.value = {}
  errorPassword.value = ''
  exitoPassword.value = false
  try {
    await auth.cambiarPassword({
      password_actual: password.actual,
      password: password.nueva,
      password_confirmation: password.confirmacion,
    })
    exitoPassword.value = true
    password.actual = ''
    password.nueva = ''
    password.confirmacion = ''
  } catch (error) {
    const campos = erroresDeValidacion(error)
    if (Object.keys(campos).length) {
      erroresPassword.value = campos
    } else {
      errorPassword.value = mensajeDeError(error, 'No pudimos cambiar tu contraseña. Intenta de nuevo.')
    }
  } finally {
    guardandoPassword.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
    <div class="mb-8 flex items-center gap-3">
      <span class="h-0.5 w-6 bg-brand-500" aria-hidden="true" />
      <span class="text-[11px] font-medium tracking-[2px] text-brand-700">TU CUENTA</span>
    </div>
    <h1 class="font-display text-4xl font-medium tracking-tight text-ink">Tu perfil</h1>

    <div v-if="auth.usuario" class="mt-8 space-y-8">
      <!-- Datos -->
      <section class="rounded-2xl border border-[#e2dbcf] bg-white p-6">
        <div class="flex items-center justify-between">
          <h2 class="font-display text-lg text-ink">Tus datos</h2>
          <span class="rounded-full bg-brand-500/15 px-3 py-1 text-xs font-medium text-brand-700">
            {{ ROLES[auth.usuario.role] ?? auth.usuario.role }}
          </span>
        </div>

        <form class="mt-5 space-y-4" @submit.prevent="guardarDatos">
          <BaseInput v-model="datos.nombre" label="Nombre" :icon="User" :error="erroresDatos.nombre" />
          <BaseInput label="Correo electrónico" :icon="Mail" :model-value="auth.usuario.email" disabled />
          <BaseInput v-model="datos.telefono" label="Teléfono (opcional)" :icon="Phone" :error="erroresDatos.telefono" />

          <p v-if="errorDatos" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorDatos }}</p>
          <p v-if="exitoDatos" class="flex items-center gap-2 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle2 :size="16" />
            Tus datos quedaron guardados.
          </p>

          <BaseButton type="submit" :disabled="guardandoDatos">
            {{ guardandoDatos ? 'Guardando…' : 'Guardar cambios' }}
          </BaseButton>
        </form>
      </section>

      <!-- Contraseña -->
      <section class="rounded-2xl border border-[#e2dbcf] bg-white p-6">
        <h2 class="font-display text-lg text-ink">Cambiar contraseña</h2>
        <p class="mt-1 text-xs text-[#6c655c]">Al cambiarla, cerramos tus demás sesiones activas por seguridad.</p>

        <form class="mt-5 space-y-4" @submit.prevent="cambiarPassword">
          <BaseInput
            v-model="password.actual"
            type="password"
            label="Contraseña actual"
            :icon="Lock"
            :error="erroresPassword.password_actual"
          />
          <BaseInput
            v-model="password.nueva"
            type="password"
            label="Nueva contraseña"
            :icon="KeyRound"
            :error="erroresPassword.password"
          />
          <BaseInput v-model="password.confirmacion" type="password" label="Confirmar nueva contraseña" :icon="KeyRound" />

          <p v-if="errorPassword" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorPassword }}</p>
          <p v-if="exitoPassword" class="flex items-center gap-2 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-700">
            <CheckCircle2 :size="16" />
            Tu contraseña se actualizó correctamente.
          </p>

          <BaseButton type="submit" variant="secondary" :disabled="guardandoPassword">
            {{ guardandoPassword ? 'Actualizando…' : 'Cambiar contraseña' }}
          </BaseButton>
        </form>
      </section>
    </div>
  </div>
</template>
