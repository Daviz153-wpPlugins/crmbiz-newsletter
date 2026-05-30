<template>
  <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex items-start gap-4">
    <div class="rounded-xl p-3 flex-shrink-0" :class="iconBg">
      <component :is="icon" class="w-5 h-5" :class="iconColor" />
    </div>
    <div class="min-w-0">
      <p class="text-sm text-gray-500 mb-1">{{ label }}</p>
      <p class="text-2xl font-bold text-gray-900 leading-none">{{ value }}</p>
      <p v-if="sub" class="text-xs text-gray-400 mt-1">{{ sub }}</p>
    </div>
  </div>
</template>

<script setup>
defineProps({
  label:     { type: String, required: true },
  value:     { type: String, required: true },
  sub:       { type: String, default: '' },
  icon:      { type: [Object, Function], required: true },
  iconBg:    { type: String, default: 'bg-blue-50' },
  iconColor: { type: String, default: 'text-blue-500' },
})
</script>
