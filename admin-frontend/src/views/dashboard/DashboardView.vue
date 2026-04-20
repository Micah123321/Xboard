<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { Component } from 'vue'
import { ElMessage } from 'element-plus'
import {
  Coin,
  DataAnalysis,
  Discount,
  Download,
  Tickets,
  Upload,
  User,
  UserFilled,
} from '@element-plus/icons-vue'
import {
  getDashboardStats,
  getOrderTrend,
  getQueueStats,
  getSystemStatus,
  getTrafficRank,
} from '@/api/admin'
import type {
  DashboardStats,
  OrderTrendPoint,
  OrderTrendSummary,
  QueueStats,
  SystemStatus,
  TrafficRankItem,
} from '@/types/api'
import {
  buildTrendChart,
  formatCompactNumber,
  formatCurrency,
  formatDateTime,
  formatPercent,
  formatTraffic,
  getDateRangeFromPreset,
  getQueueWaitName,
  getQueueWaitSeconds,
  type TimePreset,
} from '@/utils/dashboard'
import { useAppStore } from '@/stores/app'

interface MetricCard {
  key: string
  label: string
  value: string
  detail: string
  change?: string
  tone: 'dark' | 'light' | 'soft'
  icon: Component
}

const app = useAppStore()
const booting = ref(true)
const trendLoading = ref(false)
const rankLoading = ref(false)
const systemLoading = ref(false)
const trendPreset = ref<TimePreset>('30d')
const rankPreset = ref<TimePreset>('1d')

const overview = ref<DashboardStats | null>(null)
const trendList = ref<OrderTrendPoint[]>([])
const trendSummary = ref<OrderTrendSummary | null>(null)
const nodeRanks = ref<TrafficRankItem[]>([])
const userRanks = ref<TrafficRankItem[]>([])
const systemStatus = ref<SystemStatus | null>(null)
const queueStats = ref<QueueStats | null>(null)

const trendPresetOptions = [
  { label: '7天', value: '7d' },
  { label: '30天', value: '30d' },
  { label: '90天', value: '90d' },
] as const

const rankPresetOptions = [
  { label: '24h', value: '1d' },
  { label: '7天', value: '7d' },
  { label: '30天', value: '30d' },
] as const

const dashboardStats = computed<DashboardStats>(() => overview.value ?? {
  todayIncome: 0,
  dayIncomeGrowth: 0,
  currentMonthIncome: 0,
  lastMonthIncome: 0,
  monthIncomeGrowth: 0,
  lastMonthIncomeGrowth: 0,
  currentMonthCommissionPayout: 0,
  lastMonthCommissionPayout: 0,
  commissionGrowth: 0,
  commissionPendingTotal: 0,
  currentMonthNewUsers: 0,
  totalUsers: 0,
  activeUsers: 0,
  userGrowth: 0,
  onlineUsers: 0,
  onlineDevices: 0,
  ticketPendingTotal: 0,
  onlineNodes: 0,
  todayTraffic: { upload: 0, download: 0, total: 0 },
  monthTraffic: { upload: 0, download: 0, total: 0 },
  totalTraffic: { upload: 0, download: 0, total: 0 },
})

const metricCards = computed<MetricCard[]>(() => [
  {
    key: 'monthIncome',
    label: '月收入',
    value: formatCurrency(dashboardStats.value.currentMonthIncome),
    detail: `上月 ${formatCurrency(dashboardStats.value.lastMonthIncome)}`,
    change: formatPercent(dashboardStats.value.monthIncomeGrowth),
    tone: 'dark',
    icon: Coin,
  },
  {
    key: 'todayIncome',
    label: '今日收入',
    value: formatCurrency(dashboardStats.value.todayIncome),
    detail: `日环比 ${formatPercent(dashboardStats.value.dayIncomeGrowth)}`,
    change: formatPercent(dashboardStats.value.dayIncomeGrowth),
    tone: 'light',
    icon: DataAnalysis,
  },
  {
    key: 'ticketPendingTotal',
    label: '待处理工单',
    value: String(dashboardStats.value.ticketPendingTotal),
    detail: '待客服跟进',
    tone: 'soft',
    icon: Tickets,
  },
  {
    key: 'commissionPendingTotal',
    label: '待处理佣金',
    value: String(dashboardStats.value.commissionPendingTotal),
    detail: `本月已发放 ${formatCurrency(dashboardStats.value.currentMonthCommissionPayout)}`,
    change: formatPercent(dashboardStats.value.commissionGrowth),
    tone: 'soft',
    icon: Discount,
  },
  {
    key: 'newUsers',
    label: '月新增用户',
    value: formatCompactNumber(dashboardStats.value.currentMonthNewUsers),
    detail: `活跃用户 ${formatCompactNumber(dashboardStats.value.activeUsers)}`,
    change: formatPercent(dashboardStats.value.userGrowth),
    tone: 'light',
    icon: User,
  },
  {
    key: 'totalUsers',
    label: '总用户',
    value: formatCompactNumber(dashboardStats.value.totalUsers),
    detail: `在线 ${formatCompactNumber(dashboardStats.value.onlineUsers)} · 设备 ${formatCompactNumber(dashboardStats.value.onlineDevices)}`,
    tone: 'light',
    icon: UserFilled,
  },
  {
    key: 'monthUpload',
    label: '月上传',
    value: formatTraffic(dashboardStats.value.monthTraffic.upload),
    detail: `今日 ${formatTraffic(dashboardStats.value.todayTraffic.upload)}`,
    tone: 'soft',
    icon: Upload,
  },
  {
    key: 'monthDownload',
    label: '月下载',
    value: formatTraffic(dashboardStats.value.monthTraffic.download),
    detail: `总计 ${formatTraffic(dashboardStats.value.totalTraffic.download)}`,
    tone: 'soft',
    icon: Download,
  },
])

const heroMeta = computed(() => [
  `在线节点 ${formatCompactNumber(dashboardStats.value.onlineNodes)}`,
  `在线用户 ${formatCompactNumber(dashboardStats.value.onlineUsers)}`,
  `总流量 ${formatTraffic(dashboardStats.value.totalTraffic.total)}`,
])

const heroSummary = computed(() => [
  {
    label: '月收入',
    value: formatCurrency(dashboardStats.value.currentMonthIncome),
  },
  {
    label: '上月收入',
    value: formatCurrency(dashboardStats.value.lastMonthIncome),
  },
  {
    label: '在线用户',
    value: formatCompactNumber(dashboardStats.value.onlineUsers),
  },
])

const trendChart = computed(() => buildTrendChart(trendList.value))

const latestTrendPoint = computed(() => {
  if (!trendList.value.length) return null
  return trendList.value[trendList.value.length - 1]
})

const trendSnapshot = computed(() => {
  if (!latestTrendPoint.value) return null
  return {
    date: latestTrendPoint.value.date,
    orderAmount: formatCurrency(latestTrendPoint.value.paid_total),
    commissionAmount: formatCurrency(latestTrendPoint.value.commission_total),
    orderCount: latestTrendPoint.value.paid_count,
  }
})

const queueHealthRows = computed(() => [
  {
    label: '运行状态',
    value: queueStats.value?.status ? '正常' : '异常',
    tone: queueStats.value?.status ? 'positive' : 'negative',
  },
  {
    label: '近期待处理',
    value: formatCompactNumber(queueStats.value?.recentJobs ?? 0),
  },
  {
    label: '每分钟处理量',
    value: formatCompactNumber(queueStats.value?.jobsPerMinute ?? 0),
  },
  {
    label: '活动进程',
    value: formatCompactNumber(queueStats.value?.processes ?? 0),
  },
])

const systemRows = computed(() => [
  {
    label: '调度器',
    value: systemStatus.value?.schedule ? '正常' : '异常',
    tone: systemStatus.value?.schedule ? 'positive' : 'negative',
  },
  {
    label: 'Horizon',
    value: systemStatus.value?.horizon ? '在线' : '离线',
    tone: systemStatus.value?.horizon ? 'positive' : 'negative',
  },
  {
    label: '最后调度',
    value: formatDateTime(systemStatus.value?.schedule_last_runtime),
  },
  {
    label: '最长等待',
    value: getQueueWaitSeconds(queueStats.value) === null
      ? 'N/A'
      : `${getQueueWaitSeconds(queueStats.value)}s`,
  },
  {
    label: '等待队列',
    value: getQueueWaitName(queueStats.value),
  },
  {
    label: '最忙吞吐队列',
    value: queueStats.value?.queueWithMaxThroughput || 'N/A',
  },
])

async function loadOverviewPanels() {
  systemLoading.value = true
  try {
    const [statsResponse, systemResponse, queueResponse] = await Promise.all([
      getDashboardStats(),
      getSystemStatus(),
      getQueueStats(),
    ])

    overview.value = statsResponse.data
    systemStatus.value = systemResponse.data
    queueStats.value = queueResponse.data
  } finally {
    systemLoading.value = false
  }
}

async function loadTrend() {
  trendLoading.value = true
  try {
    const range = getDateRangeFromPreset(trendPreset.value)
    const response = await getOrderTrend({
      startDate: range.startDate,
      endDate: range.endDate,
    })

    trendList.value = response.data.list || []
    trendSummary.value = response.data.summary
  } finally {
    trendLoading.value = false
  }
}

async function loadRankings() {
  rankLoading.value = true
  try {
    const range = getDateRangeFromPreset(rankPreset.value)
    const [nodeResponse, userResponse] = await Promise.all([
      getTrafficRank({
        type: 'node',
        startTime: range.startTime,
        endTime: range.endTime,
      }),
      getTrafficRank({
        type: 'user',
        startTime: range.startTime,
        endTime: range.endTime,
      }),
    ])

    nodeRanks.value = nodeResponse.data
    userRanks.value = userResponse.data
  } finally {
    rankLoading.value = false
  }
}

async function refreshDashboard() {
  booting.value = true
  const results = await Promise.allSettled([
    loadOverviewPanels(),
    loadTrend(),
    loadRankings(),
  ])

  if (results.some((item) => item.status === 'rejected')) {
    ElMessage.error('部分仪表盘数据加载失败，请稍后重试')
  }

  booting.value = false
}

function rankBarWidth(index: number): string {
  return `${Math.max(28, 100 - index * 12)}%`
}

watch(trendPreset, () => {
  void loadTrend().catch(() => ElMessage.error('趋势数据刷新失败'))
})

watch(rankPreset, () => {
  void loadRankings().catch(() => ElMessage.error('排行数据刷新失败'))
})

onMounted(() => {
  void refreshDashboard()
})
</script>

<template>
  <div class="dashboard-page">
    <section class="dashboard-hero">
      <div class="dashboard-copy">
        <p class="dashboard-kicker">Dashboard</p>
        <h1>经营概览，一眼看清。</h1>
        <p class="dashboard-description">
          保留相同的数据入口，但以更轻的页面结构展示收入、流量、用户和系统状态。
        </p>
      </div>

      <div class="dashboard-hero-side">
        <div class="hero-status">
          <span>{{ booting ? '正在同步数据' : '数据已同步' }}</span>
          <strong>/{{ app.securePath || 'admin' }}</strong>
        </div>

        <div class="hero-highlights">
          <article v-for="item in heroSummary" :key="item.label">
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </article>
        </div>
      </div>
    </section>

    <section class="hero-meta-list">
      <span v-for="item in heroMeta" :key="item">{{ item }}</span>
    </section>

    <section class="metrics-grid">
      <article
        v-for="card in metricCards"
        :key="card.key"
        class="metric-card"
        :class="`tone-${card.tone}`"
      >
        <div class="metric-card__meta">
          <span>{{ card.label }}</span>
          <ElIcon><component :is="card.icon" /></ElIcon>
        </div>
        <strong class="metric-card__value">{{ card.value }}</strong>
        <p class="metric-card__detail">{{ card.detail }}</p>
        <span
          v-if="card.change"
          class="metric-card__change"
          :class="Number(card.change.replace('%', '')) >= 0 ? 'positive' : 'negative'"
        >
          {{ card.change }}
        </span>
      </article>
    </section>

    <section class="content-grid">
      <article class="panel trend-panel">
        <header class="panel-header">
          <div>
            <p class="panel-kicker">Revenue</p>
            <h2>收入趋势</h2>
            <p class="panel-description">
              {{ trendSummary?.start_date || '--' }} 至 {{ trendSummary?.end_date || '--' }}
            </p>
          </div>

          <div class="filter-group">
            <button
              v-for="option in trendPresetOptions"
              :key="option.value"
              type="button"
              class="filter-pill"
              :class="{ active: option.value === trendPreset }"
              @click="trendPreset = option.value"
            >
              {{ option.label }}
            </button>
          </div>
        </header>

        <div class="trend-summary">
          <article class="trend-stat">
            <span>成交总额</span>
            <strong>{{ formatCurrency(trendSummary?.paid_total ?? 0) }}</strong>
            <p>共 {{ formatCompactNumber(trendSummary?.paid_count ?? 0) }} 笔</p>
          </article>
          <article class="trend-stat">
            <span>佣金支出</span>
            <strong>{{ formatCurrency(trendSummary?.commission_total ?? 0) }}</strong>
            <p>佣金率 {{ formatPercent(trendSummary?.commission_rate ?? 0, false) }}</p>
          </article>
          <article class="trend-stat">
            <span>订单均价</span>
            <strong>{{ formatCurrency(trendSummary?.avg_paid_amount ?? 0) }}</strong>
            <p>单笔均值</p>
          </article>
        </div>

        <div class="chart-panel">
          <div class="panel-state" v-if="trendLoading">趋势数据同步中…</div>
          <svg
            class="trend-chart"
            viewBox="0 0 760 260"
            preserveAspectRatio="none"
          >
            <g v-for="grid in trendChart.gridLines" :key="grid.y">
              <line x1="24" :y1="grid.y" x2="736" :y2="grid.y" />
              <text x="24" :y="grid.y - 8">{{ grid.label }}</text>
            </g>

            <path class="trend-chart__area" :d="trendChart.areaPath" />
            <path class="trend-chart__line" :d="trendChart.path" />

            <g v-for="point in trendChart.points" :key="point.date">
              <circle
                class="trend-chart__dot"
                :class="{ active: latestTrendPoint?.date === point.date }"
                :cx="point.x"
                :cy="point.y"
                r="4"
              />
            </g>
          </svg>

          <div class="trend-axis">
            <span
              v-for="label in trendChart.labels"
              :key="`${label.index}-${label.label}`"
              :style="{ left: `${label.xPercent}%` }"
            >
              {{ label.label }}
            </span>
          </div>
        </div>

        <div v-if="trendSnapshot" class="snapshot-card">
          <div>
            <span>最近记录</span>
            <strong>{{ trendSnapshot.date }}</strong>
          </div>
          <div>
            <span>收入</span>
            <strong>{{ trendSnapshot.orderAmount }}</strong>
          </div>
          <div>
            <span>佣金</span>
            <strong>{{ trendSnapshot.commissionAmount }}</strong>
          </div>
          <div>
            <span>订单</span>
            <strong>{{ trendSnapshot.orderCount }} 笔</strong>
          </div>
        </div>
      </article>

      <article class="panel panel-dark system-panel">
        <header class="panel-header panel-header--dark">
          <div>
            <p class="panel-kicker panel-kicker--dark">Operations</p>
            <h2>作业详情</h2>
            <p class="panel-description panel-description--dark">
              队列、调度器和关键系统状态。
            </p>
          </div>
        </header>

        <div class="panel-state panel-state--dark" v-if="systemLoading">系统状态同步中…</div>
        <div class="status-grid status-grid--dark">
          <div v-for="row in queueHealthRows" :key="row.label" class="status-item status-item--dark">
            <span>{{ row.label }}</span>
            <strong :class="row.tone || ''">{{ row.value }}</strong>
          </div>
        </div>

        <div class="status-grid status-grid--dark status-grid--compact">
          <div v-for="row in systemRows" :key="row.label" class="status-item status-item--dark">
            <span>{{ row.label }}</span>
            <strong :class="row.tone || ''">{{ row.value }}</strong>
          </div>
        </div>
      </article>
    </section>

    <section class="rank-grid">
      <article class="panel rank-panel">
        <header class="panel-header">
          <div>
            <p class="panel-kicker">Traffic</p>
            <h2>节点流量排行</h2>
          </div>

          <div class="filter-group">
            <button
              v-for="option in rankPresetOptions"
              :key="`node-${option.value}`"
              type="button"
              class="filter-pill"
              :class="{ active: option.value === rankPreset }"
              @click="rankPreset = option.value"
            >
              {{ option.label }}
            </button>
          </div>
        </header>

        <div class="panel-state" v-if="rankLoading">排行数据同步中…</div>
        <div v-if="nodeRanks.length" class="rank-list">
          <div v-for="(item, index) in nodeRanks.slice(0, 6)" :key="item.id" class="rank-item">
            <div class="rank-item__copy">
              <strong>{{ item.name }}</strong>
              <span>{{ formatTraffic(item.value) }}</span>
            </div>
            <div class="rank-item__bar">
              <span :style="{ width: rankBarWidth(index) }" />
            </div>
            <em :class="Number(item.change) >= 0 ? 'positive' : 'negative'">
              {{ formatPercent(item.change) }}
            </em>
          </div>
        </div>
      </article>

      <article class="panel rank-panel">
        <header class="panel-header">
          <div>
            <p class="panel-kicker">Users</p>
            <h2>用户流量排行</h2>
          </div>

          <div class="filter-group">
            <button
              v-for="option in rankPresetOptions"
              :key="`user-${option.value}`"
              type="button"
              class="filter-pill"
              :class="{ active: option.value === rankPreset }"
              @click="rankPreset = option.value"
            >
              {{ option.label }}
            </button>
          </div>
        </header>

        <div class="panel-state" v-if="rankLoading">排行数据同步中…</div>
        <div v-if="userRanks.length" class="rank-list">
          <div v-for="(item, index) in userRanks.slice(0, 6)" :key="item.id" class="rank-item">
            <div class="rank-item__copy">
              <strong>{{ item.name }}</strong>
              <span>{{ formatTraffic(item.value) }}</span>
            </div>
            <div class="rank-item__bar">
              <span :style="{ width: rankBarWidth(index) }" />
            </div>
            <em :class="Number(item.change) >= 0 ? 'positive' : 'negative'">
              {{ formatPercent(item.change) }}
            </em>
          </div>
        </div>
      </article>
    </section>
  </div>
</template>

<style scoped>
.dashboard-page {
  display: grid;
  gap: 24px;
}

.dashboard-hero {
  display: flex;
  justify-content: space-between;
  gap: 28px;
  padding: 40px;
  border-radius: 28px;
  background: #000000;
}

.dashboard-copy {
  display: grid;
  gap: 14px;
  max-width: 620px;
}

.dashboard-kicker,
.panel-kicker {
  margin: 0;
  color: var(--xboard-text-muted);
  font-size: 11px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
}

.dashboard-copy h1 {
  margin: 0;
  font-size: clamp(42px, 5vw, 56px);
  line-height: 1.07;
  letter-spacing: -0.28px;
  color: #ffffff;
}

.dashboard-description {
  margin: 0;
  max-width: 540px;
  color: var(--xboard-text-on-dark-muted);
  line-height: 1.47;
}

.dashboard-hero-side {
  display: grid;
  gap: 16px;
  min-width: 280px;
}

.hero-status {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 12px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.12);
  color: rgba(255, 255, 255, 0.72);
}

.hero-status strong {
  color: #ffffff;
  font-size: 14px;
  font-weight: 600;
}

.hero-highlights {
  display: grid;
  gap: 14px;
}

.hero-highlights article {
  display: grid;
  gap: 4px;
}

.hero-highlights span {
  color: rgba(255, 255, 255, 0.72);
  font-size: 14px;
}

.hero-highlights strong {
  color: #ffffff;
  font-size: 28px;
  line-height: 1.1;
  letter-spacing: -0.28px;
}

.hero-meta-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.hero-meta-list span {
  border-radius: 999px;
  background: #ffffff;
  color: var(--xboard-text-secondary);
  padding: 10px 16px;
  box-shadow: var(--xboard-shadow);
}

.metrics-grid,
.content-grid,
.rank-grid {
  display: grid;
  gap: 18px;
}

.metrics-grid {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.content-grid,
.rank-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.metric-card,
.panel {
  border-radius: 22px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.metric-card {
  min-height: 150px;
  padding: 22px;
  display: grid;
  gap: 10px;
}

.metric-card__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: var(--xboard-text-secondary);
}

.metric-card__value,
.trend-stat strong,
.status-item strong,
.snapshot-card strong {
  color: var(--xboard-text-strong);
}

.metric-card__value {
  font-size: 30px;
  line-height: 1.05;
  letter-spacing: -0.28px;
}

.tone-dark {
  background: #1d1d1f;
}

.tone-dark .metric-card__meta,
.tone-dark .metric-card__detail {
  color: rgba(255, 255, 255, 0.72);
}

.tone-dark .metric-card__value {
  color: #ffffff;
}

.tone-soft {
  background: #fbfbfd;
}

.metric-card__detail,
.trend-stat p,
.status-item span,
.rank-item__copy span {
  color: var(--xboard-text-muted);
}

.metric-card__change {
  margin-top: auto;
  font-size: 13px;
}

.panel {
  padding: 28px;
}

.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 20px;
}

.panel-header h2 {
  margin: 0;
  font-size: 32px;
  line-height: 1.1;
  letter-spacing: -0.28px;
}

.panel-description {
  margin: 8px 0 0;
  color: var(--xboard-text-secondary);
}

.panel-kicker--dark,
.panel-description--dark {
  color: rgba(255, 255, 255, 0.72);
}

.filter-group {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.filter-pill {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 999px;
  background: #ffffff;
  padding: 10px 14px;
  color: var(--xboard-text-secondary);
  cursor: pointer;
}

.filter-pill.active {
  color: #0071e3;
  border-color: rgba(0, 113, 227, 0.22);
  background: rgba(0, 113, 227, 0.08);
}

.trend-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}

.trend-stat {
  border-radius: 18px;
  padding: 16px;
  background: #f5f5f7;
  display: grid;
  gap: 8px;
}

.trend-stat span {
  color: var(--xboard-text-secondary);
}

.trend-stat strong {
  font-size: 26px;
}

.chart-panel {
  border-radius: 20px;
  padding: 18px 14px 26px;
  background: #fbfbfd;
}

.trend-chart {
  width: 100%;
  height: 260px;
}

.trend-chart line {
  stroke: rgba(0, 0, 0, 0.08);
  stroke-dasharray: 4 6;
}

.trend-chart text {
  fill: var(--xboard-text-muted);
  font-size: 10px;
}

.trend-chart__area {
  fill: rgba(0, 113, 227, 0.08);
}

.trend-chart__line {
  fill: none;
  stroke: #0071e3;
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.trend-chart__dot {
  fill: #ffffff;
  stroke: rgba(0, 113, 227, 0.56);
  stroke-width: 1.5;
}

.trend-chart__dot.active {
  fill: #0071e3;
}

.trend-axis {
  position: relative;
  height: 18px;
}

.trend-axis span {
  position: absolute;
  transform: translateX(-50%);
  color: var(--xboard-text-muted);
  font-size: 10px;
}

.rank-list,
.status-grid {
  display: grid;
  gap: 14px;
}

.rank-item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 150px auto;
  gap: 14px;
  align-items: center;
}

.rank-item__copy {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.rank-item__copy strong {
  color: var(--xboard-text-strong);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rank-item__bar {
  height: 8px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.06);
  overflow: hidden;
}

.rank-item__bar span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: #0071e3;
}

.rank-item em {
  font-style: normal;
}

.status-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.status-item {
  display: grid;
  gap: 10px;
  border-radius: 16px;
  padding: 16px;
  background: rgba(0, 0, 0, 0.04);
}

.status-item strong {
  font-size: 22px;
}

.panel-dark {
  background: #000000;
  color: #ffffff;
}

.status-item--dark {
  background: rgba(255, 255, 255, 0.06);
}

.status-item--dark span {
  color: rgba(255, 255, 255, 0.68);
}

.status-item--dark strong {
  color: #ffffff;
}

.status-grid--compact {
  margin-top: 14px;
}

.snapshot-card {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-top: 16px;
}

.snapshot-card div {
  display: grid;
  gap: 6px;
  border-radius: 16px;
  background: #f5f5f7;
  padding: 14px 16px;
}

.snapshot-card span,
.panel-state {
  color: var(--xboard-text-muted);
}

.panel-state {
  margin-bottom: 14px;
  font-size: 14px;
}

.panel-state--dark {
  color: rgba(255, 255, 255, 0.68);
}

@media (max-width: 1279px) {
  .metrics-grid,
  .content-grid,
  .rank-grid,
  .trend-summary,
  .snapshot-card {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 767px) {
  .dashboard-hero {
    flex-direction: column;
    padding: 28px 24px;
  }

  .metrics-grid,
  .content-grid,
  .rank-grid,
  .status-grid,
  .trend-summary,
  .snapshot-card {
    grid-template-columns: 1fr;
  }

  .rank-item {
    grid-template-columns: 1fr;
  }
}
</style>
