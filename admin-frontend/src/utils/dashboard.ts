import type {
  OrderTrendPoint,
  QueueStats,
  QueueWaitEntry,
} from '@/types/api'

export type TimePreset = '1d' | '7d' | '30d' | '90d'

export interface DateRangePreset {
  startDate: string
  endDate: string
  startTime: number
  endTime: number
}

export interface ChartLabelPoint {
  index: number
  label: string
  xPercent: number
}

export interface TrendChartPoint {
  index: number
  x: number
  y: number
  value: number
  date: string
  label: string
}

export interface TrendChartModel {
  path: string
  areaPath: string
  points: TrendChartPoint[]
  gridLines: Array<{ label: string; y: number }>
  labels: ChartLabelPoint[]
}

export type TrendMetric = 'amount' | 'count'

const TREND_WIDTH = 760
const TREND_HEIGHT = 260
const PADDING_X = 24
const PADDING_TOP = 18
const PADDING_BOTTOM = 34

function formatDateToken(date: Date): string {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
  ].join('-')
}

function toNumber(value: unknown): number {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : 0
}

export function getDateRangeFromPreset(preset: TimePreset): DateRangePreset {
  const days = { '1d': 1, '7d': 7, '30d': 30, '90d': 90 }[preset]
  const end = new Date()
  end.setHours(23, 59, 59, 999)

  const start = new Date(end)
  start.setDate(start.getDate() - (days - 1))
  start.setHours(0, 0, 0, 0)

  return {
    startDate: formatDateToken(start),
    endDate: formatDateToken(end),
    startTime: Math.floor(start.getTime() / 1000),
    endTime: Math.floor(end.getTime() / 1000),
  }
}

export function formatCurrency(value: number): string {
  const amount = (value || 0) / 100
  return new Intl.NumberFormat('zh-CN', {
    style: 'currency',
    currency: 'CNY',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount)
}

export function formatCompactCurrency(value: number): string {
  const amount = (value || 0) / 100
  const absolute = Math.abs(amount)
  if (absolute >= 1_000_000) return `¥${(amount / 1_000_000).toFixed(1)}m`
  if (absolute >= 1_000) return `¥${(amount / 1_000).toFixed(1)}k`
  return formatCurrency(value)
}

export function formatCompactNumber(value: number): string {
  return new Intl.NumberFormat('zh-CN', {
    notation: value >= 10_000 ? 'compact' : 'standard',
    maximumFractionDigits: value >= 10_000 ? 1 : 0,
  }).format(value || 0)
}

export function formatCountLabel(value: number): string {
  return `${formatCompactNumber(Math.max(0, Math.round(value || 0)))} 笔`
}

export function formatTraffic(bytes: number): string {
  const value = Math.max(0, bytes || 0)
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let size = value
  let index = 0

  while (size >= 1024 && index < units.length - 1) {
    size /= 1024
    index += 1
  }

  const digits = size >= 100 || index === 0 ? 0 : size >= 10 ? 1 : 2
  return `${size.toFixed(digits)} ${units[index]}`
}

export function formatPercent(value: number, signed: boolean = true): string {
  const numeric = Number.isFinite(value) ? value : 0
  const prefix = signed && numeric > 0 ? '+' : ''
  return `${prefix}${numeric.toFixed(1)}%`
}

export function formatDateTime(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return 'N/A'
  }

  const numeric = Number(value)
  const timeValue = Number.isFinite(numeric)
    ? (numeric > 1_000_000_000_000 ? numeric : numeric * 1000)
    : Date.parse(String(value))

  if (!Number.isFinite(timeValue)) {
    return 'N/A'
  }

  return new Intl.DateTimeFormat('zh-CN', {
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(timeValue))
}

function getVisibleLabels(points: TrendChartPoint[]): ChartLabelPoint[] {
  if (points.length <= 1) {
    return points.map((point) => ({
      index: point.index,
      label: point.label,
      xPercent: 50,
    }))
  }

  const step = Math.max(1, Math.ceil(points.length / 6))
  return points
    .filter((_, index) => index === 0 || index === points.length - 1 || index % step === 0)
    .map((point) => ({
      index: point.index,
      label: point.label,
      xPercent: ((point.x - PADDING_X) / (TREND_WIDTH - PADDING_X * 2)) * 100,
    }))
}

export function buildTrendChart(
  points: OrderTrendPoint[],
  options: { metric?: TrendMetric } = {},
): TrendChartModel {
  if (!points.length) {
    return {
      path: '',
      areaPath: '',
      points: [],
      gridLines: [],
      labels: [],
    }
  }

  const metric = options.metric ?? 'amount'
  const values = points.map((point) => {
    const value = metric === 'count' ? point.paid_count : point.paid_total
    return Math.max(0, toNumber(value))
  })
  const maxValue = Math.max(...values, 1)
  const innerWidth = TREND_WIDTH - PADDING_X * 2
  const innerHeight = TREND_HEIGHT - PADDING_TOP - PADDING_BOTTOM
  const stepX = values.length > 1 ? innerWidth / (values.length - 1) : innerWidth / 2

  const chartPoints = points.map((point, index) => {
    const normalized = maxValue === 0 ? 0 : values[index] / maxValue
    return {
      index,
      value: values[index],
      date: point.date,
      label: point.date.slice(5),
      x: PADDING_X + stepX * index,
      y: PADDING_TOP + innerHeight - normalized * innerHeight,
    }
  })

  const path = chartPoints
    .map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`)
    .join(' ')

  const areaPath = chartPoints.length
    ? `${path} L ${chartPoints[chartPoints.length - 1].x.toFixed(2)} ${(TREND_HEIGHT - PADDING_BOTTOM).toFixed(2)} L ${chartPoints[0].x.toFixed(2)} ${(TREND_HEIGHT - PADDING_BOTTOM).toFixed(2)} Z`
    : ''

  const gridLines = [1, 0.75, 0.5, 0.25, 0].map((ratio) => ({
    label: metric === 'count'
      ? formatCountLabel(maxValue * ratio)
      : formatCompactCurrency(maxValue * ratio),
    y: PADDING_TOP + innerHeight - innerHeight * ratio,
  }))

  return {
    path,
    areaPath,
    points: chartPoints,
    gridLines,
    labels: getVisibleLabels(chartPoints),
  }
}

function extractQueueWaitEntry(wait?: QueueWaitEntry[]): QueueWaitEntry | null {
  if (!Array.isArray(wait) || !wait.length) {
    return null
  }

  return wait[0]
}

export function getQueueWaitSeconds(queueStats: QueueStats | null): number | null {
  const waitEntry = extractQueueWaitEntry(queueStats?.wait)
  if (!waitEntry) {
    return null
  }

  const value = toNumber(waitEntry.wait ?? waitEntry.value ?? waitEntry.time)
  return Number.isFinite(value) ? value : null
}

export function getQueueWaitName(queueStats: QueueStats | null): string {
  const waitEntry = extractQueueWaitEntry(queueStats?.wait)
  if (!waitEntry) {
    return 'N/A'
  }

  const queueName = waitEntry.name ?? waitEntry.queue ?? waitEntry.label
  return typeof queueName === 'string' && queueName ? queueName : 'N/A'
}
