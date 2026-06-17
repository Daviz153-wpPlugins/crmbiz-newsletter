<template>
  <div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center items-center py-16">
      <div class="w-6 h-6 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else-if="detail">
      <div class="p-5 bg-gray-50/60">

        <!-- Stats grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">

          <!-- Campaign performance -->
          <div class="bg-white border border-gray-100 rounded-xl p-4">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">캠페인 성과</div>
            <div class="space-y-1.5 text-sm">
              <div v-for="r in perfRows" :key="r.label" class="flex justify-between">
                <span class="text-gray-500">{{ r.label }}</span>
                <span class="font-semibold" :style="{ color: r.color }">{{ r.value }}</span>
              </div>
            </div>
          </div>

          <!-- Email stats with bars -->
          <div class="bg-white border border-gray-100 rounded-xl p-4">
            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">이메일 통계</div>
            <div class="space-y-2.5">
              <div v-for="r in barRows" :key="r.label">
                <div class="flex justify-between text-xs mb-1">
                  <span class="text-gray-600 font-medium">{{ r.label }}</span>
                  <span class="text-gray-400">{{ r.count }} · {{ r.rate }}%</span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                  <div class="h-full rounded-full transition-all" :style="{ background: r.color, width: Math.min(r.rate, 100) + '%' }"></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 실패 원인 배너 -->
        <div v-if="detail.newsletter.status === 'failed' && detail.newsletter.fail_reason"
             class="flex items-start gap-3 bg-red-50 border border-red-100 rounded-xl px-4 py-3 mb-4 text-sm text-red-700">
          <span class="text-base flex-shrink-0">⚠️</span>
          <span>{{ detail.newsletter.fail_reason }}</span>
        </div>

        <!-- Tab card -->
        <div class="bg-white border border-gray-100 rounded-xl overflow-hidden">

          <!-- Tab header -->
          <div class="flex border-b border-gray-100">
            <button v-for="t in tabs" :key="t.key"
                    @click="tab = t.key"
                    class="px-5 py-3 text-sm font-medium border-b-2 transition-colors"
                    :class="tab === t.key
                      ? 'border-gray-900 text-gray-900'
                      : 'border-transparent text-gray-400 hover:text-gray-600'">
              {{ t.label }}
              <span v-if="t.count != null"
                    class="ml-1.5 px-1.5 py-0.5 rounded-full text-xs"
                    :class="tab === t.key ? 'bg-gray-100 text-gray-700' : 'bg-gray-50 text-gray-400'">
                {{ t.count }}
              </span>
            </button>
          </div>

          <!-- Details tab -->
          <div v-if="tab === 'details'" class="p-5">
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-5">
              <div>
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">주제</div>
                <div class="text-sm text-gray-900 font-medium leading-snug">{{ detail.newsletter.post_title }}</div>
              </div>
              <div>
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">총 수신자</div>
                <div class="text-sm text-gray-900 font-medium">{{ fmt(detail.stats.total) }}</div>
              </div>
              <div>
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">발송 일시</div>
                <div class="text-sm text-gray-900 font-medium">{{ detailDate(detail.newsletter) }}</div>
              </div>
              <div>
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">발송자</div>
                <div class="text-sm text-gray-900 font-medium">
                  {{ detail.from_name }}
                  <span class="text-gray-400 font-normal">({{ detail.from_email }})</span>
                </div>
              </div>
              <div>
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">발송 방식</div>
                <div class="text-sm text-gray-900 font-medium">{{ sendModeLabel(detail.newsletter.send_mode) }}</div>
              </div>
              <div>
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">이메일 미리보기</div>
                <a :href="detail.newsletter.preview_url ?? detail.preview_url" target="_blank"
                   class="text-sm text-blue-600 hover:underline">열기 ↗</a>
              </div>
            </div>
          </div>

          <!-- Recipients tab -->
          <div v-if="tab === 'recipients'" class="p-4">

            <!-- Filter buttons -->
            <div class="flex gap-1.5 mb-3 flex-wrap">
              <button v-for="f in filterBtns" :key="f.key"
                      @click="filter = f.key"
                      class="px-3 py-1 rounded-lg text-xs font-medium border transition-colors"
                      :class="filter === f.key
                        ? 'bg-gray-900 text-white border-gray-900'
                        : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50'">
                {{ f.label }} ({{ f.count }})
              </button>
            </div>

            <!-- Recipient table -->
            <div v-if="!detail.recipients.length" class="text-center text-gray-400 text-sm py-6">
              수신자 데이터 없음
            </div>
            <table v-else class="w-full text-sm">
              <thead>
                <tr class="border-b border-gray-100">
                  <th class="text-left text-xs font-semibold text-gray-400 pb-2 pr-3">이메일</th>
                  <th class="text-center text-xs font-semibold text-gray-400 pb-2 w-14">열람</th>
                  <th class="text-center text-xs font-semibold text-gray-400 pb-2 w-14">클릭</th>
                  <th class="text-right text-xs font-semibold text-gray-400 pb-2 w-36">마지막 활동</th>
                  <th class="text-right text-xs font-semibold text-gray-400 pb-2 w-10">액션</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in pagedRecipients" :key="r.email"
                    class="border-b border-gray-50 hover:bg-gray-50/50 transition-colors"
                    :data-status="recipientStatus(r)">
                  <td class="py-2 pr-3">
                    <div class="flex items-center gap-2">
                      <div class="w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-500 flex-shrink-0">
                        {{ initial(r) }}
                      </div>
                      <div class="min-w-0">
                        <div v-if="r.name" class="font-medium text-gray-800 truncate text-xs">{{ r.name }}</div>
                        <div class="text-gray-400 truncate text-xs" :class="r.name ? '' : 'text-gray-700 font-medium'">{{ r.email }}</div>
                      </div>
                      <span v-if="r.failed"       class="ml-1 px-1.5 py-0.5 rounded text-xs bg-red-50 text-red-600 font-medium flex-shrink-0">실패</span>
                      <span v-if="r.unsubscribed" class="ml-1 px-1.5 py-0.5 rounded text-xs bg-orange-50 text-orange-600 font-medium flex-shrink-0">수신 취소</span>
                    </div>
                  </td>
                  <td class="py-2 text-center text-base">
                    <span v-if="r.opened || r.clicked" class="text-green-500 font-bold">✓</span>
                    <span v-else class="text-gray-200">—</span>
                  </td>
                  <td class="py-2 text-center text-base">
                    <span v-if="r.clicked" class="text-blue-500 font-bold">✓</span>
                    <span v-else class="text-gray-200">—</span>
                  </td>
                  <td class="py-2 text-right text-xs text-gray-400 whitespace-nowrap">
                    {{ localDate(r.click_at ?? r.open_at ?? r.sent_at) }}
                  </td>
                  <td class="py-2 text-right">
                    <button @click="resendSingle(r.email)"
                            :disabled="resendingEmail === r.email"
                            title="재발송"
                            class="inline-flex items-center justify-center w-6 h-6 rounded text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors text-sm disabled:opacity-40">
                      ↺
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>

            <!-- Recipient pagination -->
            <div v-if="filteredRecipients.length > recipientPerPage"
                 class="flex items-center justify-between mt-3 pt-3 border-t border-gray-100 text-xs text-gray-500">
              <span>총 {{ filteredRecipients.length }}명</span>
              <div class="flex items-center gap-1">
                <button @click="recipientPage--" :disabled="recipientPage <= 1"
                        class="px-2 py-1 rounded border border-gray-200 hover:bg-gray-50 disabled:opacity-30 transition-colors">◀</button>
                <span class="px-2">{{ recipientPage }} / {{ recipientTotalPages }}</span>
                <button @click="recipientPage++" :disabled="recipientPage >= recipientTotalPages"
                        class="px-2 py-1 rounded border border-gray-200 hover:bg-gray-50 disabled:opacity-30 transition-colors">▶</button>
              </div>
            </div>

          </div>
        </div>

      </div>
    </template>

    <!-- Toast -->
    <Transition enter-active-class="transition-all duration-200" enter-from-class="translate-y-1 opacity-0" leave-active-class="transition-all duration-200" leave-to-class="translate-y-1 opacity-0">
      <div v-if="toast" class="fixed bottom-6 right-6 z-50 px-4 py-2.5 rounded-xl shadow-lg text-sm font-medium"
           :class="toast.type === 'error' ? 'bg-red-500 text-white' : 'bg-gray-900 text-white'">
        {{ toast.message }}
      </div>
    </Transition>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const props = defineProps({ id: { type: Number, required: true } })

const detail          = ref(null)
const loading         = ref(true)
const tab             = ref('details')
const filter          = ref('all')
const recipientPage   = ref(1)
const recipientPerPage = 50
const resendingEmail  = ref(null)
const toast           = ref(null)
let toastTimer        = null

// ── Computed ──────────────────────────────────────────────────────────────────

const tabs = computed(() => [
  { key: 'details',    label: '캠페인 세부 정보', count: null },
  { key: 'recipients', label: '수신자',          count: detail.value?.stats?.sent ?? 0 },
])

const s = computed(() => detail.value?.stats ?? {})

const perfRows = computed(() => [
  { label: '발송된 이메일', value: String(s.value.sent ?? 0),                                    color: '#111827' },
  { label: '오픈률',       value: `${s.value.opens ?? 0} (${s.value.open_rate ?? 0}%)`,           color: '#15803d' },
  { label: '클릭률',       value: `${s.value.clicks ?? 0} (${s.value.click_rate ?? 0}%)`,         color: '#1d4ed8' },
  { label: '클릭/오픈률',  value: `${s.value.ctr ?? 0}%`,                                         color: '#7c3aed' },
  { label: '구독 취소',    value: `${s.value.unsubs ?? 0} (${s.value.unsub_rate ?? 0}%)`,         color: '#f97316' },
])

const barRows = computed(() => {
  const total = s.value.total || 1
  return [
    { label: '전송됨',    count: s.value.sent   ?? 0, rate: total > 0 ? Math.round((s.value.sent   ?? 0) / total * 1000) / 10 : 0, color: '#3b82f6' },
    { label: '열림',      count: s.value.opens  ?? 0, rate: s.value.open_rate  ?? 0, color: '#10b981' },
    { label: '클릭됨',    count: s.value.clicks ?? 0, rate: s.value.click_rate ?? 0, color: '#6366f1' },
    { label: '구독 취소', count: s.value.unsubs  ?? 0, rate: s.value.unsub_rate ?? 0, color: '#f97316' },
    { label: '실패',      count: s.value.fails  ?? 0, rate: s.value.fail_rate  ?? 0, color: '#ef4444' },
  ]
})

function recipientStatus(r) {
  if (r.unsubscribed) return 'unsubscribed'
  if (r.clicked)      return 'clicked'
  if (r.opened)       return 'opened'
  if (r.failed)       return 'failed'
  return 'unopened'
}

const filterBtns = computed(() => {
  const rs = detail.value?.recipients ?? []
  const all   = rs.length
  const click = rs.filter(r => r.clicked).length
  const open  = rs.filter(r => r.opened).length
  const unop  = rs.filter(r => !r.opened && !r.clicked && !r.unsubscribed && !r.failed).length
  const unsub = rs.filter(r => r.unsubscribed).length
  return [
    { key: 'all',         label: '모두',     count: all },
    { key: 'clicked',     label: '클릭',     count: click },
    { key: 'opened',      label: '보기',     count: open },
    { key: 'unopened',    label: '미열람',   count: unop },
    { key: 'unsubscribed',label: '구독 취소', count: unsub },
  ]
})

const filteredRecipients = computed(() => {
  const rs = detail.value?.recipients ?? []
  if (filter.value === 'all')    return rs
  if (filter.value === 'opened') return rs.filter(r => r.opened)
  return rs.filter(r => recipientStatus(r) === filter.value)
})

const recipientTotalPages = computed(() => Math.max(1, Math.ceil(filteredRecipients.value.length / recipientPerPage)))

const pagedRecipients = computed(() => {
  const start = (recipientPage.value - 1) * recipientPerPage
  return filteredRecipients.value.slice(start, start + recipientPerPage)
})

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmt(n) { return Number(n).toLocaleString('ko-KR') }

function localDate(dt) {
  if (!dt) return '—'
  try {
    return new Date(dt).toLocaleDateString('ko-KR', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
  } catch { return dt }
}

function detailDate(nl) {
  if (nl.status === 'scheduled' && nl.scheduled_at) return localDate(nl.scheduled_at)
  if (nl.status === 'queued') return '대기 중'
  return localDate(nl.sent_at)
}

function sendModeLabel(m) { return { immediate: '즉시', manual: '수동', scheduled: '예약' }[m] ?? m }

function initial(r) {
  const src = r.name || r.email
  return src ? src.charAt(0).toUpperCase() : '?'
}

function showToast(message, type = 'success') {
  clearTimeout(toastTimer)
  toast.value = { message, type }
  toastTimer = setTimeout(() => { toast.value = null }, 3000)
}

async function api(method, path, body) {
  const res = await fetch(window.CrmbizNL.restUrl + path, {
    method,
    headers: {
      'X-WP-Nonce': window.CrmbizNL.nonce,
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  })
  if (!res.ok) {
    const err = await res.json().catch(() => ({}))
    throw new Error(err.message ?? `오류 (${res.status})`)
  }
  return res.json()
}

// ── Data ──────────────────────────────────────────────────────────────────────

async function fetchDetail() {
  loading.value = true
  try {
    detail.value = await api('GET', `newsletters/${props.id}`)
  } catch (e) {
    showToast(e.message, 'error')
  }
  loading.value = false
}

async function resendSingle(email) {
  resendingEmail.value = email
  try {
    await api('POST', `newsletters/${props.id}/resend-single`, { email })
    showToast(`${email} 재발송 완료.`)
  } catch (e) {
    showToast(e.message, 'error')
  }
  resendingEmail.value = null
}

onMounted(fetchDetail)
</script>
