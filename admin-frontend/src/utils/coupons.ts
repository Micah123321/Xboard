import type {
  AdminCouponGeneratePayload,
  AdminCouponListItem,
  AdminCouponType,
} from '@/types/api'

export type CouponTypeFilter = 'all' | `${AdminCouponType}`
export type CouponSortKey = 'id' | 'type' | 'limit_use' | 'limit_use_with_user' | 'ended_at'
export type CouponSortOrder = 'ascending' | 'descending' | null

export interface CouponFormModel {
  id?: number
  name: string
  generateCount: number | null
  code: string
  type: AdminCouponType
  value: number | null
  dateRange: [number, number] | []
  limitUse: number | null
  limitUseWithUser: number | null
  limitPlanIds: number[]
  limitPeriod: string[]
}

export interface CouponExpiryMeta {
  text: string
  kind: 'danger' | 'success' | 'info'
}

const SECONDS_PER_DAY = 24 * 60 * 60
const MAX_COUPON_TIMESTAMP = 2147483647
const DEFAULT_COUPON_VALIDITY_DAYS = 30

type CouponDateValue = Date | string | number

export const COUPON_TYPE_OPTIONS: Array<{
  label: string
  shortLabel: string
  value: AdminCouponType
}> = [
  { label: '按金额减免', shortLabel: '金额减免', value: 1 },
  { label: '按比例减免', shortLabel: '比例减免', value: 2 },
]

export const COUPON_PERIOD_OPTIONS = [
  { label: '月付', value: 'month_price' },
  { label: '季付', value: 'quarter_price' },
  { label: '半年付', value: 'half_year_price' },
  { label: '年付', value: 'year_price' },
  { label: '两年付', value: 'two_year_price' },
  { label: '三年付', value: 'three_year_price' },
  { label: '一次性', value: 'onetime_price' },
  { label: '重置流量', value: 'reset_price' },
] as const

function clampNumber(value: number | null): number | null {
  if (value === null) {
    return null
  }

  if (!Number.isFinite(Number(value))) {
    return null
  }
  return Number(value)
}

function roundCurrencyToCent(value: number | null): number {
  return Math.round(Number(value || 0) * 100)
}

function formatDatePart(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day} ${hours}:${minutes}`
}

function normalizeTimestampToDate(timestamp: number | null | undefined): Date | null {
  const value = Number(timestamp || 0)
  if (!Number.isFinite(value) || value <= 0) {
    return null
  }
  return new Date(Math.floor(value) * 1000)
}

function normalizeTimestampToPickerValue(timestamp: number | null | undefined): number {
  const value = Number(timestamp || 0)
  if (!Number.isFinite(value) || value <= 0) {
    return 0
  }
  return Math.floor(value) * 1000
}

function normalizePickerTimestamp(value: CouponDateValue | undefined): number {
  const numeric = value instanceof Date ? value.getTime() / 1000 : Number(value)
  if (!Number.isFinite(numeric)) {
    return 0
  }
  const timestamp = numeric > MAX_COUPON_TIMESTAMP ? numeric / 1000 : numeric
  return Math.floor(timestamp)
}

function formatPayableRatioLabel(discountPercent: number): string {
  const payablePercent = Math.max(0, Math.min(100, 100 - discountPercent))
  // 中文「N 折」= 实付 N×10%；这里用可读比例，避免与减免百分比混淆
  if (payablePercent === 0) {
    return '实付 0%'
  }
  if (payablePercent % 10 === 0) {
    return `实付 ${payablePercent}%（约 ${payablePercent / 10} 折）`
  }
  return `实付 ${payablePercent}%`
}

export function getCouponValueHelper(type: AdminCouponType): string {
  if (type === 1) {
    return '按金额减免：输入元，例如 50 表示订单减免 ¥50。'
  }

  return '按比例减免：输入 1–100 的减免百分比，不是“几折”。例如 20 表示减免 20%（实付 80%，约 8 折）；85 表示减免 85%（实付 15%）。不要填 85 当 8.5 折。'
}

export function getCouponDateRangeError(dateRange: CouponFormModel['dateRange']): string {
  if (!Array.isArray(dateRange) || dateRange.length !== 2 || !dateRange[0] || !dateRange[1]) {
    return '请选择优惠券有效期'
  }

  const startedAt = normalizePickerTimestamp(dateRange[0])
  const endedAt = normalizePickerTimestamp(dateRange[1])

  if (startedAt <= 0 || endedAt <= 0) {
    return '优惠券有效期不能早于 1970-01-01'
  }

  if (startedAt > MAX_COUPON_TIMESTAMP || endedAt > MAX_COUPON_TIMESTAMP) {
    return '优惠券有效期不能晚于 2038-01-19'
  }

  if (endedAt <= startedAt) {
    return '结束时间必须晚于开始时间'
  }

  return ''
}

export function createEmptyCouponForm(): CouponFormModel {
  const now = Math.floor(Date.now() / 1000)
  const endedAt = now + DEFAULT_COUPON_VALIDITY_DAYS * SECONDS_PER_DAY

  return {
    id: undefined,
    name: '',
    generateCount: null,
    code: '',
    type: 1,
    value: null,
    dateRange: [now * 1000, endedAt * 1000],
    limitUse: null,
    limitUseWithUser: null,
    limitPlanIds: [],
    limitPeriod: [],
  }
}

export function normalizeCoupon(coupon: AdminCouponListItem): AdminCouponListItem {
  return {
    ...coupon,
    show: Boolean(coupon.show),
    limit_plan_ids: coupon.limit_plan_ids ?? null,
    limit_period: coupon.limit_period ?? null,
  }
}

export function toCouponFormModel(coupon?: AdminCouponListItem | null): CouponFormModel {
  const base = createEmptyCouponForm()
  if (!coupon) {
    return base
  }
  const startedAt = normalizeTimestampToDate(coupon.started_at)
  const endedAt = normalizeTimestampToDate(coupon.ended_at)

  return {
    id: coupon.id,
    name: coupon.name || '',
    generateCount: null,
    code: coupon.code || '',
    type: coupon.type,
    value: coupon.type === 1
      ? Number((coupon.value / 100).toFixed(2))
      : Number(coupon.value),
    dateRange: startedAt && endedAt
      ? [
          normalizeTimestampToPickerValue(coupon.started_at),
          normalizeTimestampToPickerValue(coupon.ended_at),
        ]
      : [],
    limitUse: clampNumber(coupon.limit_use ?? null),
    limitUseWithUser: clampNumber(coupon.limit_use_with_user ?? null),
    limitPlanIds: (coupon.limit_plan_ids ?? [])
      .map((item) => Number(item))
      .filter((item) => Number.isFinite(item)),
    limitPeriod: [...(coupon.limit_period ?? [])],
  }
}

export function toCouponSavePayload(form: CouponFormModel): AdminCouponGeneratePayload {
  const [startedAt, endedAt] = form.dateRange
  const normalizedStartedAt = normalizePickerTimestamp(startedAt)
  const normalizedEndedAt = normalizePickerTimestamp(endedAt)
  const normalizedCode = form.code.trim()
  const payload: AdminCouponGeneratePayload = {
    id: form.id,
    name: form.name.trim(),
    type: form.type,
    value: form.type === 1
      ? roundCurrencyToCent(form.value)
      : Math.round(Number(form.value || 0)),
    started_at: normalizedStartedAt,
    ended_at: normalizedEndedAt,
  }

  if (form.generateCount && form.generateCount > 1) {
    payload.generate_count = Math.round(form.generateCount)
  }

  if (normalizedCode) {
    payload.code = normalizedCode
  }

  if (form.limitUse !== null && Number.isFinite(form.limitUse)) {
    payload.limit_use = Math.round(form.limitUse)
  }

  if (form.limitUseWithUser !== null && Number.isFinite(form.limitUseWithUser)) {
    payload.limit_use_with_user = Math.round(form.limitUseWithUser)
  }

  if (form.limitPlanIds.length) {
    payload.limit_plan_ids = form.limitPlanIds
  }

  if (form.limitPeriod.length) {
    payload.limit_period = form.limitPeriod
  }

  return payload
}

export function getCouponTypeLabel(type: AdminCouponType): string {
  return COUPON_TYPE_OPTIONS.find((item) => item.value === type)?.label || '未知类型'
}

export function getCouponTypeShortLabel(type: AdminCouponType): string {
  return COUPON_TYPE_OPTIONS.find((item) => item.value === type)?.shortLabel || '未知类型'
}

export function formatCouponValue(coupon: Pick<AdminCouponListItem, 'type' | 'value'>): string {
  if (coupon.type === 1) {
    return `减免 ¥${(coupon.value / 100).toFixed(2).replace(/\.00$/, '')}`
  }

  const discountPercent = Number(coupon.value)
  if (!Number.isFinite(discountPercent)) {
    return '减免 -'
  }

  return `减免 ${discountPercent}%（${formatPayableRatioLabel(discountPercent)}）`
}

export function filterCoupons(
  coupons: AdminCouponListItem[],
  keyword: string,
  typeFilter: CouponTypeFilter,
): AdminCouponListItem[] {
  const normalized = keyword.trim().toLowerCase()
  return coupons.filter((coupon) => {
    const matchesType = typeFilter === 'all' || String(coupon.type) === typeFilter
    if (!matchesType) {
      return false
    }

    if (!normalized) {
      return true
    }

    const haystack = [coupon.id, coupon.name, coupon.code]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return haystack.includes(normalized)
  })
}

export function sortCoupons(
  coupons: AdminCouponListItem[],
  sortKey: CouponSortKey,
  sortOrder: CouponSortOrder,
): AdminCouponListItem[] {
  if (!sortOrder) {
    return [...coupons].sort((left, right) => right.id - left.id)
  }

  const factor = sortOrder === 'ascending' ? 1 : -1
  return [...coupons].sort((left, right) => {
    const leftValue = Number(left[sortKey] ?? -1)
    const rightValue = Number(right[sortKey] ?? -1)
    return (leftValue - rightValue) * factor
  })
}

export function formatCouponLimit(value: number | null | undefined, fallback = '无限次'): string {
  if (value === null || value === undefined) {
    return fallback
  }

  const numeric = Number(value)
  if (!Number.isFinite(numeric)) {
    return fallback
  }
  return String(numeric)
}

export function formatCouponDateRange(coupon: Pick<AdminCouponListItem, 'started_at' | 'ended_at'>): string {
  return `${formatDatePart(new Date(coupon.started_at * 1000))} → ${formatDatePart(new Date(coupon.ended_at * 1000))}`
}

export function getCouponExpiryMeta(endedAt: number): CouponExpiryMeta {
  const diff = endedAt * 1000 - Date.now()
  const diffDays = Math.max(0, Math.floor(Math.abs(diff) / (24 * 60 * 60 * 1000)))
  if (diff < 0) {
    return {
      text: `已过期${diffDays}天`,
      kind: 'danger',
    }
  }

  if (diffDays <= 3) {
    return {
      text: `剩余${diffDays}天`,
      kind: 'info',
    }
  }

  return {
    text: `有效中`,
    kind: 'success',
  }
}

export function countEnabledCoupons(coupons: AdminCouponListItem[]): number {
  return coupons.filter((coupon) => coupon.show).length
}

export function countExpiredCoupons(coupons: AdminCouponListItem[]): number {
  return coupons.filter((coupon) => coupon.ended_at * 1000 < Date.now()).length
}
