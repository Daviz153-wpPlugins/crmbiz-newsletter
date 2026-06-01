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

      <!-- Stats -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

        <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">발송 캠페인</p>
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
          <a v-for="c in recentCampaigns" :key="c.id"
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

      </div>

    </template>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { ChevronRight, Mail } from 'lucide-vue-next'
import Chart from 'chart.js/auto'

const loading    = ref(true)
const data       = ref(null)
const dailyChart = ref(null)
const chartDays  = ref(30)
let   chartInstance = null

const historyUrl = window.CrmbizNL?.historyUrl ?? '#'

// ── Computed ──────────────────────────────────────────────────────────────────

const recentCampaigns = computed(() => (data.value?.campaigns ?? []).slice(0, 6))

const chartTotal = computed(() =>
  (data.value?.chart?.counts ?? []).reduce((sum, n) => sum + n, 0)
)

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
    return new Date(dt).toLocaleDateString('ko-KR', { month: 'short', day: 'numeric' })
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

async function fetchData(days = 30) {
  try {
    const res  = await fetch(window.CrmbizNL.restUrl + 'dashboard?days=' + days, {
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

onMounted(() => fetchData(chartDays.value))
</script>
