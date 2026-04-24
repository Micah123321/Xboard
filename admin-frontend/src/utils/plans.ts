import MarkdownIt from 'markdown-it'
import type { AdminPlanListItem, AdminPlanPriceMap, AdminPlanSavePayload } from '@/types/api'

export type PlanPricePeriod =
  | 'monthly'
  | 'quarterly'
  | 'half_yearly'
  | 'yearly'
  | 'two_yearly'
  | 'three_yearly'
  | 'onetime'
  | 'reset_traffic'

export type PlanResetMethodValue = number | 'follow'

export interface PlanPricePeriodOption {
  key: PlanPricePeriod
  label: string
  badgeLabel: string
}

export interface PlanFormModel {
  id?: number
  name: string
  tags: string[]
  groupId: number | null
  transferEnableGb: number | null
  speedLimit: number | null
  deviceLimit: number | null
  capacityLimit: number | null
  resetTrafficMethod: PlanResetMethodValue
  prices: Record<PlanPricePeriod, string>
  content: string
  forceUpdate: boolean
}

const markdown = new MarkdownIt({
  html: true,
  breaks: true,
  linkify: true,
})

export const PLAN_PRICE_PERIODS: PlanPricePeriodOption[] = [
  { key: 'monthly', label: '月付', badgeLabel: '月付' },
  { key: 'quarterly', label: '季付', badgeLabel: '季付' },
  { key: 'half_yearly', label: '半年付', badgeLabel: '半年付' },
  { key: 'yearly', label: '年付', badgeLabel: '年付' },
  { key: 'two_yearly', label: '两年付', badgeLabel: '两年付' },
  { key: 'three_yearly', label: '三年付', badgeLabel: '三年付' },
  { key: 'onetime', label: '流量包', badgeLabel: '流量包' },
  { key: 'reset_traffic', label: '重置包', badgeLabel: '重置包' },
]

export const RESET_TRAFFIC_METHOD_OPTIONS: Array<{ label: string; value: PlanResetMethodValue }> = [
  { label: '跟随系统设置', value: 'follow' },
  { label: '每月 1 号', value: 0 },
  { label: '按月重置', value: 1 },
  { label: '不重置', value: 2 },
  { label: '每年 1 月 1 日', value: 3 },
  { label: '按年重置', value: 4 },
]

export const DEFAULT_PLAN_DESCRIPTION_TEMPLATE = [
  '- 节点覆盖多个国家与地区',
  '- 包含家庭宽带与专线节点',
  '- 少量用户无滥用，稳定高速',
  '- 支持流媒体与下载场景',
  '- 不限制使用人数',
  '- 不限制到期时间',
  '- 不限制网络速度',
].join('\n')

function createEmptyPriceMap(): Record<PlanPricePeriod, string> {
  return PLAN_PRICE_PERIODS.reduce((acc, item) => {
    acc[item.key] = ''
    return acc
  }, {} as Record<PlanPricePeriod, string>)
}

function trimTrailingZeros(value: number): string {
  return value
    .toFixed(2)
    .replace(/\.00$/, '')
    .replace(/(\.\d)0$/, '$1')
}

function normalizeNumericInput(value: string): string {
  return value.replace(/[^\d.]/g, '').replace(/(\..*)\./g, '$1')
}

export function createEmptyPlanForm(): PlanFormModel {
  return {
    name: '',
    tags: [],
    groupId: null,
    transferEnableGb: null,
    speedLimit: null,
    deviceLimit: null,
    capacityLimit: null,
    resetTrafficMethod: 'follow',
    prices: createEmptyPriceMap(),
    content: '',
    forceUpdate: false,
  }
}

export function toPlanFormModel(plan?: AdminPlanListItem | null): PlanFormModel {
  const form = createEmptyPlanForm()

  if (!plan) {
    return form
  }

  const nextPrices = createEmptyPriceMap()
  for (const option of PLAN_PRICE_PERIODS) {
    const rawValue = plan.prices?.[option.key]
    nextPrices[option.key] = rawValue ? trimTrailingZeros(Number(rawValue)) : ''
  }

  return {
    id: plan.id,
    name: plan.name || '',
    tags: [...(plan.tags ?? [])],
    groupId: plan.group_id ?? null,
    transferEnableGb: Number(plan.transfer_enable) || null,
    speedLimit: plan.speed_limit ?? null,
    deviceLimit: plan.device_limit ?? null,
    capacityLimit: plan.capacity_limit ?? null,
    resetTrafficMethod: typeof plan.reset_traffic_method === 'number' ? plan.reset_traffic_method : 'follow',
    prices: nextPrices,
    content: plan.content || '',
    forceUpdate: false,
  }
}

export function sanitizePlanPriceInput(value: string): string {
  return normalizeNumericInput(value)
}

export function normalizePlanTag(raw: string): string {
  return raw.trim().replace(/\s+/g, ' ')
}

export function toPlanSavePayload(form: PlanFormModel): AdminPlanSavePayload {
  const prices = PLAN_PRICE_PERIODS.reduce((acc, item) => {
    const rawValue = form.prices[item.key]
    if (!rawValue) {
      return acc
    }

    const numeric = Number(rawValue)
    if (Number.isFinite(numeric) && numeric > 0) {
      acc[item.key] = Number(trimTrailingZeros(numeric))
    }
    return acc
  }, {} as AdminPlanPriceMap)

  return {
    id: form.id,
    name: form.name.trim(),
    content: form.content.trim(),
    transfer_enable: Math.max(1, Math.round(Number(form.transferEnableGb) || 0)),
    prices,
    group_id: form.groupId,
    speed_limit: form.speedLimit ?? null,
    device_limit: form.deviceLimit ?? null,
    capacity_limit: form.capacityLimit ?? null,
    reset_traffic_method: form.resetTrafficMethod === 'follow' ? null : form.resetTrafficMethod,
    tags: form.tags,
    force_update: form.forceUpdate,
  }
}

export function renderPlanContent(source: string): string {
  return markdown.render(source || '')
}

export function formatPlanPrice(value: number | null | undefined, suffix = ''): string {
  const numeric = Number(value)
  if (!Number.isFinite(numeric) || numeric <= 0) {
    return '未设置'
  }

  return `¥${trimTrailingZeros(numeric)}${suffix}`
}

export function getPlanPriceBadges(plan: Pick<AdminPlanListItem, 'prices'>): Array<{ key: PlanPricePeriod; label: string }> {
  return PLAN_PRICE_PERIODS
    .filter((item) => Number(plan.prices?.[item.key] || 0) > 0)
    .map((item) => ({
      key: item.key,
      label: item.key === 'reset_traffic'
        ? `${item.badgeLabel} ${formatPlanPrice(plan.prices?.[item.key], '/次')}`
        : `${item.badgeLabel} ${formatPlanPrice(plan.prices?.[item.key])}`,
    }))
}

export function formatPlanTraffic(plan: Pick<AdminPlanListItem, 'transfer_enable'>): string {
  const value = Number(plan.transfer_enable)
  if (!Number.isFinite(value) || value <= 0) {
    return '未设流量'
  }

  return `${value} GB`
}

export function filterPlans(plans: AdminPlanListItem[], keyword: string): AdminPlanListItem[] {
  const normalized = keyword.trim().toLowerCase()
  if (!normalized) {
    return plans
  }

  return plans.filter((plan) => {
    const haystack = [
      plan.id,
      plan.name,
      plan.group?.name,
      plan.tags?.join(' '),
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return haystack.includes(normalized)
  })
}

export function countEnabledPlans(plans: AdminPlanListItem[], field: 'show' | 'sell' | 'renew'): number {
  return plans.filter((plan) => Boolean(plan[field])).length
}

export function movePlanOrder(plans: AdminPlanListItem[], fromIndex: number, direction: -1 | 1): AdminPlanListItem[] {
  const targetIndex = fromIndex + direction
  if (targetIndex < 0 || targetIndex >= plans.length) {
    return plans
  }

  const next = [...plans]
  const [current] = next.splice(fromIndex, 1)
  next.splice(targetIndex, 0, current)
  return next
}
