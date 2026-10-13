<script setup lang="ts">
import AppSidebar from '@/components/common/AppSidebar.vue'
</script>

<template>
  <!-- h-dvh (no h-screen): en móvil "vh" incluye la barra de direcciones del navegador y el
       final de la página quedaba tapado. md+ : barra lateral/riel a la izquierda. -->
  <div class="flex h-dvh flex-col overflow-hidden bg-cream md:flex-row">
    <AppSidebar variant="admin" />
    <main class="min-h-0 min-w-0 flex-1 overflow-y-auto [scrollbar-gutter:stable]">
      <div class="mx-auto w-full max-w-7xl px-4 pb-10 pt-5 sm:px-6 sm:pt-7 lg:px-10 lg:pb-12 lg:pt-9">
        <router-view />
      </div>
    </main>
  </div>
</template>
