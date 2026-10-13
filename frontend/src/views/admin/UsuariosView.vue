<script setup lang="ts">
// M01 — Usuarios del Administrador: listar (staff y clientes), crear cuentas de
// Mesero/Administrador, activar/desactivar y revocar sesiones. Ver UsuarioController.
import { computed, onMounted, ref } from 'vue'
import { History, KeyRound, Pencil, Plus, Users as UsersIcon } from 'lucide-vue-next'
import SesionesLista from '@/components/common/SesionesLista.vue'
import BaseButton from '@/components/common/BaseButton.vue'
import BaseModal from '@/components/common/BaseModal.vue'
import PageHeader from '@/components/common/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { usuarioService } from '@/services/usuario.service'
import { erroresDeValidacion, mensajeDeError } from '@/services/errors'
import type { PoliticaSesiones, Rol, SesionAbierta, Usuario } from '@/types/models'

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

// ---- Sesiones de una cuenta (Fase 3, tarea 4: RF-M01-010/023/024, HU-M01-010) ----
// "Sesiones" abre la lista de dispositivos donde la cuenta está abierta: se cierra una en
// particular o todas a la vez (lo que antes hacía "Revocar sesiones").
const usuarioSesiones = ref<Usuario | null>(null)
const sesionesUsuario = ref<SesionAbierta[]>([])
const politicaSesiones = ref<PoliticaSesiones | null>(null)
const cargandoSesiones = ref(false)
const errorSesiones = ref('')
const cerrandoSesion = ref<number | null>(null)

const modalSesionesAbierto = computed({
  get: () => usuarioSesiones.value !== null,
  set: (abierto: boolean) => {
    if (!abierto) usuarioSesiones.value = null
  },
})

function actualizarConteo(usuario: Usuario, cantidad: number) {
  const i = usuarios.value.findIndex((u) => u.id === usuario.id)
  const actual = usuarios.value[i]
  if (actual) usuarios.value[i] = { ...actual, sesiones_activas: cantidad }
}

async function abrirSesiones(usuario: Usuario) {
  usuarioSesiones.value = usuario
  sesionesUsuario.value = []
  errorSesiones.value = ''
  cargandoSesiones.value = true
  try {
    const { data } = await usuarioService.sesiones(usuario.id)
    sesionesUsuario.value = data.sesiones
    politicaSesiones.value = data.politica
    actualizarConteo(usuario, data.sesiones.length)
  } catch (e) {
    errorSesiones.value = mensajeDeError(e, 'No pudimos cargar sus sesiones.')
  } finally {
    cargandoSesiones.value = false
  }
}

async function cerrarSesionDe(sesion: SesionAbierta) {
  const usuario = usuarioSesiones.value
  if (!usuario) return
  cerrandoSesion.value = sesion.id
  errorSesiones.value = ''
  try {
    await usuarioService.revocarSesion(usuario.id, sesion.id)
    sesionesUsuario.value = sesionesUsuario.value.filter((s) => s.id !== sesion.id)
    actualizarConteo(usuario, sesionesUsuario.value.length)
  } catch (e) {
    errorSesiones.value = mensajeDeError(e, 'No pudimos cerrar esa sesión.')
  } finally {
    cerrandoSesion.value = null
  }
}

async function revocarSesiones(usuario: Usuario) {
  procesando.value = usuario.id
  error.value = ''
  errorSesiones.value = ''
  mensajeRevocar.value = ''
  try {
    await usuarioService.revocarSesiones(usuario.id)
    mensajeRevocar.value = `Sesiones de ${usuario.nombre} revocadas.`
    sesionesUsuario.value = []
    actualizarConteo(usuario, 0)
    usuarioSesiones.value = null
  } catch (e) {
    errorSesiones.value = mensajeDeError(e, 'No pudimos revocar las sesiones.')
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
  username: '',
  telefono: '',
  password: '',
  password_confirmation: '',
})
const erroresForm = ref<Record<string, string>>({})
const errorForm = ref('')
const creando = ref(false)

function abrirCrear() {
  form.value = { role: 'mesero', nombre: '', email: '', username: '', telefono: '', password: '', password_confirmation: '' }
  erroresForm.value = {}
  errorForm.value = ''
  modalAbierto.value = true
}

async function confirmarCrear() {
  creando.value = true
  erroresForm.value = {}
  errorForm.value = ''
  try {
    await usuarioService.crear({
      ...form.value,
      username: form.value.username.trim() || undefined,
      telefono: form.value.telefono || undefined,
    })
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

// ---- Editar usuario y cambiar su rol (Fase 3, tarea 2: RF-M01-003/005, HU-M01-003/005) ----
// Datos permitidos: nombre, correo, nombre de usuario, teléfono y rol (CA-M01-003.1). La contraseña no se cambia
// aquí. Cambiar el rol cierra todas las sesiones de esa cuenta; nadie cambia su propio rol.
const usuarioEditando = ref<Usuario | null>(null)
const formEditar = ref({ nombre: '', email: '', username: '', telefono: '', role: 'mesero' as Rol })
const erroresEditar = ref<Record<string, string>>({})
const errorEditar = ref('')
const guardandoEdicion = ref(false)
const mensajeEdicion = ref('')

const modalEditarAbierto = computed({
  get: () => usuarioEditando.value !== null,
  set: (abierto: boolean) => {
    if (!abierto) usuarioEditando.value = null
  },
})
const editandoPropia = computed(() => usuarioEditando.value?.id === auth.usuario?.id)
const cambiaRol = computed(() => !!usuarioEditando.value && formEditar.value.role !== usuarioEditando.value.role)
const OPCIONES_ROL_EDITAR: Rol[] = ['administrador', 'mesero', 'cliente']

function abrirEditar(usuario: Usuario) {
  usuarioEditando.value = usuario
  formEditar.value = {
    nombre: usuario.nombre,
    email: usuario.email,
    username: usuario.username ?? '',
    telefono: usuario.telefono ?? '',
    role: usuario.role,
  }
  erroresEditar.value = {}
  errorEditar.value = ''
}

async function confirmarEditar() {
  const usuario = usuarioEditando.value
  if (!usuario) return
  guardandoEdicion.value = true
  erroresEditar.value = {}
  errorEditar.value = ''
  mensajeEdicion.value = ''
  mensajeRevocar.value = ''
  try {
    const { data } = await usuarioService.editar(usuario.id, {
      nombre: formEditar.value.nombre,
      email: formEditar.value.email,
      username: formEditar.value.username.trim(),
      telefono: formEditar.value.telefono.trim() || null,
      role: formEditar.value.role,
    })
    reemplazar(data.usuario)
    if (data.usuario.id === auth.usuario?.id) auth.usuario = data.usuario
    mensajeEdicion.value = data.sesiones_cerradas
      ? `${data.usuario.nombre} ahora es ${ROLES[data.usuario.role].texto}. Se cerraron sus sesiones: debe volver a entrar.`
      : `Datos de ${data.usuario.nombre} actualizados.`
    usuarioEditando.value = null
    // Si el filtro de rol ya no lo incluye, se recarga la lista.
    if (filtroRol.value !== 'todos' && data.usuario.role !== filtroRol.value) await cargar()
  } catch (e) {
    const campos = erroresDeValidacion(e)
    if (Object.keys(campos).length) {
      erroresEditar.value = campos
    } else {
      errorEditar.value = mensajeDeError(e, 'No pudimos guardar los cambios.')
    }
  } finally {
    guardandoEdicion.value = false
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
        placeholder="Buscar por nombre, correo o usuario…"
        class="field sm:w-64"
        @input="buscar"
      />
    </div>

    <p v-if="error" class="mt-4 alert-error">{{ error }}</p>
    <p v-if="mensajeRevocar" class="mt-4 alert-success">{{ mensajeRevocar }}</p>
    <p v-if="mensajeEdicion" class="mt-4 alert-success">{{ mensajeEdicion }}</p>

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
                {{ usuario.email }}<template v-if="usuario.username"> · @{{ usuario.username }}</template><template v-if="usuario.telefono"> · {{ usuario.telefono }}</template>
              </p>
            </div>
          </div>

          <div class="grid shrink-0 grid-cols-2 gap-2 sm:flex">
            <!-- Fase 3, tarea 1 (RF-M01-025): lo que hizo este usuario, en Auditoría. -->
            <router-link
              :to="{ name: 'admin-auditoria', query: { user_id: String(usuario.id) } }"
              class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50 hover:text-ink sm:min-h-9"
            >
              <History :size="14" />
              Actividad
            </router-link>
            <BaseButton
              variant="ghost"
              size="sm"
              :disabled="procesando === usuario.id"
              @click="abrirEditar(usuario)"
            >
              <Pencil :size="14" />
              Editar
            </BaseButton>
            <template v-if="usuario.id !== auth.usuario?.id">
            <BaseButton variant="ghost" size="sm" :disabled="procesando === usuario.id" @click="abrirSesiones(usuario)">
              <KeyRound :size="14" />
              Sesiones<template v-if="usuario.sesiones_activas !== undefined"> ({{ usuario.sesiones_activas }})</template>
            </BaseButton>
            <BaseButton
              size="sm"
              :variant="usuario.activo ? 'danger-soft' : 'primary'"
              :disabled="procesando === usuario.id"
              @click="alternarActivo(usuario)"
            >
              {{ usuario.activo ? 'Desactivar' : 'Activar' }}
            </BaseButton>
            </template>
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
          <span class="field-label">Nombre de usuario (opcional)</span>
          <input v-model="form.username" type="text" class="field" autocomplete="off" autocapitalize="none" placeholder="Ej. mario.m" />
          <span v-if="erroresForm.username" class="mt-1 block text-xs text-red-600">{{ erroresForm.username }}</span>
          <span v-else class="mt-1 block text-xs text-gray-500">Para entrar sin escribir el correo.</span>
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

    <!-- Sesiones abiertas de una cuenta (Fase 3, tarea 4) -->
    <BaseModal v-model="modalSesionesAbierto" :title="usuarioSesiones ? `Sesiones de ${usuarioSesiones.nombre}` : 'Sesiones'">
      <div v-if="usuarioSesiones" class="space-y-4">
        <p class="text-sm text-gray-600">
          Dispositivos donde esta cuenta está abierta. Cerrar una sesión obliga a volver a entrar en ese dispositivo.
        </p>
        <p v-if="errorSesiones" class="alert-error">{{ errorSesiones }}</p>
        <div v-if="cargandoSesiones" class="loading-state">Cargando sesiones…</div>
        <SesionesLista
          v-else
          :sesiones="sesionesUsuario"
          :politica="politicaSesiones"
          :procesando="cerrandoSesion"
          permitir-cerrar-actual
          @cerrar="cerrarSesionDe"
        />
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="usuarioSesiones = null">Cerrar ventana</BaseButton>
          <BaseButton
            variant="danger-soft"
            type="button"
            :disabled="procesando === usuarioSesiones.id || !sesionesUsuario.length"
            @click="revocarSesiones(usuarioSesiones)"
          >
            Cerrar todas sus sesiones
          </BaseButton>
        </div>
      </div>
    </BaseModal>

    <!-- Editar usuario y rol (Fase 3, tarea 2) -->
    <BaseModal v-model="modalEditarAbierto" title="Editar usuario">
      <form v-if="usuarioEditando" class="space-y-4" @submit.prevent="confirmarEditar">
        <label class="block">
          <span class="field-label">Nombre</span>
          <input v-model="formEditar.nombre" type="text" class="field" autocomplete="off" />
          <span v-if="erroresEditar.nombre" class="mt-1 block text-xs text-red-600">{{ erroresEditar.nombre }}</span>
        </label>

        <label class="block">
          <span class="field-label">Correo</span>
          <input v-model="formEditar.email" type="email" class="field" autocomplete="off" />
          <span v-if="erroresEditar.email" class="mt-1 block text-xs text-red-600">{{ erroresEditar.email }}</span>
        </label>

        <label class="block">
          <span class="field-label">Nombre de usuario (opcional)</span>
          <input v-model="formEditar.username" type="text" class="field" autocomplete="off" autocapitalize="none" placeholder="Vacío = entra solo con el correo" />
          <span v-if="erroresEditar.username" class="mt-1 block text-xs text-red-600">{{ erroresEditar.username }}</span>
        </label>

        <label class="block">
          <span class="field-label">Teléfono (opcional)</span>
          <input v-model="formEditar.telefono" type="tel" class="field" autocomplete="off" />
          <span v-if="erroresEditar.telefono" class="mt-1 block text-xs text-red-600">{{ erroresEditar.telefono }}</span>
        </label>

        <div>
          <p class="field-label">Rol</p>
          <div class="grid grid-cols-3 gap-2">
            <button
              v-for="rol in OPCIONES_ROL_EDITAR"
              :key="rol"
              type="button"
              class="rounded-xl border px-2 py-3 text-sm font-semibold transition active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
              :class="formEditar.role === rol ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-gray-200 text-gray-600 hover:border-brand-300'"
              :disabled="editandoPropia"
              :aria-pressed="formEditar.role === rol"
              @click="formEditar.role = rol"
            >
              {{ ROLES[rol].texto }}
            </button>
          </div>
          <span v-if="erroresEditar.role" class="mt-1 block text-xs text-red-600">{{ erroresEditar.role }}</span>
          <p v-if="editandoPropia" class="mt-2 text-xs text-gray-500">
            No puedes cambiar tu propio rol; otro Administrador puede hacerlo.
          </p>
          <p v-else-if="cambiaRol" class="mt-2 alert-info">
            Al guardar, {{ usuarioEditando.nombre }} pasa de {{ ROLES[usuarioEditando.role].texto }} a
            {{ ROLES[formEditar.role].texto }} y se cierran todas sus sesiones: tendrá que volver a entrar.
          </p>
        </div>

        <p class="text-xs text-gray-500">
          La contraseña no se cambia aquí: cada persona la cambia en su perfil o con "¿Olvidaste tu contraseña?".
        </p>

        <p v-if="errorEditar" class="alert-error">{{ errorEditar }}</p>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <BaseButton variant="ghost" type="button" @click="usuarioEditando = null">Cancelar</BaseButton>
          <BaseButton variant="primary" type="submit" :disabled="guardandoEdicion">
            {{ guardandoEdicion ? 'Guardando…' : cambiaRol ? 'Guardar y cambiar rol' : 'Guardar cambios' }}
          </BaseButton>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
