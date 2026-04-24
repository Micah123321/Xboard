<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import type { Component } from 'vue'
import { ElMessage } from 'element-plus'
import {
  Coin,
  DataAnalysis,
  Discount,
  Download,
  RefreshRight,
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
  formatCountLabel,
  formatCompactNumber,
  formatCurrency,
  formatDateTime,
  formatPercent,
  formatTraffic,
  getDateRangeFromPreset,
  getQueueWaitName,
  getQueueWaitSeconds,
  type TrendMetric,
  type TimePreset,
} from '@/utils/dashboard'
import { useAppStore } from '@/stores/app'
import QueueFailedJobsDialog from './QueueFailedJobsDialog.vue'

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
const lastRefreshedAt = ref<string | null>(null)
const trendPreset = ref<TimePreset>('30d')
const trendMetric = ref<TrendMetric>('amount')
const rankPreset = ref<TimePreset>('1d')

const overview = ref<DashboardStats | null>(null)
const trendList = ref<OrderTrendPoint[]>([])
const trendSummary = ref<OrderTrendSummary | null>(null)
const nodeRanks = ref<TrafficRankItem[]>([])
const userRanks = ref<TrafficRankItem[]>([])
const systemStatus = ref<SystemStatus | null>(null)
const queueStats = ref<QueueStats | null>(null)
const failedJobsDialogVisible = ref(false)

const trendPresetOptions = [
  { label: '7天', value: '7d' },
  { label: '30天', value: '30d' },
  { label: '90天', value: '90d' },
] as const

const trendMetricOptions = [
  { label: '按金额', value: 'amount' },
  { label: '按数量', value: 'count' },
] as const

const rankPresetOptions = [
  { label: '24h', value: '1d' },
  { label: '7天', value: '7d' },
  { label: '30天', value: '30d' },
] as const

const rankDisplayOptions = [
  { label: '10个', value: 10 },
  { label: '20个', value: 20 },
] as const

type RankDisplayCount = (typeof rankDisplayOptions)[number]['value']

const nodeRankLimit = ref<RankDisplayCount>(10)
const userRankLimit = ref<RankDisplayCount>(10)

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

const refreshButtonDisabled = computed(() => (
  booting.value
  || trendLoading.value
  || rankLoading.value
  || systemLoading.value
))

const refreshStatusText = computed(() => {
  if (booting.value) return '正在同步全部数据'
  return '数据已同步'
})

const refreshStatusMeta = computed(() => {
  if (booting.value) return '统计、趋势、排行与系统状态正在刷新'
  if (!lastRefreshedAt.value) return '首次加载完成后可再次刷新'
  return `上次刷新 ${formatDateTime(lastRefreshedAt.value)}`
})

const trendChart = computed(() => buildTrendChart(trendList.value, {
  metric: trendMetric.value,
}))

const trendAverageCount = computed(() => {
  if (!trendList.value.length) return 0
  const total = trendList.value.reduce((sum, point) => sum + point.paid_count, 0)
  return total / trendList.value.length
})

const trendPeakCount = computed(() => {
  if (!trendList.value.length) return 0
  return Math.max(...trendList.value.map((point) => point.paid_count))
})

const trendSummaryCards = computed(() => {
  const summary = trendSummary.value
  if (!summary) {
    return trendMetric.value === 'count'
      ? [
          { label: '成交订单', value: formatCountLabel(0), detail: '总成交额 ¥0.00' },
          { label: '佣金订单', value: formatCountLabel(0), detail: '占成交 0.0%' },
          { label: '日均成交', value: formatCountLabel(0), detail: '峰值 0 笔' },
        ]
      : [
          { label: '成交总额', value: formatCurrency(0), detail: '共 0 笔' },
          { label: '佣金支出', value: formatCurrency(0), detail: '佣金率 0.0%' },
          { label: '订单均价', value: formatCurrency(0), detail: '单笔均值' },
        ]
  }

  if (trendMetric.value === 'count') {
    const commissionShare = summary.paid_count
      ? (summary.commission_count / summary.paid_count) * 100
      : 0

    return [
      {
        label: '成交订单',
        value: formatCountLabel(summary.paid_count),
        detail: `总成交额 ${formatCurrency(summary.paid_total)}`,
      },
      {
        label: '佣金订单',
        value: formatCountLabel(summary.commission_count),
        detail: `占成交 ${formatPercent(commissionShare, false)}`,
      },
      {
        label: '日均成交',
        value: formatCountLabel(trendAverageCount.value),
        detail: `峰值 ${formatCountLabel(trendPeakCount.value)}`,
      },
    ]
  }

  return [
    {
      label: '成交总额',
      value: formatCurrency(summary.paid_total),
      detail: `共 ${formatCompactNumber(summary.paid_count)} 笔`,
    },
    {
      label: '佣金支出',
      value: formatCurrency(summary.commission_total),
      detail: `佣金率 ${formatPercent(summary.commission_rate ?? 0, false)}`,
    },
    {
      label: '订单均价',
      value: formatCurrency(summary.avg_paid_amount),
      detail: '单笔均值',
    },
  ]
})

const latestTrendPoint = computed(() => {
  if (!trendList.value.length) return null
  return trendList.value[trendList.value.length - 1]
})

const trendSnapshot = computed(() => {
  const point = latestTrendPoint.value
  if (!point) return null

  return {
    date: point.date,
    items: trendMetric.value === 'count'
      ? [
          { label: '成交订单', value: formatCountLabel(point.paid_count) },
          { label: '佣金订单', value: formatCountLabel(point.commission_count) },
          { label: '成交总额', value: formatCurrency(point.paid_total) },
        ]
      : [
          { label: '收入', value: formatCurrency(point.paid_total) },
          { label: '佣金', value: formatCurrency(point.commission_total) },
          { label: '订单', value: formatCountLabel(point.paid_count) },
        ],
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

const displayedNodeRanks = computed(() => nodeRanks.value.slice(0, nodeRankLimit.value))
const displayedUserRanks = computed(() => userRanks.value.slice(0, userRankLimit.value))

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
        limit: nodeRankLimit.value,
      }),
      getTrafficRank({
        type: 'user',
        startTime: range.startTime,
        endTime: range.endTime,
        limit: userRankLimit.value,
      }),
    ])

    nodeRanks.value = nodeResponse.data
    userRanks.value = userResponse.data
  } finally {
    rankLoading.value = false
  }
}

async function refreshDashboard(options: { silentSuccess?: boolean } = {}) {
  booting.value = true
  try {
    const results = await Promise.allSettled([
      loadOverviewPanels(),
      loadTrend(),
      loadRankings(),
    ])

    if (results.some((item) => item.status === 'rejected')) {
      ElMessage.error('部分仪表盘数据加载失败，请稍后重试')
      return
    }

    lastRefreshedAt.value = new Date().toISOString()
    if (!options.silentSuccess) {
      ElMessage.success('仪表盘数据已刷新')
    }
  } finally {
    booting.value = false
  }
}

function handleRefresh() {
  if (refreshButtonDisabled.value) return
  void refreshDashboard()
}

function rankBarWidth(index: number): string {
  return `${Math.max(28, 100 - index * 12)}%`
}

function rankScrollClass(limit: RankDisplayCount): string {
  return limit === 20 ? 'rank-scroll rank-scroll--extended' : 'rank-scroll'
}

function rankChangeClass(change: number): string {
  if (Number(change) > 0) return 'positive'
  if (Number(change) < 0) return 'negative'
  return 'neutral'
}

watch(trendPreset, () => {
  void loadTrend().catch(() => ElMessage.error('趋势数据刷新失败'))
})

watch([rankPreset, nodeRankLimit, userRankLimit], () => {
  void loadRankings().catch(() => ElMessage.error('排行数据刷新失败'))
})

onMounted(() => {
  void refreshDashboard({ silentSuccess: true })
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
          <div class="hero-status__copy">
            <span>{{ refreshStatusText }}</span>
            <strong>/{{ app.securePath || 'admin' }}</strong>
            <p>{{ refreshStatusMeta }}</p>
          </div>

          <button
            type="button"
            class="dashboard-refresh-button"
            :disabled="refreshButtonDisabled"
            @click="handleRefresh"
          >
            <ElIcon class="dashboard-refresh-button__icon" :class="{ spinning: booting }">
              <RefreshRight />
            </ElIcon>
            <span>{{ booting ? '正在刷新全部数据' : '刷新全部数据' }}</span>
          </button>
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

          <div class="panel-actions">
            <div class="filter-group filter-group--segmented" aria-label="趋势口径切换">
              <button
                v-for="option in trendMetricOptions"
                :key="option.value"
                type="button"
                class="filter-pill"
                :class="{ active: option.value === trendMetric }"
                :aria-pressed="option.value === trendMetric"
                @click="trendMetric = option.value"
              >
                {{ option.label }}
              </button>
            </div>

            <div class="filter-group">
              <button
                v-for="option in trendPresetOptions"
                :key="option.value"
                type="button"
                class="filter-pill"
                :class="{ active: option.value === trendPreset }"
                :aria-pressed="option.value === trendPreset"
                @click="trendPreset = option.value"
              >
                {{ option.label }}
              </button>
            </div>
          </div>
        </header>

        <div class="trend-summary">
          <article
            v-for="card in trendSummaryCards"
            :key="card.label"
            class="trend-stat"
          >
            <span>{{ card.label }}</span>
            <strong>{{ card.value }}</strong>
            <p>{{ card.detail }}</p>
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
          <div
            v-for="item in trendSnapshot.items"
            :key="item.label"
          >
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
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

          <div class="panel-actions panel-actions--dark">
            <button
              type="button"
              class="system-action-button"
              aria-haspopup="dialog"
              @click="failedJobsDialogVisible = true"
            >
              查看报错详情
            </button>
            <span class="system-panel__meta">
              当前失败 {{ formatCompactNumber(queueStats?.failedJobs ?? 0) }} 条
            </span>
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

          <div class="panel-actions">
            <div class="filter-group">
              <button
                v-for="option in rankPresetOptions"
                :key="`node-${option.value}`"
                type="button"
                class="filter-pill"
                :class="{ active: option.value === rankPreset }"
                :aria-pressed="option.value === rankPreset"
                @click="rankPreset = option.value"
              >
                {{ option.label }}
              </button>
            </div>

            <div class="filter-group filter-group--segmented" aria-label="节点排行显示数量">
              <button
                v-for="option in rankDisplayOptions"
                :key="`node-limit-${option.value}`"
                type="button"
                class="filter-pill"
                :class="{ active: option.value === nodeRankLimit }"
                :aria-pressed="option.value === nodeRankLimit"
                @click="nodeRankLimit = option.value"
              >
                {{ option.label }}
              </button>
            </div>
          </div>
        </header>

        <div class="panel-state" v-if="rankLoading">排行数据同步中…</div>
        <div
          v-else-if="nodeRanks.length"
          :class="rankScrollClass(nodeRankLimit)"
        >
          <div class="rank-list">
            <ElTooltip
              v-for="(item, index) in displayedNodeRanks"
              :key="item.id"
              placement="top-end"
              :show-after="80"
              popper-class="dashboard-rank-tooltip-popper"
            >
              <template #content>
                <div class="rank-tooltip">
                  <div class="rank-tooltip__row">
                    <span>当前流量</span>
                    <strong>{{ formatTraffic(item.value) }}</strong>
                  </div>
                  <div class="rank-tooltip__row">
                    <span>上期流量</span>
                    <strong>{{ formatTraffic(item.previousValue) }}</strong>
                  </div>
                  <div class="rank-tooltip__row">
                    <span>变化率</span>
                    <strong :class="rankChangeClass(item.change)">{{ formatPercent(item.change) }}</strong>
                  </div>
                </div>
              </template>

              <div class="rank-item">
                <div class="rank-item__copy">
                  <strong>{{ item.name }}</strong>
                </div>
                <div class="rank-item__bar">
                  <span :style="{ width: rankBarWidth(index) }" />
                </div>
                <div class="rank-item__meta">
                  <em :class="rankChangeClass(item.change)">
                    {{ formatPercent(item.change) }}
                  </em>
                  <span class="rank-item__value">{{ formatTraffic(item.value) }}</span>
                </div>
              </div>
            </ElTooltip>
          </div>
        </div>
        <div v-else class="panel-state">暂无节点排行数据</div>
      </article>

      <article class="panel rank-panel">
        <header class="panel-header">
          <div>
            <p class="panel-kicker">Users</p>
            <h2>用户流量排行</h2>
          </div>

          <div class="panel-actions">
            <div class="filter-group">
              <button
                v-for="option in rankPresetOptions"
                :key="`user-${option.value}`"
                type="button"
                class="filter-pill"
                :class="{ active: option.value === rankPreset }"
                :aria-pressed="option.value === rankPreset"
                @click="rankPreset = option.value"
              >
                {{ option.label }}
              </button>
            </div>

            <div class="filter-group filter-group--segmented" aria-label="用户排行显示数量">
              <button
                v-for="option in rankDisplayOptions"
                :key="`user-limit-${option.value}`"
                type="button"
                class="filter-pill"
                :class="{ active: option.value === userRankLimit }"
                :aria-pressed="option.value === userRankLimit"
                @click="userRankLimit = option.value"
              >
                {{ option.label }}
              </button>
            </div>
          </div>
        </header>

        <div class="panel-state" v-if="rankLoading">排行数据同步中…</div>
        <div
          v-else-if="userRanks.length"
          :class="rankScrollClass(userRankLimit)"
        >
          <div class="rank-list">
            <ElTooltip
              v-for="(item, index) in displayedUserRanks"
              :key="item.id"
              placement="top-end"
              :show-after="80"
              popper-class="dashboard-rank-tooltip-popper"
            >
              <template #content>
                <div class="rank-tooltip">
                  <div class="rank-tooltip__row">
                    <span>当前流量</span>
                    <strong>{{ formatTraffic(item.value) }}</strong>
                  </div>
                  <div class="rank-tooltip__row">
                    <span>上期流量</span>
                    <strong>{{ formatTraffic(item.previousValue) }}</strong>
                  </div>
                  <div class="rank-tooltip__row">
                    <span>变化率</span>
                    <strong :class="rankChangeClass(item.change)">{{ formatPercent(item.change) }}</strong>
                  </div>
                </div>
              </template>

              <div class="rank-item">
                <div class="rank-item__copy">
                  <strong>{{ item.name }}</strong>
                </div>
                <div class="rank-item__bar">
                  <span :style="{ width: rankBarWidth(index) }" />
                </div>
                <div class="rank-item__meta">
                  <em :class="rankChangeClass(item.change)">
                    {{ formatPercent(item.change) }}
                  </em>
                  <span class="rank-item__value">{{ formatTraffic(item.value) }}</span>
                </div>
              </div>
            </ElTooltip>
          </div>
        </div>
        <div v-else class="panel-state">暂无用户排行数据</div>
      </article>
    </section>

    <QueueFailedJobsDialog v-model:visible="failedJobsDialogVisible" />
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
  align-items: flex-start;
  gap: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.12);
  color: rgba(255, 255, 255, 0.72);
}

.hero-status__copy {
  display: grid;
  gap: 6px;
}

.hero-status strong {
  color: #ffffff;
  font-size: 14px;
  font-weight: 600;
}

.hero-status__copy p {
  margin: 0;
  font-size: 13px;
  color: rgba(255, 255, 255, 0.56);
}

.dashboard-refresh-button {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  border: 1px solid rgba(255, 255, 255, 0.16);
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  color: #ffffff;
  padding: 11px 18px;
  min-height: 44px;
  cursor: pointer;
  transition: transform 180ms ease, background-color 180ms ease, border-color 180ms ease;
}

.dashboard-refresh-button:hover:not(:disabled) {
  transform: translateY(-1px);
  background: rgba(255, 255, 255, 0.12);
  border-color: rgba(255, 255, 255, 0.24);
}

.dashboard-refresh-button:focus-visible {
  outline: 2px solid rgba(0, 113, 227, 0.88);
  outline-offset: 2px;
}

.dashboard-refresh-button:disabled {
  cursor: wait;
  opacity: 0.78;
}

.dashboard-refresh-button__icon {
  font-size: 15px;
}

.dashboard-refresh-button__icon.spinning {
  animation: dashboard-refresh-spin 0.9s linear infinite;
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

.panel-actions {
  display: grid;
  justify-items: end;
  gap: 10px;
}

.panel-actions--dark {
  align-items: end;
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

.system-action-button {
  border: 1px solid rgba(255, 255, 255, 0.16);
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  color: #ffffff;
  padding: 10px 16px;
  cursor: pointer;
  transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.18s ease;
}

.system-action-button:hover {
  background: rgba(255, 255, 255, 0.14);
  border-color: rgba(255, 255, 255, 0.24);
}

.system-action-button:focus-visible {
  outline: 2px solid rgba(41, 151, 255, 0.72);
  outline-offset: 2px;
}

.system-action-button:active {
  transform: translateY(1px);
}

.system-panel__meta {
  color: rgba(255, 255, 255, 0.72);
  font-size: 13px;
}

.filter-group {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.filter-group--segmented {
  padding: 4px;
  border-radius: 999px;
  background: #f5f5f7;
}

.filter-pill {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 999px;
  background: #ffffff;
  padding: 10px 14px;
  color: var(--xboard-text-secondary);
  cursor: pointer;
  transition: border-color 0.18s ease, background-color 0.18s ease, color 0.18s ease;
}

.filter-pill.active {
  color: #0071e3;
  border-color: rgba(0, 113, 227, 0.22);
  background: rgba(0, 113, 227, 0.08);
}

.filter-pill:focus-visible {
  outline: 2px solid rgba(0, 113, 227, 0.36);
  outline-offset: 2px;
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

.rank-scroll {
  max-height: 368px;
  overflow-y: auto;
  padding-right: 6px;
  margin-right: -6px;
  scrollbar-gutter: stable;
  overscroll-behavior: contain;
  scrollbar-width: thin;
  scrollbar-color: rgba(0, 113, 227, 0.22) transparent;
}

.rank-scroll--extended {
  max-height: 516px;
}

.rank-scroll::-webkit-scrollbar {
  width: 8px;
}

.rank-scroll::-webkit-scrollbar-track {
  background: transparent;
}

.rank-scroll::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: rgba(0, 113, 227, 0.22);
}

.rank-item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 150px auto;
  gap: 14px;
  align-items: center;
  cursor: default;
}

.rank-item__copy {
  display: flex;
  align-items: center;
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
  font-size: 14px;
  font-weight: 600;
}

.rank-item__meta {
  display: grid;
  gap: 4px;
  justify-items: end;
  text-align: right;
}

.rank-item__value {
  color: var(--xboard-text-strong);
  font-size: 15px;
  font-weight: 600;
}

.rank-tooltip {
  display: grid;
  gap: 10px;
  min-width: 188px;
}

.rank-tooltip__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
}

.rank-tooltip__row span {
  color: rgba(255, 255, 255, 0.68);
  font-size: 12px;
}

.rank-tooltip__row strong {
  color: #ffffff;
  font-size: 13px;
  font-weight: 600;
}

:global(.dashboard-rank-tooltip-popper) {
  border: 1px solid rgba(255, 255, 255, 0.08) !important;
  border-radius: 16px !important;
  background: rgba(6, 12, 24, 0.94) !important;
  box-shadow: 0 18px 48px rgba(15, 23, 42, 0.28) !important;
  backdrop-filter: blur(18px);
}

:global(.dashboard-rank-tooltip-popper .el-popper__arrow::before) {
  background: rgba(6, 12, 24, 0.94) !important;
  border-color: rgba(255, 255, 255, 0.08) !important;
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

  .hero-status,
  .panel-actions {
    width: 100%;
  }

  .hero-status {
    flex-direction: column;
  }

  .dashboard-refresh-button {
    width: 100%;
    justify-content: center;
  }

  .rank-scroll,
  .rank-scroll--extended {
    max-height: 460px;
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

  .rank-item__meta {
    justify-items: start;
    text-align: left;
  }
}

@keyframes dashboard-refresh-spin {
  from {
    transform: rotate(0deg);
  }

  to {
    transform: rotate(360deg);
  }
}
</style>
