<template>
  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="cls">
    <span class="w-1.5 h-1.5 rounded-full mr-1.5" :class="dotCls"></span>
    {{ label }}
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({ status: { type: Boolean, required: true }, ok: String, fail: String })

const label = computed(() => props.status ? (props.ok ?? '정상') : (props.fail ?? '확인 필요'))
const cls   = computed(() => props.status
  ? 'bg-green-50 text-green-700'
  : 'bg-red-50 text-red-700')
const dotCls = computed(() => props.status ? 'bg-green-500' : 'bg-red-500')
</script>
