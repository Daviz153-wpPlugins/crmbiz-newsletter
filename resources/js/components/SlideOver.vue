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

        <!-- Panel wrapper (handles slide animation + resize) -->
        <Transition
          enter-active-class="transition-transform duration-300 ease-out"
          enter-from-class="translate-x-full"
          leave-active-class="transition-transform duration-200 ease-in"
          leave-to-class="translate-x-full"
        >
          <div v-if="open" class="relative flex h-full" :style="{ width: panelWidth + 'px' }">

            <!-- Resize handle — sits at the left edge, outside panel overflow -->
            <div
              class="absolute left-0 top-0 bottom-0 z-10 flex items-center justify-center"
              style="width:12px; cursor:col-resize; transform:translateX(-50%)"
              @mousedown.prevent.stop="startResize">
              <div :class="['h-full w-1 rounded-full transition-colors', isDragging ? 'bg-blue-500' : 'bg-gray-200 hover:bg-blue-400']" />
            </div>

            <!-- Panel content -->
            <div class="flex flex-col w-full h-full bg-white shadow-2xl overflow-hidden">

              <!-- Header -->
              <div class="flex items-start gap-3 px-6 py-4 border-b border-gray-100 flex-shrink-0">
                <div class="min-w-0 flex-1">
                  <slot name="header" />
                </div>
                <div class="flex items-center gap-2 flex-shrink-0 pt-0.5">
                  <slot name="actions" />
                  <button @click="$emit('close')"
                          aria-label="닫기"
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

const STORAGE_KEY   = 'crmbiz_slideover_width'
const MIN_WIDTH     = 400
const MAX_WIDTH     = () => Math.round(window.innerWidth * 0.92)
const DEFAULT_WIDTH = 672

const panelWidth  = ref(parseInt(localStorage.getItem(STORAGE_KEY) || '0') || DEFAULT_WIDTH)
const isDragging  = ref(false)

function startResize() {
  isDragging.value          = true
  document.body.style.cursor     = 'col-resize'
  document.body.style.userSelect = 'none'
  document.addEventListener('mousemove', onMouseMove)
  document.addEventListener('mouseup',   onMouseUp)
}

function onMouseMove(e) {
  if (!isDragging.value) return
  panelWidth.value = Math.min(Math.max(window.innerWidth - e.clientX, MIN_WIDTH), MAX_WIDTH())
}

function onMouseUp() {
  isDragging.value           = false
  document.body.style.cursor      = ''
  document.body.style.userSelect  = ''
  localStorage.setItem(STORAGE_KEY, String(panelWidth.value))
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
