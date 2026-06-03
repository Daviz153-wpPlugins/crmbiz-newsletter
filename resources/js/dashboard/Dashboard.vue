<template>
  <div class="min-h-screen bg-gray-50 p-6 font-sans">

    <!-- Header -->
    <div class="flex items-start justify-between mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">뉴스레터 대시보드</h1>
        <p class="text-sm text-gray-400 mt-0.5">
          CRMBiz Newsletter
          <span v-if="data"> v{{ data.system.version }}</span>
        </p>
      </div>
      <a :href="historyUrl"
         class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-600 text-sm font-medium hover:bg-gray-50 hover:text-gray-900 transition-colors">
        발송 이력
        <ChevronRight class="w-3.5 h-3.5" />
      </a>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center h-64">
      <div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else-if="data">

      <!-- 발송 예약/대기 현황 (최우선 표시) -->
      <div class="rounded-2xl p-5 mb-4 border"
           :class="pendingTotal > 0
             ? 'bg-blue-50 border-blue-200'
             : 'bg-white border-gray-100 shadow-sm'">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide mb-2"
               :class="pendingTotal > 0 ? 'text-blue-500' : 'text-gray-400'">
              발송 예약 / 대기 현황
            </p>
            <div v-if="pendingTotal > 0" class="flex flex-wrap gap-3">
              <span v-if="data.pending.sending > 0"
                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-blue-500 text-white">
                <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                발송 중 {{ data.pending.sending }}건
              </span>
              <span v-if="data.pending.queued > 0"
                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-amber-100 text-amber-700">
                발송 대기 {{ data.pending.queued }}건
              </span>
              <span v-if="data.pending.scheduled > 0"
                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-indigo-100 text-indigo-700">
                예약됨 {{ data.pending.scheduled }}건
              </span>
              <span v-if="data.pending.draft > 0"
                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-semibold bg-gray-100 text-gray-500">
                임시저장 {{ data.pending.draft }}건
              </span>
            </div>
            <p v-else class="text-sm text-gray-400">현재 대기 중인 캠페인 없음</p>
            <p v-if="data.pending.next_scheduled_at" class="text-xs text-indigo-500 mt-2">
              다음 예약 발송: {{ formatDate(data.pending.next_scheduled_at) }}
            </p>
          </div>
          <a :href="historyUrl" class="text-xs text-blue-500 hover:underline flex-shrink-0 mt-0.5">이력 보기 →</a>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">완료 캠페인</p>
          <p class="text-3xl font-bold text-gray-900">{{ fmt(data.stats.total_nl) }}<span class="text-base font-normal text-gray-400 ml-1">회</span></p>
        </div>

        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">발송 성공</p>
          <p class="text-3xl font-bold text-gray-900">{{ fmtShort(data.stats.total_success) }}<span class="text-base font-normal text-gray-400 ml-1">건</span></p>
        </div>

        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">발송 실패</p>
          <p class="text-3xl font-bold" :class="data.stats.total_fail > 0 ? 'text-red-500' : 'text-gray-900'">
            {{ fmtShort(data.stats.total_fail) }}<span class="text-base font-normal text-gray-400 ml-1">건</span>
          </p>
        </div>

        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">성공률</p>
          <p class="text-3xl font-bold" :class="data.stats.success_rate >= 99 ? 'text-green-600' : data.stats.success_rate >= 95 ? 'text-yellow-500' : 'text-red-500'">
            {{ data.stats.success_rate }}<span class="text-base font-normal text-gray-400 ml-0.5">%</span>
          </p>
        </div>

      </div>

      <!-- Chart + System status -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">

        <!-- 발송 추이 차트 -->
        <div class="lg:col-span-2 bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div class="flex items-center justify-between mb-5">
            <h2 class="text-sm font-semibold text-gray-700">최근 {{ chartDays }}일 발송 추이</h2>
            <div class="flex items-center gap-2">
              <span class="text-xs text-gray-400 mr-1">{{ chartTotal }}건 발송</span>
              <div class="flex rounded-lg border border-gray-200 overflow-hidden text-xs font-medium">
                <button v-for="d in [7, 30, 90]" :key="d"
                  @click="setChartDays(d)"
                  :class="chartDays === d
                    ? 'bg-blue-500 text-white'
                    : 'bg-white text-gray-500 hover:bg-gray-50'"
                  class="px-2.5 py-1 transition-colors">
                  {{ d }}일
                </button>
              </div>
            </div>
          </div>
          <div class="h-44">
            <canvas ref="dailyChart"></canvas>
          </div>
        </div>

        <!-- 시스템 상태 -->
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 flex flex-col justify-between">
          <div>
            <h2 class="text-sm font-semibold text-gray-700 mb-4">시스템 상태</h2>
            <div class="space-y-3">

              <div class="flex items-center justify-between">
                <span class="text-sm text-gray-500">플러그인</span>
                <span class="inline-flex items-center gap-1.5 text-xs font-medium text-green-700 bg-green-50 px-2 py-0.5 rounded-full">
                  <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                  v{{ data.system.version }}
                </span>
              </div>

              <div class="flex items-center justify-between">
                <span class="text-sm text-gray-500">FluentCRM</span>
                <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded-full"
                      :class="data.system.fluent_crm ? 'text-green-700 bg-green-50' : 'text-red-600 bg-red-50'">
                  <span class="w-1.5 h-1.5 rounded-full" :class="data.system.fluent_crm ? 'bg-green-500' : 'bg-red-400'"></span>
                  {{ data.system.fluent_crm ? '활성화됨' : '비활성' }}
                </span>
              </div>

              <div class="flex items-center justify-between">
                <span class="text-sm text-gray-500">FluentSMTP</span>
                <span class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-0.5 rounded-full"
                      :class="data.system.fluent_smtp ? 'text-green-700 bg-green-50' : 'text-gray-500 bg-gray-100'">
                  <span class="w-1.5 h-1.5 rounded-full" :class="data.system.fluent_smtp ? 'bg-green-500' : 'bg-gray-400'"></span>
                  {{ data.system.fluent_smtp ? '활성화됨' : '기본 메일 사용' }}
                </span>
              </div>

            </div>
          </div>

          <div class="pt-4 mt-4 border-t border-gray-100">
            <p class="text-xs text-gray-400">등록 연락처</p>
            <p class="text-2xl font-bold text-gray-900 mt-0.5">
              {{ fmt(data.system.contact_count) }}<span class="text-sm font-normal text-gray-400 ml-1">명</span>
            </p>
          </div>
        </div>

      </div>

      <!-- 최근 캠페인 -->
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
          <h2 class="text-sm font-semibold text-gray-700">최근 캠페인</h2>
          <a :href="historyUrl" class="text-xs text-blue-500 hover:underline flex items-center gap-0.5">
            전체 보기 <ChevronRight class="w-3 h-3" />
          </a>
        </div>

        <!-- Empty -->
        <div v-if="!data.campaigns.length" class="flex flex-col items-center justify-center py-14 gap-3">
          <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center">
            <Mail class="w-5 h-5 text-gray-300" />
          </div>
          <p class="text-sm text-gray-400">아직 발송된 캠페인이 없습니다.</p>
        </div>

        <!-- Campaign list -->
        <div v-else>
          <a v-for="c in data.campaigns" :key="c.id"
             :href="historyUrl + '&nl=' + c.id"
             class="flex items-center gap-4 px-6 py-4 border-b border-gray-50 last:border-0 hover:bg-gray-50/60 transition-colors group cursor-pointer">

            <!-- Status dot + title -->
            <div class="flex-1 min-w-0 flex items-center gap-3">
              <span class="w-2 h-2 rounded-full flex-shrink-0 bg-green-400"></span>
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate group-hover:text-blue-600 transition-colors">
                  {{ c.title }}
                </p>
                <p class="text-xs text-gray-400 mt-0.5">{{ formatDate(c.sent_at) }}</p>
              </div>
            </div>

            <!-- Metrics -->
            <div class="flex items-center gap-3 flex-shrink-0">
              <div class="text-center min-w-12">
                <p class="text-sm font-bold text-green-600">{{ c.open_rate }}%</p>
                <p class="text-xs text-gray-400">오픈</p>
              </div>
              <div class="text-center min-w-12">
                <p class="text-sm font-bold text-blue-600">{{ c.click_rate }}%</p>
                <p class="text-xs text-gray-400">클릭</p>
              </div>
              <div class="text-center min-w-14">
                <p class="text-sm font-semibold text-gray-600">{{ fmtShort(c.sent) }}</p>
                <p class="text-xs text-gray-400">발송</p>
              </div>
              <ChevronRight class="w-4 h-4 text-gray-300 group-hover:text-gray-500 transition-colors flex-shrink-0" />
            </div>

          </a>
        </div>

        <!-- 캠페인 페이지네이션 -->
        <div v-if="data.campaign_pages > 1 || data.campaign_total > 5"
             class="flex items-center justify-between px-6 py-3 border-t border-gray-100 bg-gray-50/40">
          <div class="flex items-center gap-2">
            <span class="text-xs text-gray-400">페이지 {{ campaignPage }} of {{ data.campaign_pages }}</span>
            <select v-model="campaignPerPage" @change="changeCampaignPage(1)"
                    class="border border-gray-200 rounded-lg pl-3 pr-7 py-1.5 text-xs bg-white"
                    style="-webkit-appearance:none;-moz-appearance:none;appearance:none;outline:none;box-shadow:none;border-radius:0.5rem;background-image:url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E&quot;);background-repeat:no-repeat;background-position:right 8px center;background-size:12px">
              <option :value="5">5 / page</option>
              <option :value="10">10 / page</option>
              <option :value="20">20 / page</option>
            </select>
            <span class="text-xs text-gray-400">총계 {{ data.campaign_total }}</span>
          </div>
          <div class="flex items-center gap-1">
            <button @click="changeCampaignPage(1)" :disabled="campaignPage <= 1"
                    class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-500 disabled:opacity-30 hover:bg-gray-50 transition-colors">
              <ChevronsLeft class="w-3.5 h-3.5" />
            </button>
            <button @click="changeCampaignPage(campaignPage - 1)" :disabled="campaignPage <= 1"
                    class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-500 disabled:opacity-30 hover:bg-gray-50 transition-colors">
              <ChevronLeft class="w-3.5 h-3.5" />
            </button>
            <button class="w-8 h-8 flex items-center justify-center rounded-lg border-2 border-gray-800 text-gray-800 text-xs font-medium">{{ campaignPage }}</button>
            <button @click="changeCampaignPage(campaignPage + 1)" :disabled="campaignPage >= data.campaign_pages"
                    class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-500 disabled:opacity-30 hover:bg-gray-50 transition-colors">
              <ChevronRight class="w-3.5 h-3.5" />
            </button>
            <button @click="changeCampaignPage(data.campaign_pages)" :disabled="campaignPage >= data.campaign_pages"
                    class="w-8 h-8 flex items-center justify-center rounded-lg border border-gray-200 text-gray-500 disabled:opacity-30 hover:bg-gray-50 transition-colors">
              <ChevronsRight class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>

      </div>

    </template>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { ChevronsLeft, ChevronLeft, ChevronRight, ChevronsRight, Mail } from 'lucide-vue-next'
import Chart from 'chart.js/auto'

const loading         = ref(true)
const data            = ref(null)
const dailyChart      = ref(null)
const chartDays       = ref(30)
const campaignPage    = ref(1)
const campaignPerPage = ref(5)
let   chartInstance   = null

const historyUrl = window.CrmbizNL?.historyUrl ?? '#'

// ── Computed ──────────────────────────────────────────────────────────────────

// recentCampaigns 제거 — 서버 페이지네이션으로 대체

const chartTotal = computed(() =>
  (data.value?.chart?.counts ?? []).reduce((sum, n) => sum + n, 0)
)

const pendingTotal = computed(() => {
  const p = data.value?.pending
  if (!p) return 0
  return p.scheduled + p.queued + p.sending + p.draft
})

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmt(n) { return Number(n).toLocaleString('ko-KR') }

function fmtShort(n) {
  n = Number(n)
  if (n >= 10000) return (n / 10000).toFixed(n % 10000 === 0 ? 0 : 1) + '만'
  return n.toLocaleString('ko-KR')
}

function formatDate(dt) {
  if (!dt) return '—'
  try {
    return new Date(dt).toLocaleDateString('ko-KR', {
      month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    })
  } catch { return dt }
}

// ── Chart ─────────────────────────────────────────────────────────────────────

function initChart() {
  if (!dailyChart.value || !data.value?.chart) return

  if (chartInstance) {
    chartInstance.destroy()
    chartInstance = null
  }

  Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
  Chart.defaults.font.size   = 11
  Chart.defaults.color       = '#9ca3af'

  chartInstance = new Chart(dailyChart.value, {
    type: 'line',
    data: {
      labels: data.value.chart.labels,
      datasets: [{
        data:            data.value.chart.counts,
        borderColor:     '#3b82f6',
        backgroundColor: 'rgba(59,130,246,.07)',
        borderWidth:     2,
        pointRadius:     0,
        pointHoverRadius: 4,
        pointHoverBackgroundColor: '#3b82f6',
        fill:    true,
        tension: 0.4,
      }],
    },
    options: {
      responsive:          true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#111827',
          titleColor:      '#9ca3af',
          bodyColor:       '#f9fafb',
          padding:         10,
          cornerRadius:    8,
          callbacks: { label: ctx => ` ${ctx.parsed.y.toLocaleString('ko-KR')}건` },
        },
      },
      scales: {
        x: {
          grid:  { display: false },
          border: { display: false },
          ticks: { maxTicksLimit: 8 },
        },
        y: {
          beginAtZero: true,
          grid:  { color: 'rgba(0,0,0,.04)' },
          border: { display: false, dash: [4, 4] },
          ticks: { precision: 0 },
        },
      },
    },
  })
}

// ── Data ──────────────────────────────────────────────────────────────────────

async function fetchData(days = chartDays.value, cPage = campaignPage.value, cPerPage = campaignPerPage.value) {
  try {
    const qs = new URLSearchParams({
      days,
      campaign_page: cPage,
      per_page: cPerPage,
    })
    const res = await fetch(window.CrmbizNL.restUrl + 'dashboard?' + qs, {
      headers: { 'X-WP-Nonce': window.CrmbizNL.nonce },
    })
    data.value    = await res.json()
    loading.value = false
    await nextTick()
    initChart()
  } catch {
    loading.value = false
  }
}

async function setChartDays(days) {
  if (chartDays.value === days) return
  chartDays.value = days
  await fetchData(days)
}

async function changeCampaignPage(p) {
  if (!data.value || p < 1 || p > data.value.campaign_pages) return
  campaignPage.value = p
  const _prev = loading.value
  loading.value = false // 차트 재초기화 방지
  try {
    const qs = new URLSearchParams({
      days: chartDays.value,
      campaign_page: p,
      per_page: campaignPerPage.value,
    })
    const res = await fetch(window.CrmbizNL.restUrl + 'dashboard?' + qs, {
      headers: { 'X-WP-Nonce': window.CrmbizNL.nonce },
    })
    const json = await res.json()
    // 캠페인 관련 데이터만 교체 (차트는 유지)
    data.value = { ...data.value, ...json, chart: data.value.chart }
  } catch {}
}

onMounted(() => fetchData())
</script>
