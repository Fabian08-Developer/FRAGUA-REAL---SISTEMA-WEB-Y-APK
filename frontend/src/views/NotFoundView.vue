<script setup lang="ts">
// Error 404. No cuelga de ningún layout (ver router/index.ts), así que arma
// su propia página completa — reutiliza la mascota de marca para que no sea
// un mensaje de error en blanco.
import mascota from '@/assets/img/mascota-fragua-real.png'
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-cream px-4 py-12">
    <div class="w-full max-w-md text-center">
      <img
        :src="mascota"
        alt="Mascota de Fragua Real Burger, algo confundida"
        class="mx-auto w-48 drop-shadow-[0_20px_30px_rgba(0,0,0,0.15)]"
      />
      <p class="mt-6 font-display text-6xl font-medium tracking-tight text-ink">404</p>
      <h1 class="mt-2 font-display text-2xl font-medium text-ink">Por aquí no hay antojo</h1>
      <p class="mt-2 text-sm text-[#6c655c]">
        La página que buscas no existe o se movió de lugar. Vuelve al menú y elige algo rico.
      </p>
      <router-link
        to="/"
        class="mt-8 inline-flex items-center justify-center gap-2 rounded-xl bg-brand-500 px-6 py-3 text-sm font-medium text-ink shadow-[0_8px_20px_rgba(214,164,58,0.35)] transition-all duration-150 hover:-translate-y-0.5 hover:bg-brand-400 hover:shadow-[0_12px_26px_rgba(214,164,58,0.4)]"
      >
        Volver al inicio
      </router-link>
    </div>
  </div>
</template>
