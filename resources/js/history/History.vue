<template>
  <div class="min-h-screen bg-gray-50 p-6 font-sans">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">뉴스레터 이력</h1>
        <p v-if="total > 0" class="text-sm text-gray-400 mt-0.5">총 {{ fmt(total) }}개</p>
      </div>
    </div>

    <!-- Search -->
    <div class="flex gap-2 mb-5">
      <div class="flex items-center gap-2 flex-1 max-w-sm border border-gray-200 rounded-xl bg-white px-3 focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-transparent">
        <Search class="w-4 h-4 text-gray-400 flex-shrink-0" />
        <input
          v-model="searchInput"
          type="text"
          placeholder="제목으로 검색..."
          class="flex-1 py-2 text-sm bg-transparent focus:outline-none"
          style="box-shadow:none;border:none;padding-left:0;padding-right:0;margin:0"
          @input="debouncedSearch"
        >
      </div>
      <button v-if="search" @click="clearSearch"
              class="px-4 py-2 text-sm text-gray-500 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 transition-colors">
        초기화 ✕
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center h-64">
      <div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <!-- Empty -->
    <div v-else-if="!items.length" class="flex flex-col items-center justify-center h-48 gap-3">
      <div class="w-16 h-16 rounded-2xl bg-gray-100 flex items-center justify-center">
        <Mail class="w-7 h-7 text-gray-300" />
      </div>
      <div class="text-center">
        <p class="text-sm font-medium text-gray-500">
          {{ search ? `"${search}"에 해당하는 이력이 없습니다.` : '아직 발송된 뉴스레터가 없습니다.' }}
        </p>
        <p v-if="!search" class="text-xs text-gray-400 mt-1">포스트를 발행하면 여기에 기록됩니다.</p>
      </div>
    </div>

    <!-- Table -->
    <div v-else class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-5">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-gray-100 bg-gray-50/60">
            <th class="text-left px-5 py-3 w-[40%]">
              <button @click="toggleSort('title')" class="flex items-center gap-1 text-xs font-semibold text-gray-400 hover:text-gray-600 transition-colors">
                제목 <component :is="sortBy==='title' ? (sortDir==='asc' ? ChevronUp : ChevronDown) : ChevronsUpDown" class="w-3 h-3" />
              </button>
            </th>
            <th class="text-center px-3 py-3 w-28">
              <button @click="toggleSort('status')" class="inline-flex items-center gap-1 text-xs font-semibold text-gray-400 hover:text-gray-600 transition-colors whitespace-nowrap">
                상태 <component :is="sortBy==='status' ? (sortDir==='asc' ? ChevronUp : ChevronDown) : ChevronsUpDown" class="w-3 h-3" />
              </button>
            </th>
            <th class="text-left px-3 py-3 w-44">
              <button @click="toggleSort('date')" class="flex items-center gap-1 text-xs font-semibold text-gray-400 hover:text-gray-600 transition-colors whitespace-nowrap">
                발송 일시 <component :is="sortBy==='date' ? (sortDir==='asc' ? ChevronUp : ChevronDown) : ChevronsUpDown" class="w-3 h-3" />
              </button>
            </th>
            <th class="text-right px-3 py-3 w-16">
              <button @click="toggleSort('recipients')" class="inline-flex items-center justify-end gap-1 w-full text-xs font-semibold text-gray-400 hover:text-gray-600 transition-colors whitespace-nowrap">
                수신자 <component :is="sortBy==='recipients' ? (sortDir==='asc' ? ChevronUp : ChevronDown) : ChevronsUpDown" class="w-3 h-3" />
              </button>
            </th>
            <th class="text-right px-3 py-3 w-16">
              <button @click="toggleSort('open_rate')" class="inline-flex items-center justify-end gap-1 w-full text-xs font-semibold text-gray-400 hover:text-gray-600 transition-colors whitespace-nowrap">
                오픈률 <component :is="sortBy==='open_rate' ? (sortDir==='asc' ? ChevronUp : ChevronDown) : ChevronsUpDown" class="w-3 h-3" />
              </button>
            </th>
            <th class="text-right px-3 py-3 w-16">
              <button @click="toggleSort('click_rate')" class="inline-flex items-center justify-end gap-1 w-full text-xs font-semibold text-gray-400 hover:text-gray-600 transition-colors whitespace-nowrap">
                클릭률 <component :is="sortBy==='click_rate' ? (sortDir==='asc' ? ChevronUp : ChevronDown) : ChevronsUpDown" class="w-3 h-3" />
              </button>
            </th>
            <th class="px-4 py-3 w-28"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in sortedItems" :key="item.id"
              @click="openDetail(item.id)"
              class="border-b border-gray-50 cursor-pointer transition-colors group"
              :class="[
                selectedId === item.id
                  ? 'bg-blue-50/60'
                  : 'hover:bg-gray-50/70',
                item.status === 'sending' ? 'border-l-2 border-l-blue-400' : '',
              ]">

            <!-- Title -->
            <td class="px-5 py-3.5">
              <div class="flex items-center gap-2.5">
                <div class="flex flex-col min-w-0">
                  <span class="font-medium text-gray-900 truncate group-hover:text-blue-600 transition-colors"
                        :class="{ 'text-blue-700': selectedId === item.id }">
                    {{ item.post_title }}
                  </span>
                  <span v-if="item.status === 'sending' && item._progress"
                        class="text-xs text-blue-500 mt-0.5 font-medium">
                    {{ fmt(item._progress.done) }} / {{ fmt(item._progress.total) }} 발송 중...
                  </span>
                </div>
              </div>
            </td>

            <!-- Status -->
            <td class="px-3 py-3.5 text-center whitespace-nowrap">
              <div class="flex flex-col items-center gap-1.5">
                <NlStatusBadge :status="item.status" />
                <div v-if="item.status === 'sending' && item._progress"
                     class="w-20 h-1 bg-blue-100 rounded-full overflow-hidden">
                  <div class="h-full bg-blue-500 rounded-full transition-all duration-500"
                       :style="{ width: item._progress.percent + '%' }"></div>
                </div>
              </div>
            </td>

            <!-- Date -->
            <td class="px-3 py-3.5 text-xs text-gray-400 whitespace-nowrap">
              {{ formatDate(item) }}
            </td>

            <!-- Recipients -->
            <td class="px-3 py-3.5 text-right font-medium text-gray-700">
              {{ fmt(item.recipient_count) }}
            </td>

            <!-- Open rate -->
            <td class="px-3 py-3.5 text-right">
              <span v-if="item.success_count > 0" class="font-semibold text-green-600">
                {{ item.open_rate }}%
              </span>
              <span v-else class="text-gray-200">—</span>
            </td>

            <!-- Click rate -->
            <td class="px-3 py-3.5 text-right">
              <span v-if="item.success_count > 0" class="font-semibold text-blue-600">
                {{ item.click_rate }}%
              </span>
              <span v-else class="text-gray-200">—</span>
            </td>

            <!-- Actions -->
            <td class="px-4 py-3.5" @click.stop>
              <div class="flex items-center justify-end gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
                   :class="{ 'opacity-100': confirmDeleteId === item.id }">

                <!-- Preview -->
                <a v-if="item.preview_url" :href="item.preview_url" target="_blank"
                   title="미리보기"
                   class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                  <Eye class="w-3.5 h-3.5" />
                </a>

                <!-- Send (draft → queued) -->
                <button v-if="item.status === 'draft'"
                        @click="doAction('send', item)"
                        :disabled="isLoading(item.id, 'send')"
                        title="발송 시작"
                        class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-blue-600 hover:bg-blue-50 transition-colors disabled:opacity-40">
                  <Send class="w-3.5 h-3.5" />
                </button>

                <!-- Force send -->
                <button v-if="['queued','sending'].includes(item.status)"
                        @click="doAction('force-send', item)"
                        :disabled="isLoading(item.id, 'force-send')"
                        title="즉시 발송"
                        class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-green-600 hover:bg-green-50 transition-colors disabled:opacity-40">
                  <PlayCircle class="w-3.5 h-3.5" />
                </button>

                <!-- Cancel -->
                <button v-if="['queued','sending','scheduled'].includes(item.status)"
                        @click="doAction('cancel', item)"
                        :disabled="isLoading(item.id, 'cancel')"
                        title="발송 취소"
                        class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-orange-500 hover:bg-orange-50 transition-colors disabled:opacity-40">
                  <XCircle class="w-3.5 h-3.5" />
                </button>

                <!-- Resend -->
                <button v-if="['sent','failed'].includes(item.status)"
                        @click="doAction('resend', item)"
                        :disabled="isLoading(item.id, 'resend')"
                        title="재발송"
                        class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-gray-500 hover:bg-gray-100 transition-colors disabled:opacity-40">
                  <RotateCw class="w-3.5 h-3.5" />
                </button>

                <!-- Delete -->
                <template v-if="confirmDeleteId === item.id">
                  <span class="text-xs text-red-600 mx-1">삭제?</span>
                  <button @click="execDelete(item.id)"
                          class="px-2 py-1 rounded text-xs font-medium bg-red-500 text-white hover:bg-red-600 transition-colors">
                    예
                  </button>
                  <button @click="confirmDeleteId = null"
                          class="px-2 py-1 rounded text-xs text-gray-500 hover:bg-gray-100 transition-colors">
                    아니오
                  </button>
                </template>
                <button v-else
                        @click="confirmDeleteId = item.id"
                        :disabled="item.status === 'sending' || isLoading(item.id, 'delete')"
                        :title="item.status === 'sending' ? '발송 중 — 취소 후 삭제 가능' : '삭제'"
                        class="inline-flex items-center justify-center w-7 h-7 rounded-lg text-red-400 hover:bg-red-50 hover:text-red-600 transition-colors disabled:opacity-30">
                  <Trash2 class="w-3.5 h-3.5" />
                </button>

              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="!loading && (pages > 1 || total > 20)"
         class="flex items-center justify-between text-sm text-gray-500 flex-wrap gap-3">
      <div class="flex items-center gap-2">
        <span class="text-xs text-gray-400">페이지당</span>
        <select v-model="perPage" @change="page = 1; fetchList()"
                class="border border-gray-200 rounded-lg px-2 py-1 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option :value="20">20개</option>
          <option :value="50">50개</option>
          <option :value="100">100개</option>
        </select>
        <span class="text-xs text-gray-400">· 총 {{ fmt(total) }}개</span>
      </div>
      <div class="flex items-center gap-1">
        <button @click="changePage(page - 1)" :disabled="page <= 1"
                class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-600 disabled:opacity-30 hover:bg-gray-50 transition-colors">
          <ChevronLeft class="w-4 h-4" />
        </button>
        <template v-for="p in pageNums" :key="p">
          <span v-if="p === '...'" class="w-8 h-8 flex items-center justify-center text-gray-300 text-xs">…</span>
          <button v-else @click="changePage(p)"
                  class="w-8 h-8 flex items-center justify-center rounded-lg border text-xs font-medium transition-colors"
                  :class="p === page ? 'bg-gray-900 text-white border-gray-900' : 'border-gray-200 text-gray-600 hover:bg-gray-50'">
            {{ p }}
          </button>
        </template>
        <button @click="changePage(page + 1)" :disabled="page >= pages"
                class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-600 disabled:opacity-30 hover:bg-gray-50 transition-colors">
          <ChevronRight class="w-4 h-4" />
        </button>
      </div>
    </div>

    <!-- ── Slide-over ──────────────────────────────────────────────────────── -->
    <SlideOver :open="!!selectedId" @close="selectedId = null">
      <template #header>
        <div v-if="selectedItem">
          <div class="flex items-center gap-2 mb-1.5">
            <NlStatusBadge :status="selectedItem.status" />
            <span v-if="selectedItem.status === 'sending' && selectedItem._progress"
                  class="text-xs text-blue-500 font-medium">
              {{ selectedItem._progress.percent }}%
            </span>
          </div>
          <h2 class="text-base font-semibold text-gray-900 leading-snug">
            {{ selectedItem.post_title }}
          </h2>
          <p class="text-xs text-gray-400 mt-0.5">{{ formatDate(selectedItem) }}</p>
        </div>
      </template>

      <template #actions>
        <div v-if="selectedItem" class="flex items-center gap-1.5">

          <!-- Preview -->
          <a v-if="selectedItem.preview_url"
             :href="selectedItem.preview_url" target="_blank"
             class="so-btn">
            <Eye class="w-3.5 h-3.5 flex-shrink-0" />
            <span>미리보기</span>
          </a>

          <!-- Send (draft → queued) -->
          <button v-if="selectedItem.status === 'draft'"
                  @click="doAction('send', selectedItem)"
                  :disabled="isLoading(selectedItem.id, 'send')"
                  class="so-btn so-btn--green">
            <Send class="w-3.5 h-3.5 flex-shrink-0" />
            <span>발송 시작</span>
          </button>

          <!-- Force send -->
          <button v-if="['queued','sending'].includes(selectedItem.status)"
                  @click="doAction('force-send', selectedItem)"
                  :disabled="isLoading(selectedItem.id, 'force-send')"
                  class="so-btn so-btn--green">
            <PlayCircle class="w-3.5 h-3.5 flex-shrink-0" />
            <span>즉시 발송</span>
          </button>

          <!-- Cancel -->
          <button v-if="['queued','sending','scheduled'].includes(selectedItem.status)"
                  @click="doAction('cancel', selectedItem)"
                  :disabled="isLoading(selectedItem.id, 'cancel')"
                  class="so-btn so-btn--orange">
            <XCircle class="w-3.5 h-3.5 flex-shrink-0" />
            <span>취소</span>
          </button>

          <!-- Resend -->
          <button v-if="['sent','failed'].includes(selectedItem.status)"
                  @click="doAction('resend', selectedItem)"
                  :disabled="isLoading(selectedItem.id, 'resend')"
                  class="so-btn">
            <RotateCw class="w-3.5 h-3.5 flex-shrink-0" />
            <span>재발송</span>
          </button>

          <!-- Delete (with inline confirm) -->
          <template v-if="confirmDeleteId === selectedItem.id">
            <span class="text-xs text-red-600 font-medium">삭제할까요?</span>
            <button @click="execDelete(selectedItem.id)"
                    class="so-btn so-btn--red">예</button>
            <button @click="confirmDeleteId = null"
                    class="so-btn">아니오</button>
          </template>
          <button v-else
                  @click="confirmDeleteId = selectedItem.id"
                  :disabled="selectedItem.status === 'sending'"
                  :title="selectedItem.status === 'sending' ? '발송 중 — 취소 후 삭제' : '삭제'"
                  class="so-btn so-btn--red-outline">
            <Trash2 class="w-3.5 h-3.5 flex-shrink-0" />
          </button>

        </div>
      </template>

      <NewsletterDetail v-if="selectedId" :id="selectedId" />
    </SlideOver>

    <!-- Toast -->
    <Transition enter-active-class="transition-all duration-300" enter-from-class="translate-y-2 opacity-0"
                leave-active-class="transition-all duration-300" leave-to-class="translate-y-2 opacity-0">
      <div v-if="toast"
           class="fixed bottom-6 right-6 z-[9998] px-4 py-3 rounded-xl shadow-lg text-sm font-medium flex items-center gap-2"
           :class="toast.type === 'error' ? 'bg-red-500 text-white' : 'bg-gray-900 text-white'">
        <CheckCircle v-if="toast.type !== 'error'" class="w-4 h-4 text-green-400 flex-shrink-0" />
        <AlertCircle v-else class="w-4 h-4 flex-shrink-0" />
        {{ toast.message }}
      </div>
    </Transition>

  </div>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import {
  Search, Mail, ChevronRight, ChevronLeft,
  Eye, Send, PlayCircle, XCircle, RotateCw, Trash2, CheckCircle, AlertCircle,
  ChevronUp, ChevronDown, ChevronsUpDown,
} from 'lucide-vue-next'
import NlStatusBadge    from '@/components/NlStatusBadge.vue'
import SlideOver        from '@/components/SlideOver.vue'
import NewsletterDetail from './NewsletterDetail.vue'

// ── State ─────────────────────────────────────────────────────────────────────

const items           = ref([])
const total           = ref(0)
const pages           = ref(1)
const page            = ref(1)
const searchInput     = ref('')
const search          = ref('')
const perPage         = ref(20)
const loading         = ref(true)
const selectedId      = ref(null)
const confirmDeleteId = ref(null)
const loadingActions  = ref(new Set())
const toast           = ref(null)
const sortBy          = ref('date')
const sortDir         = ref('desc')
let toastTimer  = null
let pollTimer   = null
let searchTimer = null

// ── Computed ──────────────────────────────────────────────────────────────────

const selectedItem = computed(() => items.value.find(i => i.id === selectedId.value) ?? null)
const sendingIds   = computed(() => items.value.filter(i => i.status === 'sending').map(i => i.id))

const STATUS_ORDER = { sending: 0, queued: 1, scheduled: 2, draft: 3, sent: 4, failed: 5, cancelled: 6 }

const sortedItems = computed(() => {
  const arr = [...items.value]
  arr.sort((a, b) => {
    let va, vb
    switch (sortBy.value) {
      case 'title':
        va = (a.post_title ?? '').toLowerCase()
        vb = (b.post_title ?? '').toLowerCase()
        return sortDir.value === 'asc' ? va.localeCompare(vb, 'ko') : vb.localeCompare(va, 'ko')
      case 'status':
        va = STATUS_ORDER[a.status] ?? 9
        vb = STATUS_ORDER[b.status] ?? 9
        break
      case 'date':
        va = new Date(a.sent_at ?? a.scheduled_at ?? a.created_at ?? 0).getTime()
        vb = new Date(b.sent_at ?? b.scheduled_at ?? b.created_at ?? 0).getTime()
        break
      case 'recipients':
        va = a.recipient_count ?? 0
        vb = b.recipient_count ?? 0
        break
      case 'open_rate':
        va = parseFloat(a.open_rate) || 0
        vb = parseFloat(b.open_rate) || 0
        break
      case 'click_rate':
        va = parseFloat(a.click_rate) || 0
        vb = parseFloat(b.click_rate) || 0
        break
      default: return 0
    }
    return sortDir.value === 'asc' ? va - vb : vb - va
  })
  return arr
})

const pageNums = computed(() => {
  const range = 2
  const all = new Set([1, pages.value])
  for (let i = Math.max(2, page.value - range); i <= Math.min(pages.value - 1, page.value + range); i++) all.add(i)
  const sorted = [...all].sort((a, b) => a - b)
  const result = []
  let prev = 0
  for (const p of sorted) {
    if (prev && p - prev > 1) result.push('...')
    result.push(p)
    prev = p
  }
  return result
})

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmt(n) { return Number(n).toLocaleString('ko-KR') }

function toggleSort(col) {
  if (sortBy.value === col) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value  = col
    sortDir.value = 'desc'
  }
}

function formatDate(item) {
  if (item.status === 'scheduled' && item.scheduled_at) return '⏰ ' + localDate(item.scheduled_at)
  if (item.status === 'queued') return '대기 중'
  if (item.status === 'draft')  return '—'
  const dt = item.sent_at ?? item.created_at
  return dt ? localDate(dt) : '—'
}

function localDate(dt) {
  if (!dt) return '—'
  try {
    return new Date(dt).toLocaleDateString('ko-KR', {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    })
  } catch { return dt }
}

function isLoading(id, action) { return loadingActions.value.has(id + ':' + action) }

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

// ── Data fetching ─────────────────────────────────────────────────────────────

async function fetchList() {
  loading.value = true
  try {
    const qs = new URLSearchParams({
      page: page.value,
      per_page: perPage.value,
      ...(search.value ? { search: search.value } : {}),
    })
    const data = await api('GET', 'newsletters?' + qs)
    items.value = data.items
    total.value = data.total
    pages.value = data.pages
  } catch (e) {
    showToast(e.message, 'error')
  }
  loading.value = false
}

function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    search.value = searchInput.value.trim()
    page.value   = 1
    fetchList()
  }, 400)
}

function clearSearch() {
  searchInput.value = ''
  search.value      = ''
  page.value        = 1
  fetchList()
}

function changePage(p) {
  if (p < 1 || p > pages.value) return
  page.value = p
  selectedId.value = null
  fetchList()
}

// ── Progress polling ──────────────────────────────────────────────────────────

async function pollProgress() {
  const ids = sendingIds.value
  if (!ids.length) return
  try {
    const qs = ids.map(id => `ids[]=${id}`).join('&')
    const rows = await api('GET', 'newsletters/progress?' + qs)
    let needsRefresh = false
    for (const row of rows) {
      const item = items.value.find(i => i.id === row.id)
      if (!item) continue
      item.status    = row.status
      item._progress = { done: row.done, total: row.recipient_count, percent: row.percent }
      if (row.status !== 'sending') needsRefresh = true
    }
    if (needsRefresh) await fetchList()
  } catch {}
}

watch(sendingIds, (ids) => {
  clearInterval(pollTimer)
  if (ids.length > 0) pollTimer = setInterval(pollProgress, 3000)
}, { immediate: true })

onUnmounted(() => {
  clearInterval(pollTimer)
  clearTimeout(toastTimer)
  clearTimeout(searchTimer)
})

// ── Actions ───────────────────────────────────────────────────────────────────

function openDetail(id) {
  confirmDeleteId.value = null
  selectedId.value = selectedId.value === id ? null : id
}

async function doAction(action, item) {
  const key = item.id + ':' + action
  loadingActions.value = new Set([...loadingActions.value, key])
  try {
    if (action === 'send') {
      await api('POST', `newsletters/${item.id}/send`)
      showToast('발송이 시작되었습니다.')
    } else if (action === 'cancel') {
      await api('POST', `newsletters/${item.id}/cancel`)
      showToast('발송이 취소되었습니다.')
    } else if (action === 'force-send') {
      await api('POST', `newsletters/${item.id}/force-send`)
      showToast('즉시 발송 요청됨.')
    } else if (action === 'resend') {
      await api('POST', `newsletters/${item.id}/resend`)
      showToast('재발송 요청됨. 새 항목으로 생성됩니다.')
    }
    await fetchList()
  } catch (e) {
    showToast(e.message, 'error')
  }
  const next = new Set(loadingActions.value)
  next.delete(key)
  loadingActions.value = next
}

async function execDelete(id) {
  confirmDeleteId.value = null
  const key = id + ':delete'
  loadingActions.value = new Set([...loadingActions.value, key])
  try {
    await api('DELETE', `newsletters/${id}`)
    showToast('삭제되었습니다.')
    if (selectedId.value === id) selectedId.value = null
    await fetchList()
  } catch (e) {
    showToast(e.message, 'error')
  }
  const next = new Set(loadingActions.value)
  next.delete(key)
  loadingActions.value = next
}

// ── Init ──────────────────────────────────────────────────────────────────────

const nlParam = new URLSearchParams(window.location.search).get('nl')

fetchList().then(() => {
  if (nlParam) {
    const id = parseInt(nlParam, 10)
    if (id > 0) selectedId.value = id
  }
})
</script>
