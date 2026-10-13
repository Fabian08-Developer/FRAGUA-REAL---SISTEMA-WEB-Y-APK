<script setup lang="ts">
// Punto de entrada del layout: cada ruta decide su propio layout (Auth,
// Admin, Mesero, Cliente) vía router/index.ts + <component :is="layout">
// en el futuro; por ahora el <router-view> alcanza para el esqueleto.
import { watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

// CORREGIDO (29/09/2026): el guard de router/index.ts solo corre en cada navegación —
// si la sesión se invalida mientras el usuario YA está parado en una ruta protegida
// (token revocado por el Administrador, AuthController::revocarSesiones; o el
// interceptor 401 de services/api.ts limpiando la sesión tras cualquier llamada
// fallida), nada lo saca de ahí hasta que intente navegar a otro lado — se queda
// viendo una pantalla con datos de una sesión que ya no existe. Este watcher cubre
// ese hueco: si estaAutenticado pasa a false estando en una ruta que la exige, lo
// manda a login de inmediato.
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

watch(
  () => auth.estaAutenticado,
  (autenticado) => {
    if (!autenticado && route.meta.requiereAuth) {
      router.push({ name: 'login', query: { redirect: route.fullPath } })
    }
  },
)
</script>

<template>
  <router-view />
</template>
