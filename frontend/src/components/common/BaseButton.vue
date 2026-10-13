<script setup lang="ts">
// Tamaño táctil: en teléfono todos los botones miden mínimo 44px de alto (recomendación de
// Apple/Google para dedos); a partir de sm vuelven a un tamaño más compacto. "sm" es para
// acciones secundarias dentro de tarjetas de lista, donde 44px por botón no cabe.
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'danger' | 'danger-soft' | 'ghost' | 'dark'
    size?: 'md' | 'sm'
    type?: 'button' | 'submit'
    disabled?: boolean
  }>(),
  { variant: 'primary', size: 'md', type: 'button', disabled: false },
)
</script>

<template>
  <button
    :type="type"
    :disabled="disabled"
    class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl font-semibold transition-all duration-150 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 disabled:hover:shadow-none disabled:active:scale-100"
    :class="[
      size === 'md' ? 'min-h-11 px-4 py-2.5 text-sm sm:min-h-10' : 'min-h-10 px-3 py-2 text-xs sm:min-h-9',
      {
        'bg-brand-500 text-ink shadow-gold hover:-translate-y-0.5 hover:bg-brand-400 hover:shadow-[0_12px_26px_rgba(214,164,58,0.4)]':
          variant === 'primary',
        'border border-gray-200 bg-white text-ink hover:border-brand-400 hover:bg-brand-50': variant === 'secondary',
        'bg-ink text-white hover:bg-ink-700': variant === 'dark',
        'bg-red-600 text-white shadow-[0_6px_16px_rgba(220,38,38,0.25)] hover:bg-red-700': variant === 'danger',
        'border border-red-200 bg-red-50 text-red-700 hover:border-red-300 hover:bg-red-100': variant === 'danger-soft',
        'border border-gray-200 bg-white text-gray-700 hover:border-gray-300 hover:bg-gray-50 hover:text-ink':
          variant === 'ghost',
      },
    ]"
  >
    <slot />
  </button>
</template>
