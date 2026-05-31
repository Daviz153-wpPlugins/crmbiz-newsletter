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
               class="relative flex flex-col h-full bg-white shadow-2xl overflow-hidden"
               :style="{ width: panelWidth + 'px' }">

            <!-- Resize handle -->
            <div
              class="absolute left-0 top-0 bottom-0 w-1.5 cursor-col-resize z-10 group"
              @mousedown.prevent="startResize">
              <div class="absolute inset-y-0 left-0 w-1 bg-transparent group-hover:bg-blue-400/50 transition-colors" />
            </div>

            <!-- Header -->
            <div class="flex items-start gap-3 px-6 py-4 border-b border-gray-100 flex-shrink-0">
              <div class="min-w-0 flex-1">
                <slot name="header" />
              </div>
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
import { ref, onMounted, onUnmounted } from 'vue'
import { X } from 'lucide-vue-next'

const props = defineProps({ open: { type: Boolean, required: true } })
const emit  = defineEmits(['close'])

const STORAGE_KEY = 'crmbiz_slideover_width'
const MIN_WIDTH   = 400
const MAX_WIDTH   = () => Math.round(window.innerWidth * 0.9)
const DEFAULT_WIDTH = 672

const panelWidth  = ref(parseInt(localStorage.getItem(STORAGE_KEY)) || DEFAULT_WIDTH)
let   dragging    = false

function startResize(e) {
  dragging = true
  document.body.style.cursor    = 'col-resize'
  document.body.style.userSelect = 'none'
  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup',   onMouseUp)
}

function onMouseMove(e) {
  if (!dragging) return
  const w = Math.min(Math.max(window.innerWidth - e.clientX, MIN_WIDTH), MAX_WIDTH())
  panelWidth.value = w
}

function onMouseUp() {
  dragging = false
  document.body.style.cursor     = ''
  document.body.style.userSelect = ''
  localStorage.setItem(STORAGE_KEY, panelWidth.value)
  document.removeEventListener('mousemove', onMouseMove)
  document.removeEventListener('mouseup',   onMouseUp)
}

function onKeydown(e) {
  if (e.key === 'Escape' && props.open) emit('close')
}
onMounted(()  => document.addEventListener('keydown', onKeydown))
onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
  document.removeEventListener('mousemove', onMouseMove)
  document.removeEventListener('mouseup',   onMouseUp)
})
</script>
