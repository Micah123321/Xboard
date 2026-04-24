import type {
  AdminGiftCardCodeItem,
  AdminGiftCardCodeStatus,
  AdminGiftCardTemplateItem,
  AdminGiftCardTemplatePayload,
  AdminGiftCardTemplateType,
  AdminGiftCardUsageItem,
  AdminPlanOption,
} from '@/types/api'

export type GiftCardTemplateStatusFilter = 'all' | 'enabled' | 'disabled'
export type GiftCardCodeStatusFilter = 'all' | AdminGiftCardCodeStatus

export interface GiftCardOption<T extends string | number> {
  label: string
  value: T
}

export interface GiftCardStatusMeta {
  label: string
  tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral'
}

export interface GiftCardTemplateFormModel {
  id?: number
  name: string
  description: string
  type: AdminGiftCardTemplateType
  status: boolean
  sort: number
  theme_color: string
  icon: string
  background_image: string
  balance_yuan: number | null
  transfer_gb: number | null
  expire_days: number | null
  device_limit: number | null
  reset_package: boolean
  plan_id: number | null
  plan_validity_days: number | null
  invite_reward_rate: number | null
  new_user_only: boolean
  new_user_max_days: number | null
  paid_user_only: boolean
  require_invite: boolean
  allowed_plan_ids: number[]
  max_use_per_user: number | null
  cooldown_hours: number | null
  festival_bonus: number | null
  festival_start_at: number | null
  festival_end_at: number | null
}

const CURRENCY_FORMATTER = new Intl.NumberFormat('zh-CN', {
  style: 'currency',
  currency: 'CNY',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const DATE_TIME_FORMATTER = new Intl.DateTimeFormat('zh-CN', {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hour12: false,
})

export const DEFAULT_GIFT_CARD_TYPE_OPTIONS: Array<GiftCardOption<AdminGiftCardTemplateType>> = [
  { label: '通用礼品卡', value: 1 },
  { label: '套餐礼品卡', value: 2 },
  { label: '盲盒礼品卡', value: 3 },
]

export const GIFT_CARD_CODE_STATUS_OPTIONS: Array<GiftCardOption<AdminGiftCardCodeStatus>> = [
  { label: '未使用', value: 0 },
  { label: '已使用', value: 1 },
  { label: '已过期', value: 2 },
  { label: '已禁用', value: 3 },
]

function toNumber(value: unknown): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : null
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

function cleanObject<T extends Record<string, unknown>>(input: T): T | undefined {
  const entries = Object.entries(input).filter(([, value]) => {
    if (value === null || value === undefined || value === '') {
      return false
    }
    if (Array.isArray(value)) {
      return value.length > 0
    }
    return true
  })

  if (entries.length === 0) {
    return undefined
  }

  return Object.fromEntries(entries) as T
}

function normalizeAllowedPlanIds(value: unknown): number[] {
  if (!Array.isArray(value)) {
    return []
  }

  return value
    .map((item) => Number(item))
    .filter((item) => Number.isFinite(item) && item > 0)
}

export function buildGiftCardTypeOptions(typeMap?: Record<string, string> | null): Array<GiftCardOption<AdminGiftCardTemplateType>> {
  if (!typeMap || Object.keys(typeMap).length === 0) {
    return DEFAULT_GIFT_CARD_TYPE_OPTIONS
  }

  return Object.entries(typeMap)
    .map(([value, label]) => ({
      label,
      value: Number(value) as AdminGiftCardTemplateType,
    }))
    .filter((item) => Number.isFinite(item.value))
}

export function createGiftCardTemplateFormModel(): GiftCardTemplateFormModel {
  return {
    name: '',
    description: '',
    type: 1,
    status: true,
    sort: 0,
    theme_color: '#0071e3',
    icon: '',
    background_image: '',
    balance_yuan: null,
    transfer_gb: null,
    expire_days: null,
    device_limit: null,
    reset_package: false,
    plan_id: null,
    plan_validity_days: null,
    invite_reward_rate: null,
    new_user_only: false,
    new_user_max_days: 7,
    paid_user_only: false,
    require_invite: false,
    allowed_plan_ids: [],
    max_use_per_user: null,
    cooldown_hours: null,
    festival_bonus: null,
    festival_start_at: null,
    festival_end_at: null,
  }
}

export function centsToYuan(value: unknown): number | null {
  const numeric = toNumber(value)
  if (numeric === null) {
    return null
  }
  return Number((numeric / 100).toFixed(2))
}

export function yuanToCents(value: unknown): number | null {
  const numeric = toNumber(value)
  if (numeric === null) {
    return null
  }
  return Math.round(numeric * 100)
}

export function bytesToGb(value: unknown): number | null {
  const numeric = toNumber(value)
  if (numeric === null) {
    return null
  }
  return Number((numeric / 1024 / 1024 / 1024).toFixed(2))
}

export function gbToBytes(value: unknown): number | null {
  const numeric = toNumber(value)
  if (numeric === null) {
    return null
  }
  return Math.round(numeric * 1024 * 1024 * 1024)
}

export function formatGiftCardDateTime(value: number | string | null | undefined): string {
  const timestamp = toTimestampMilliseconds(value)
  if (timestamp === null) {
    return '-'
  }
  return DATE_TIME_FORMATTER.format(new Date(timestamp))
}

export function formatGiftCardCurrency(value: unknown): string {
  const yuan = centsToYuan(value)
  return yuan === null ? '-' : CURRENCY_FORMATTER.format(yuan)
}

export function formatGiftCardTraffic(value: unknown): string {
  const gb = bytesToGb(value)
  if (gb === null) {
    return '-'
  }
  return `${gb} GB`
}

export function formatGiftCardMultiplier(value: unknown): string {
  const numeric = toNumber(value)
  if (numeric === null) {
    return '-'
  }
  return `${numeric.toFixed(2)}x`
}

export function formatGiftCardTypeLabel(value: AdminGiftCardTemplateType, options = DEFAULT_GIFT_CARD_TYPE_OPTIONS): string {
  return options.find((item) => item.value === value)?.label ?? '未知类型'
}

export function getGiftCardCodeStatusMeta(status: AdminGiftCardCodeStatus | null | undefined): GiftCardStatusMeta {
  switch (status) {
    case 0:
      return { label: '未使用', tone: 'info' }
    case 1:
      return { label: '已使用', tone: 'success' }
    case 2:
      return { label: '已过期', tone: 'warning' }
    case 3:
      return { label: '已禁用', tone: 'danger' }
    default:
      return { label: '未知状态', tone: 'neutral' }
  }
}

export function getGiftCardTemplateRewardSummary(template: AdminGiftCardTemplateItem, plans: AdminPlanOption[] = []): string[] {
  const rewards = template.rewards ?? {}
  const summary: string[] = []

  if (toNumber(rewards.balance) && toNumber(rewards.balance)! > 0) {
    summary.push(`余额: ${formatGiftCardCurrency(rewards.balance)}`)
  }
  if (toNumber(rewards.transfer_enable) && toNumber(rewards.transfer_enable)! > 0) {
    summary.push(`流量: ${formatGiftCardTraffic(rewards.transfer_enable)}`)
  }
  if (toNumber(rewards.expire_days) && toNumber(rewards.expire_days)! > 0) {
    summary.push(`有效期: ${rewards.expire_days} 天`)
  }
  if (toNumber(rewards.device_limit) && toNumber(rewards.device_limit)! > 0) {
    summary.push(`设备数: ${rewards.device_limit}`)
  }
  if (rewards.reset_package) {
    summary.push('重置当月流量')
  }
  if (toNumber(rewards.plan_id)) {
    const plan = plans.find((item) => item.id === Number(rewards.plan_id))
    summary.push(`套餐: ${plan?.name || `#${rewards.plan_id}`}`)
    if (toNumber(rewards.plan_validity_days) && toNumber(rewards.plan_validity_days)! > 0) {
      summary.push(`套餐有效期: ${rewards.plan_validity_days} 天`)
    }
  }
  if (toNumber(rewards.invite_reward_rate) && toNumber(rewards.invite_reward_rate)! > 0) {
    summary.push(`邀请奖励: ${(Number(rewards.invite_reward_rate) * 100).toFixed(0)}%`)
  }

  return summary.length > 0 ? summary : ['暂无奖励配置']
}

export function getGiftCardAvailableUsage(code: Pick<AdminGiftCardCodeItem, 'max_usage' | 'usage_count'>): number {
  return Math.max(0, (code.max_usage ?? 0) - (code.usage_count ?? 0))
}

export function filterGiftCardTemplates(
  templates: AdminGiftCardTemplateItem[],
  keyword: string,
  type: AdminGiftCardTemplateType | 'all',
  status: GiftCardTemplateStatusFilter,
): AdminGiftCardTemplateItem[] {
  const normalizedKeyword = keyword.trim().toLowerCase()

  return templates.filter((item) => {
    const matchesKeyword = !normalizedKeyword
      || [item.name, item.description, item.type_name]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(normalizedKeyword))

    const matchesType = type === 'all' || item.type === type
    const matchesStatus = status === 'all'
      || (status === 'enabled' && Boolean(item.status))
      || (status === 'disabled' && !Boolean(item.status))

    return matchesKeyword && matchesType && matchesStatus
  })
}

export function filterGiftCardCodes(
  codes: AdminGiftCardCodeItem[],
  keyword: string,
  templateId: number | 'all',
  status: GiftCardCodeStatusFilter,
): AdminGiftCardCodeItem[] {
  const normalizedKeyword = keyword.trim().toLowerCase()

  return codes.filter((item) => {
    const matchesKeyword = !normalizedKeyword
      || [item.code, item.template_name, item.batch_id, item.user_email]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(normalizedKeyword))

    const matchesTemplate = templateId === 'all' || item.template_id === templateId
    const matchesStatus = status === 'all' || item.status === status
    return matchesKeyword && matchesTemplate && matchesStatus
  })
}

export function filterGiftCardUsages(usages: AdminGiftCardUsageItem[], keyword: string): AdminGiftCardUsageItem[] {
  const normalizedKeyword = keyword.trim().toLowerCase()

  return usages.filter((item) => {
    if (!normalizedKeyword) {
      return true
    }
    return [item.user_email, item.template_name, item.code, item.invite_user_email]
      .filter(Boolean)
      .some((value) => String(value).toLowerCase().includes(normalizedKeyword))
  })
}

export function toGiftCardTemplateFormModel(template?: AdminGiftCardTemplateItem | null): GiftCardTemplateFormModel {
  const base = createGiftCardTemplateFormModel()
  if (!template) {
    return base
  }

  return {
    id: template.id,
    name: template.name,
    description: template.description ?? '',
    type: template.type,
    status: Boolean(template.status),
    sort: Number(template.sort ?? 0),
    theme_color: template.theme_color || '#0071e3',
    icon: template.icon ?? '',
    background_image: template.background_image ?? '',
    balance_yuan: centsToYuan(template.rewards?.balance),
    transfer_gb: bytesToGb(template.rewards?.transfer_enable),
    expire_days: toNumber(template.rewards?.expire_days),
    device_limit: toNumber(template.rewards?.device_limit),
    reset_package: Boolean(template.rewards?.reset_package),
    plan_id: toNumber(template.rewards?.plan_id),
    plan_validity_days: toNumber(template.rewards?.plan_validity_days),
    invite_reward_rate: toNumber(template.rewards?.invite_reward_rate),
    new_user_only: Boolean(template.conditions?.new_user_only),
    new_user_max_days: toNumber(template.conditions?.new_user_max_days) ?? 7,
    paid_user_only: Boolean(template.conditions?.paid_user_only),
    require_invite: Boolean(template.conditions?.require_invite),
    allowed_plan_ids: normalizeAllowedPlanIds(template.conditions?.allowed_plans),
    max_use_per_user: toNumber(template.limits?.max_use_per_user),
    cooldown_hours: toNumber(template.limits?.cooldown_hours),
    festival_bonus: toNumber(template.special_config?.festival_bonus),
    festival_start_at: toNumber(template.special_config?.start_time),
    festival_end_at: toNumber(template.special_config?.end_time),
  }
}

export function toGiftCardTemplatePayload(form: GiftCardTemplateFormModel): AdminGiftCardTemplatePayload {
  const conditions = cleanObject({
    new_user_only: form.new_user_only || undefined,
    new_user_max_days: form.new_user_only ? toNumber(form.new_user_max_days) : undefined,
    paid_user_only: form.paid_user_only || undefined,
    allowed_plans: normalizeAllowedPlanIds(form.allowed_plan_ids),
    require_invite: form.require_invite || undefined,
  })

  const rewards = cleanObject({
    balance: yuanToCents(form.balance_yuan),
    transfer_enable: gbToBytes(form.transfer_gb),
    expire_days: toNumber(form.expire_days),
    device_limit: toNumber(form.device_limit),
    reset_package: form.reset_package || undefined,
    plan_id: form.type === 2 ? toNumber(form.plan_id) : undefined,
    plan_validity_days: form.type === 2 ? toNumber(form.plan_validity_days) : undefined,
    invite_reward_rate: toNumber(form.invite_reward_rate),
  }) ?? {}

  const limits = cleanObject({
    max_use_per_user: toNumber(form.max_use_per_user),
    cooldown_hours: toNumber(form.cooldown_hours),
  })

  const special_config = cleanObject({
    festival_bonus: toNumber(form.festival_bonus),
    start_time: toNumber(form.festival_start_at),
    end_time: toNumber(form.festival_end_at),
  })

  return {
    id: form.id,
    name: form.name.trim(),
    description: form.description.trim() || null,
    type: form.type,
    status: Boolean(form.status),
    conditions,
    rewards,
    limits,
    special_config,
    icon: form.icon.trim() || null,
    background_image: form.background_image.trim() || null,
    theme_color: form.theme_color.trim() || '#0071e3',
    sort: Math.max(0, Number(form.sort || 0)),
  }
}
