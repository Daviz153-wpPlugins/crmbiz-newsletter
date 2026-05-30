<template>
  <div class="min-h-screen bg-gray-50 p-6 font-sans">

    <!-- 헤더 -->
    <div class="flex items-center justify-between mb-8">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">뉴스레터 대시보드</h1>
        <p class="text-sm text-gray-400 mt-0.5">CRMBiz Newsletter v{{ data?.system?.version }}</p>
      </div>
      <a :href="historyUrl"
         class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-900 text-white text-sm font-medium hover:bg-gray-700 transition-colors">
        <LayoutList class="w-4 h-4" />
        발송 이력
      </a>
    </div>

    <!-- 로딩 -->
    <div v-if="loading" class="flex items-center justify-center h-64">
      <div class="w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
    </div>

    <template v-else-if="data">

      <!-- 통계 카드 -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <StatCard label="발송 캠페인"
                  :value="data.stats.total_nl + '회'"
                  icon="SendHorizontal"
                  iconBg="bg-blue-50" iconColor="text-blue-500" />
        <StatCard label="발송 성공"
                  :value="fmt(data.stats.total_success) + '건'"
                  icon="CheckCircle"
                  iconBg="bg-green-50" iconColor="text-green-500" />
        <StatCard label="발송 실패"
                  :value="fmt(data.stats.total_fail) + '건'"
                  icon="XCircle"
                  iconBg="bg-red-50" iconColor="text-red-400" />
        <StatCard label="성공률"
                  :value="data.stats.success_rate + '%'"
                  :sub="fmt(data.stats.total_success + data.stats.total_fail) + '건 발송'"
                  icon="TrendingUp"
                  iconBg="bg-purple-50" iconColor="text-purple-500" />
      </div>

      <!-- 차트 -->
      <div v-if="data.stats.total_nl > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-8">

        <!-- 30일 추이 -->
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="text-sm font-semibold text-gray-700 mb-4">최근 30일 발송 추이</h2>
          <div class="h-48">
            <canvas ref="dailyChart"></canvas>
          </div>
        </div>

        <!-- 캠페인 성과 -->
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="text-sm font-semibold text-gray-700 mb-4">최근 캠페인 성과</h2>
          <div class="h-48">
            <canvas ref="campaignChart"></canvas>
          </div>
        </div>
      </div>

      <!-- 시스템 상태 + 최근 캠페인 목록 -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        <!-- 시스템 상태 -->
        <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <h2 class="text-sm font-semibold text-gray-700 mb-4">시스템 상태</h2>
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">플러그인</span>
              <StatusBadge :status="true" :ok="'v' + data.system.version" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">FluentCRM</span>
              <StatusBadge :status="data.system.fluent_crm" ok="활성화됨" fail="비활성" />
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">FluentSMTP</span>
              <StatusBadge :status="data.system.fluent_smtp" ok="활성화됨" fail="기본 메일 사용" />
            </div>
            <div class="border-t border-gray-100 pt-3">
              <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">연락처</span>
                <span class="text-sm font-semibold text-gray-900">{{ fmt(data.system.contact_count) }}명</span>
              </div>
            </div>
          </div>
        </div>

        <!-- 최근 캠페인 목록 -->
        <div class="lg:col-span-2 bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-gray-700">최근 캠페인</h2>
            <a :href="historyUrl" class="text-xs text-blue-500 hover:underline">전체 보기</a>
          </div>

          <div v-if="!data.campaigns.length" class="text-sm text-gray-400 text-center py-8">
            아직 발송된 캠페인이 없습니다.
          </div>

          <div v-else class="divide-y divide-gray-50">
            <div v-for="c in data.campaigns.slice().reverse().slice(0,5)" :key="c.id"
                 class="py-3 flex items-center justify-between gap-4">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-900 truncate">{{ c.title }}</p>
                <p class="text-xs text-gray-400 mt-0.5">{{ formatDate(c.sent_at) }}</p>
              </div>
              <div class="flex items-center gap-4 flex-shrink-0 text-xs text-gray-500">
                <div class="text-center">
                  <p class="font-semibold text-green-600 text-sm">{{ c.open_rate }}%</p>
                  <p>오픈</p>
                </div>
                <div class="text-center">
                  <p class="font-semibold text-blue-600 text-sm">{{ c.click_rate }}%</p>
                  <p>클릭</p>
                </div>
                <div class="text-center">
                  <p class="font-semibold text-gray-700 text-sm">{{ fmt(c.sent) }}</p>
                  <p>발송</p>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </template>

  </div>
</template>

<script setup>
import { ref, onMounted, nextTick } from 'vue'
import { SendHorizontal, CheckCircle, XCircle, TrendingUp, LayoutList } from 'lucide-vue-next'
import Chart from 'chart.js/auto'
import StatCard from '@/components/StatCard.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const loading      = ref(true)
const data         = ref(null)
const dailyChart   = ref(null)
const campaignChart = ref(null)

const historyUrl = window.CrmbizNL?.historyUrl ?? '#'

function fmt(n) {
  return Number(n).toLocaleString('ko-KR')
}

function formatDate(dt) {
  if (!dt) return '—'
  try {
    return new Date(dt).toLocaleDateString('ko-KR', { month: 'short', day: 'numeric' })
  } catch { return dt }
}

async function fetchData() {
  const res = await fetch(window.CrmbizNL.restUrl + 'dashboard', {
    headers: { 'X-WP-Nonce': window.CrmbizNL.nonce },
  })
  data.value = await res.json()
  loading.value = false
  await nextTick()
  initCharts()
}

function initCharts() {
  if (!data.value || !data.value.chart) return

  Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif'
  Chart.defaults.font.size   = 11

  // 30일 라인 차트
  if (dailyChart.value) {
    new Chart(dailyChart.value, {
      type: 'line',
      data: {
        labels: data.value.chart.labels,
        datasets: [{
          data: data.value.chart.counts,
          borderColor: '#3b82f6',
          backgroundColor: 'rgba(59,130,246,.08)',
          borderWidth: 2,
          pointRadius: 2,
          fill: true,
          tension: 0.4,
        }],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { maxTicksLimit: 8 } },
          y: { beginAtZero: true, ticks: { precision: 0 } },
        },
      },
    })
  }

  // 캠페인 바 차트
  if (campaignChart.value && data.value.campaigns.length) {
    const camps = data.value.campaigns
    new Chart(campaignChart.value, {
      type: 'bar',
      data: {
        labels: camps.map(c => c.title.length > 12 ? c.title.slice(0, 12) + '…' : c.title),
        datasets: [
          { label: '오픈율 %', data: camps.map(c => c.open_rate),  backgroundColor: 'rgba(16,185,129,.75)', borderRadius: 4 },
          { label: '클릭율 %', data: camps.map(c => c.click_rate), backgroundColor: 'rgba(99,102,241,.75)', borderRadius: 4 },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 10 } } },
        scales: {
          x: { grid: { display: false } },
          y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
        },
      },
    })
  }
}

onMounted(fetchData)
</script>
