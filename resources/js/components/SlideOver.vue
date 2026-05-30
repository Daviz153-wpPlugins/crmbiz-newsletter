<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition-opacity duration-300"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-300"
      leave-to-class="opacity-0"
    >
      <div v-if="open" class="fixed inset-0 z-[9999] flex justify-end font-sans" style="top:32px">

        <!-- Backdrop -->
        <div class="absolute inset-0 bg-gray-900/40" @click="$emit('close')" />

        <!-- Panel -->
        <Transition
          enter-active-class="transition-transform duration-300 ease-out"
          enter-from-class="translate-x-full"
          leave-active-class="transition-transform duration-200 ease-in"
          leave-to-class="translate-x-full"
        >
          <div v-if="open"
               class="relative flex flex-col w-full max-w-2xl h-full bg-white shadow-2xl overflow-hidden">

            <!-- Header -->
            <div class="flex items-start gap-3 px-6 py-4 border-b border-gray-100 flex-shrink-0">
              <!-- Title -->
              <div class="min-w-0 flex-1">
                <slot name="header" />
              </div>
              <!-- Actions + Close -->
              <div class="flex items-center gap-2 flex-shrink-0 pt-0.5">
                <slot name="actions" />
                <button @click="$emit('close')"
                        class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                  <X class="w-4 h-4" />
                </button>
              </div>
            </div>

            <!-- Scrollable content -->
            <div class="flex-1 overflow-y-auto">
              <slot />
            </div>

          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue'
import { X } from 'lucide-vue-next'

const props = defineProps({ open: { type: Boolean, required: true } })
const emit  = defineEmits(['close'])

function onKeydown(e) {
  if (e.key === 'Escape' && props.open) emit('close')
}
onMounted(()  => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))
</script>
