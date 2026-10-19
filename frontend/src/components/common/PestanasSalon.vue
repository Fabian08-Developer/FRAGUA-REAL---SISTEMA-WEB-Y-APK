<script setup lang="ts">
// Corrección (16/10/2026): «Mesas y Reservas» del Administrador son dos pantallas (/admin/mesas y
// /admin/reservas), pero desde Mesas solo se llegaba a Reservas con el botón «Ver reservas» de cada
// mesa, que abre la lista ya filtrada. Estas pestañas, arriba de las dos pantallas, llevan a todas
// las reservas (sin filtro) o de vuelta a las mesas.
import { Armchair, CalendarClock } from 'lucide-vue-next'
import { useRoute } from 'vue-router'

const route = useRoute()

const PESTANAS = [
  { nombre: 'admin-mesas', texto: 'Mesas', icono: Armchair },
  { nombre: 'admin-reservas', texto: 'Reservas', icono: CalendarClock },
] as const
</script>

<template>
  <nav class="segmented mt-6 sm:w-fit" aria-label="Mesas y reservas">
    <router-link
      v-for="p in PESTANAS"
      :key="p.nombre"
      :to="{ name: p.nombre }"
      class="segmented-item flex items-center justify-center gap-1.5 text-sm"
      :class="route.name === p.nombre ? 'is-active' : ''"
      :aria-current="route.name === p.nombre ? 'page' : undefined"
    >
      <component :is="p.icono" :size="15" />
      {{ p.texto }}
    </router-link>
  </nav>
</template>
