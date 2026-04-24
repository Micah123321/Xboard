import type {
  AdminOrderDetail,
  AdminOrderFilter,
  AdminOrderListItem,
  AdminPlanListItem,
} from '@/types/api'
import { formatPlanPrice } from './plans'

export type OrderFilterValue<T> = T | 'all'
export type OrderPeriodKey =
  | 'monthly'
  | 'quarterly'
  | 'half_yearly'
  | 'yearly'
  | 'two_yearly'
  | 'three_yearly'
  | 'onetime'
  | 'reset_traffic'

export type OrderLegacyPeriodKey =
  | 'month_price'
  | 'quarter_price'
  | 'half_year_price'
  | 'year_price'
  | 'two_year_price'
  | 'three_year_price'
  | 'onetime_price'
  | 'reset_price'

export interface OrderFilterOption<T extends string | number> {
  label: string
  value: T
}

export interface OrderStatusMeta {
  label: string
  tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral'
}

export interface AssignablePeriodOption {
  label: string
  value: OrderLegacyPeriodKey
  amount: number
}

const CURRENCY_FORMATTER = new Intl.NumberFormat('zh-CN', {
  style: 'currency',
  currency: 'CNY',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const FULL_DATE_FORMATTER = new Intl.DateTimeFormat('zh-CN', {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
})

const PERIOD_META: Array<{
  key: OrderPeriodKey
  legacy: OrderLegacyPeriodKey
  label: string
}> = [
  { key: 'monthly', legacy: 'month_price', label: '月付' },
  { key: 'quarterly', legacy: 'quarter_price', label: '季付' },
  { key: 'half_yearly', legacy: 'half_year_price', label: '半年付' },
  { key: 'yearly', legacy: 'year_price', label: '年付' },
  { key: 'two_yearly', legacy: 'two_year_price', label: '两年付' },
  { key: 'three_yearly', legacy: 'three_year_price', label: '三年付' },
  { key: 'onetime', legacy: 'onetime_price', label: '一次性' },
  { key: 'reset_traffic', legacy: 'reset_price', label: '重置流量' },
]

export const ORDER_TYPE_OPTIONS: Array<OrderFilterOption<number>> = [
  { label: '新购', value: 1 },
  { label: '续费', value: 2 },
  { label: '升级', value: 3 },
  { label: '流量重置', value: 4 },
]

export const ORDER_STATUS_OPTIONS: Array<OrderFilterOption<number>> = [
  { label: '待支付', value: 0 },
  { label: '开通中', value: 1 },
  { label: '已取消', value: 2 },
  { label: '已完成', value: 3 },
  { label: '已折抵', value: 4 },
]

export const COMMISSION_STATUS_OPTIONS: Array<OrderFilterOption<number>> = [
  { label: '待确认', value: 0 },
  { label: '发放中', value: 1 },
  { label: '已发放', value: 2 },
  { label: '无效', value: 3 },
]

export const COMMISSION_STATUS_UPDATE_OPTIONS: Array<OrderFilterOption<number>> = [
  { label: '待确认', value: 0 },
  { label: '发放中', value: 1 },
  { label: '无效', value: 3 },
]

export const ORDER_PERIOD_OPTIONS: Array<OrderFilterOption<OrderPeriodKey>> = PERIOD_META.map((item) => ({
  label: item.label,
  value: item.key,
}))

function toAmount(value: unknown): number {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : 0
}

function toTimestampMilliseconds(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const numeric = Number(value)
  if (Number.isFinite(numeric)) {
    return numeric > 1_000_000_000_000 ? numeric : numeric * 1000
  }

  const parsed = Date.parse(String(value))
  return Number.isFinite(parsed) ? parsed : null
}

function findPeriodMeta(period: string | null | undefined) {
  if (!period) {
    return null
  }

  return PERIOD_META.find((item) => item.key === period || item.legacy === period) ?? null
}

function getOptionLabel<T extends string | number>(options: Array<OrderFilterOption<T>>, value: T | 'all'): string {
  if (value === 'all') {
    return '全部'
  }

  return options.find((item) => item.value === value)?.label ?? '全部'
}

export function formatOrderAmount(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return '-'
  }

  const numeric = Number(value)
  if (!Number.isFinite(numeric)) {
    return '-'
  }

  return CURRENCY_FORMATTER.format(numeric / 100)
}

export function formatOrderDateTime(value: number | string | null | undefined): string {
  const timestamp = toTimestampMilliseconds(value)
  if (timestamp === null) {
    return '-'
  }

  return FULL_DATE_FORMATTER.format(new Date(timestamp))
}

export function yuanToOrderAmount(value: number | null | undefined): number {
  const numeric = Number(value)
  if (!Number.isFinite(numeric) || numeric < 0) {
    return 0
  }

  return Math.round(numeric * 100)
}

export function orderAmountToYuan(value: number | null | undefined): number {
  const numeric = Number(value)
  if (!Number.isFinite(numeric)) {
    return 0
  }

  return Number((numeric / 100).toFixed(2))
}

export function getOrderTypeMeta(type: number | null | undefined): OrderStatusMeta {
  return {
    label: getOptionLabel(ORDER_TYPE_OPTIONS, (type ?? 'all') as number | 'all'),
    tone: type === 4 ? 'info' : type === 3 ? 'warning' : 'neutral',
  }
}

export function getOrderStatusMeta(status: number | null | undefined): OrderStatusMeta {
  switch (status) {
    case 0:
      return { label: '待支付', tone: 'warning' }
    case 1:
      return { label: '开通中', tone: 'info' }
    case 2:
      return { label: '已取消', tone: 'danger' }
    case 3:
      return { label: '已完成', tone: 'success' }
    case 4:
      return { label: '已折抵', tone: 'neutral' }
    default:
      return { label: '未知状态', tone: 'neutral' }
  }
}

export function getCommissionStatusMeta(status: number | null | undefined, amount?: number | null): OrderStatusMeta {
  if ((amount ?? 0) <= 0 && (status === null || status === undefined)) {
    return { label: '-', tone: 'neutral' }
  }

  switch (status) {
    case 0:
      return { label: '待确认', tone: 'warning' }
    case 1:
      return { label: '发放中', tone: 'info' }
    case 2:
      return { label: '已发放', tone: 'success' }
    case 3:
      return { label: '无效', tone: 'danger' }
    default:
      return { label: '未参与', tone: 'neutral' }
  }
}

export function getOrderPeriodLabel(period: string | null | undefined): string {
  return findPeriodMeta(period)?.label ?? (period || '-')
}

export function getOrderFilterLabel(type: OrderFilterValue<number>): string {
  return getOptionLabel(ORDER_TYPE_OPTIONS, type)
}

export function getOrderStatusFilterLabel(status: OrderFilterValue<number>): string {
  return getOptionLabel(ORDER_STATUS_OPTIONS, status)
}

export function getCommissionStatusFilterLabel(status: OrderFilterValue<number>): string {
  return getOptionLabel(COMMISSION_STATUS_OPTIONS, status)
}

export function getOrderPeriodFilterLabel(period: OrderFilterValue<OrderPeriodKey>): string {
  return getOptionLabel(ORDER_PERIOD_OPTIONS, period)
}

export function buildOrderFetchFilters(filters: {
  keyword: string
  type: OrderFilterValue<number>
  period: OrderFilterValue<OrderPeriodKey>
  status: OrderFilterValue<number>
  commissionStatus: OrderFilterValue<number>
}): AdminOrderFilter[] {
  const result: AdminOrderFilter[] = []

  if (filters.keyword.trim()) {
    result.push({
      id: 'trade_no',
      value: filters.keyword.trim(),
    })
  }

  if (filters.type !== 'all') {
    result.push({
      id: 'type',
      value: [filters.type],
    })
  }

  if (filters.period !== 'all') {
    result.push({
      id: 'period',
      value: [filters.period],
    })
  }

  if (filters.status !== 'all') {
    result.push({
      id: 'status',
      value: [filters.status],
    })
  }

  if (filters.commissionStatus !== 'all') {
    result.push({
      id: 'commission_status',
      value: [filters.commissionStatus],
    })
  }

  return result
}

export function getAssignablePeriods(plan?: Pick<AdminPlanListItem, 'prices'> | null): AssignablePeriodOption[] {
  if (!plan?.prices) {
    return []
  }

  return PERIOD_META
    .filter((item) => toAmount(plan.prices?.[item.key]) > 0)
    .map((item) => ({
      label: `${item.label} · ${formatPlanPrice(plan.prices?.[item.key])}`,
      value: item.legacy,
      amount: Number(plan.prices?.[item.key] ?? 0),
    }))
}

export function canMarkOrderPaid(order?: Pick<AdminOrderListItem, 'status'> | null): boolean {
  return order?.status === 0
}

export function canCancelOrder(order?: Pick<AdminOrderListItem, 'status'> | null): boolean {
  return order?.status === 0
}

export function canUpdateCommissionStatus(order?: Pick<AdminOrderDetail, 'commission_balance' | 'commission_status'> | null): boolean {
  if (!order) {
    return false
  }

  if ((order.commission_balance ?? 0) <= 0) {
    return false
  }

  return order.commission_status !== 2
}
