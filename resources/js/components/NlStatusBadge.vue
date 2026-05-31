<template>
  <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium" :class="e.cls">
    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="e.dot"></span>
    {{ e.label }}
  </span>
</template>

<script setup>
import { computed } from 'vue'

const { status } = defineProps({ status: { type: String, required: true } })

const MAP = {
  draft:     { label: '대기',     cls: 'bg-gray-100 text-gray-500',    dot: 'bg-gray-400' },
  queued:    { label: '발송 대기', cls: 'bg-blue-50 text-blue-700',     dot: 'bg-blue-500' },
  scheduled: { label: '예약됨',   cls: 'bg-purple-50 text-purple-700', dot: 'bg-purple-500' },
  sending:   { label: '발송 중',  cls: 'bg-yellow-50 text-yellow-700', dot: 'bg-yellow-500 animate-pulse' },
  sent:      { label: '완료',     cls: 'bg-green-50 text-green-700',   dot: 'bg-green-500' },
  failed:    { label: '실패',     cls: 'bg-red-50 text-red-700',       dot: 'bg-red-500' },
  cancelled: { label: '취소됨',   cls: 'bg-gray-100 text-gray-400',    dot: 'bg-gray-300' },
}

const e = computed(() => MAP[status] ?? { label: status, cls: 'bg-gray-100 text-gray-500', dot: 'bg-gray-400' })
</script>
