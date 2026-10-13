<script setup lang="ts">
// M01 — Usuarios del Administrador: listar (staff y clientes), crear cuentas de
// Mesero/Administrador, activar/desactivar y revocar sesiones. Ver UsuarioController.
import { computed, onMounted, ref } from 'vue'
import { KeyRound, Plus, Users as UsersIcon } from 'lucide-vue-next'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { usuarioService } from '@/services/usuario.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import type { Rol, Usuario } from '@/types/models'

const auth = useAuthStore()

const usuarios = ref<Usuario[]>([])
const total = ref(0)
const cargando = ref(true)
const error = ref('')
const procesando = ref<number | null>(null)

const filtroRol = ref<'todos' | Rol>('todos')
const busqueda = ref('')

const OPCIONES_ROL: { v: typeof filtroRol.value; t: string }[] = [
  { v: 'todos', t: 'Todos' },
  { v: 'administrador', t: 'Administrador' },
  { v: 'mesero', t: 'Mesero' },
  { v: 'cliente', t: 'Cliente' },
]

const ROLES: Record<Rol, { texto: string; clase: string }> = {
  administrador: { texto: 'Administrador', clase: 'bg-violet-50 text-violet-700 ring-violet-600/20' },
  mesero: { texto: 'Mesero', clase: 'bg-sky-50 text-sky-700 ring-sky-600/20' },
  cliente: { texto: 'Cliente', clase: 'bg-gray-100 text-gray-600 ring-gray-500/20' },
}

function iniciales(nombre: string) {
  return nombre
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('')
}

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await usuarioService.index({
      rol: filtroRol.value === 'todos' ? undefined : filtroRol.value,
      busqueda: busqueda.value.trim() || undefined,
    })
    usuarios.value = data.data
    total.value = data.total
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos cargar los usuarios.')
  } finally {
    cargando.value = false
  }
}

let debounce: ReturnType<typeof setTimeout> | undefined
function buscar() {
  clearTimeout(debounce)
  debounce = setTimeout(cargar, 300)
}

function reemplazar(actualizado: Usuario) {
  const i = usuarios.value.findIndex((u) => u.id === actualizado.id)
  if (i !== -1) usuarios.value[i] = actualizado
}

async function alternarActivo(usuario: Usuario) {
  procesando.value = usuario.id
  error.value = ''
  try {
    const { data } = usuario.activo
      ? await usuarioService.desactivar(usuario.id)
      : await usuarioService.activar(usuario.id)
    reemplazar(data)
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos actualizar el usuario.')
  } finally {
    procesando.value = null
  }
}

const mensajeRevocar = ref('')
async function revocarSesiones(usuario: Usuario) {
  procesando.value = usuario.id
  error.value = ''
  mensajeRevocar.value = ''
  try {
    await usuarioService.revocarSesiones(usuario.id)
    mensajeRevocar.value = `Sesiones de ${usuario.nombre} revocadas.`
  } catch (e) {
    error.value = mensajeDeError(e, 'No pudimos revocar las sesiones.')
  } finally {
    procesando.value = null
  }
}

// ---- Crear usuario (Mesero/Administrador) ----
const modalAbierto = ref(false)
const form = ref({
  role: 'mesero' as Extract<Rol, 'mesero' | 'administrador'>,
  nombre: '',
  email: '',
  telefono: '',
  password: '',
  password_confirmation: '',
})
const erroresForm = ref<Record<string, string>>({})
const errorForm = ref('')
const creando = ref(false)

function abrirCrear() {
  form.value = { role: 'mesero', nombre: '', email: '', telefono: '', password: '', password_confirmation: '' }
  erroresForm.value = {}
  errorForm.value = ''
  modalAbierto.value = true
}

async function confirmarCrear() {
  creando.value = true
  erroresForm.value = {}
  errorForm.value = ''
  try {
    await usuarioService.crear({ ...form.value, telefono: form.value.telefono || undefined })
    modalAbierto.value = false
    await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresForm.value = campos
    } else {
      errorForm.value = mensajeDeError(e, 'No pudimos crear el usuario.')
    }
  } finally {
    creando.value = false
  }
}

const contadorTexto = computed(() => (total.value === 1 ? '1 usuario' : `${total.value} usuarios`))

onMounted(cargar)
</script>

<template>
  <div>
    <PageHeader eyebrow="ADMINISTRACIÓN" titulo="Usuarios" descripcion="Staff y clientes del sistema.">
      <template #actions>
        <BaseButton variant="primary" @click="abrirCrear">
          <Plus :size="17" />
          Nuevo usuario
        </BaseButton>
      </template>
    </PageHeader>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div class="segmented">
        <button
          v-for="opcion in OPCIONES_ROL"
          :key="opcion.v"
          type="button"
          class="segmented-item"
          :class="filtroRol === opcion.v ? 'is-active' : ''"
          @click="filtroRol = opcion.v; cargar()"
        >
          {{ opcion.t }}
        </button>
      </div>
      <input
        v-model="busqueda"
        type="text"
        placeholder="Buscar por nombre o correo…"
        class="field sm:w-64"
        @input="buscar"
      />
    </div>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>
    <p v-if="mensajeRevocar" class="mt-4 alert-success">{{ mensajeRevocar }}</p>

    <div v-if="cargando" class="loading-state">Cargando usuarios…</div>

    <div v-else-if="!usuarios.length" class="mt-10 empty-state">
      <UsersIcon :size="32" class="mx-auto text-gray-300" />
      <p class="mt-3 text-sm text-gray-500">No hay usuarios en este filtro.</p>
    </div>

    <template v-else>
      <p class="mt-4 text-xs text-gray-500">{{ contadorTexto }}</p>
      <ul class="mt-2 space-y-3">
        <li
          v-for="usuario in usuarios"
          :key="usuario.id"
          class="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
          :class="{ 'opacity-70': !usuario.activo }"
        >
          <div class="flex min-w-0 items-center gap-3.5">
            <span
              class="flex size-11 shrink-0 items-center justify-center rounded-full bg-ink-900 text-sm font-semibold text-brand-400"
              aria-hidden="true"
            >
              {{ iniciales(usuario.nombre) }}
            </span>
            <div class="min-w-0">
              <p class="flex flex-wrap items-center gap-x-2 gap-y-1.5 text-[15px] font-semibold text-ink">
                <span class="truncate">{{ usuario.nombre }}</span>
                <span v-if="usuario.id === auth.usuario?.id" class="text-xs font-normal text-gray-400">(tú)</span>
                <span class="badge" :class="ROLES[usuario.role].clase">
                  {{ ROLES[usuario.role].texto }}
                </span>
                <span
                  class="badge"
                  :class="usuario.activo ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-red-50 text-red-700 ring-red-600/20'"
                >
                  {{ usuario.activo ? 'Activo' : 'Inactivo' }}
                </span>
              </p>
              <p class="mt-1 truncate text-xs text-gray-500">
                {{ usuario.email }}<template v-if="usuario.telefono"> · {{ usuario.telefono }}</template>
              </p>
            </div>
          </div>

          <div v-if="usuario.id !== auth.usuario?.id" class="grid shrink-0 grid-cols-2 gap-2 sm:flex">
            <BaseButton variant="ghost" size="sm" :disabled="procesando === usuario.id" @click="revocarSesiones(usuario)">
              <KeyRound :size="14" />
              Revocar sesiones
            </BaseButton>
            <BaseButton
              size="sm"
              :variant="usuario.activo ? 'danger-soft' : 'primary'"
              :disabled="procesando === usuario.id"
              @click="alternarActivo(usuario)"
            >
              {{ usuario.activo ? 'Desactivar' : 'Activar' }}
            </BaseButton>
          </div>
        </li>
      </ul>
    </template>

    <!-- Crear usuario (Mesero/Administrador — el Cliente se autorregistra en el sitio público) -->
    <BaseModal v-model="modalAbierto" title="Nuevo usuario">
      <form class="space-y-4" @submit.prevent="confirmarCrear">
        <div>
          <p class="field-label">Rol</p>
          <div class="grid grid-cols-2 gap-2">
            <button
              type="button"
              class="rounded-xl border px-3 py-3 text-sm font-semibold transition active:scale-[0.98]"
              :class="form.role === 'mesero' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="form.role = 'mesero'"
            >
              Mesero
            </button>
            <button
              type="button"
              class="rounded-xl border px-3 py-3 text-sm font-semibold transition active:scale-[0.98]"
              :class="form.role === 'administrador' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              @click="form.role = 'administrador'"
            >
              Administrador
            </button>
          </div>
        </div>

        <label class="block">
          <span class="field-label">Nombre</span>
          <input v-model="form.nombre" type="text" class="field" />
          <span v-if="erroresForm.nombre" class="mt-1 block text-xs text-red-600">{{ erroresForm.nombre }}</span>
        </label>

        <label class="block">
          <span class="field-label">Correo</span>
          <input v-model="form.email" type="email" class="field" />
          <span v-if="erroresForm.email" class="mt-1 block text-xs text-red-600">{{ erroresForm.email }}</span>
        </label>

        <label class="block">
          <span class="field-label">Teléfono (opcional)</span>
          <input v-model="form.telefono" type="text" class="field" />
          <span v-if="erroresForm.telefono" class="mt-1 block text-xs text-red-600">{{ erroresForm.telefono }}</span>
        </label>

        <div class="grid gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="field-label">Contraseña</span>
            <input v-model="form.password" type="password" class="field" />
            <span v-if="erroresForm.password" class="mt-1 block text-xs text-red-600">{{ erroresForm.password }}</span>
          </label>
          <label class="block">
            <span class="field-label">Confirmar contraseña</span>
            <input v-model="form.password_confirmation" type="password" class="field" />
          </label>
        </div>

        <p v-if="errorForm" class="alert-error">{{ errorForm }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="modalAbierto = false">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="creando">
            {{ creando ? 'Creando…' : 'Crear usuario' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
