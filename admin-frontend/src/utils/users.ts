import type { AdminPlanOption, AdminUserFilter, AdminUserListItem, AdminUserUpdatePayload } from '@/types/api'

export interface UserStatusMeta {
  label: string
  type: 'success' | 'danger' | 'warning' | 'info'
}

export interface UserFormModel {
  id?: number
  email: string
  password: string
  uploadGb: number | null
  downloadGb: number | null
  totalTrafficGb: number | null
  expiredAt: number | null
  planId: number | null
  banned: boolean
  commissionType: number | null
  commissionRate: number | null
  discount: number | null
  speedLimit: number | null
  deviceLimit: number | null
  balance: number | null
  commissionBalance: number | null
  inviteUserEmail: string
  isAdmin: boolean
  isStaff: boolean
  remarks: string
}

export type UserAdvancedFieldKey =
  | 'email'
  | 'id'
  | 'plan_id'
  | 'activity_status'
  | 'transfer_enable'
  | 'total_used'
  | 'online_count'
  | 'expired_at'
  | 'uuid'
  | 'token'
  | 'banned'
  | 'remarks'

export type UserAdvancedOperator =
  | 'like'
  | 'notlike'
  | 'eq'
  | 'gt'
  | 'gte'
  | 'lt'
  | 'lte'
  | 'null'
  | 'notnull'

export type UserAdvancedInputKind = 'text' | 'number' | 'plan' | 'status' | 'activity' | 'date'

export interface UserAdvancedFilterItem {
  key: string
  field: UserAdvancedFieldKey
  operator: UserAdvancedOperator
  value: string | number | null
  logic: 'and' | 'or'
}

export interface UserAdvancedFieldDefinition {
  field: UserAdvancedFieldKey
  label: string
  input: UserAdvancedInputKind
  placeholder?: string
  unit?: string
  step?: number
  operators: Array<{ value: UserAdvancedOperator; label: string }>
}

export const USER_STATUS_VALUE_OPTIONS = [
  { label: '正常', value: 0 },
  { label: '封禁', value: 1 },
] as const

export const USER_ACTIVITY_STATUS_OPTIONS = [
  { label: '活跃', value: 1 },
  { label: '非活跃', value: 0 },
] as const

export const USER_ADVANCED_FIELD_DEFINITIONS: UserAdvancedFieldDefinition[] = [
  {
    field: 'email',
    label: '邮箱',
    input: 'text',
    placeholder: '输入邮箱关键字',
    operators: [
      { value: 'like', label: '包含' },
      { value: 'notlike', label: '不包含' },
      { value: 'eq', label: '等于' },
    ],
  },
  {
    field: 'id',
    label: '用户ID',
    input: 'number',
    placeholder: '输入用户 ID',
    step: 1,
    operators: [
      { value: 'eq', label: '等于' },
      { value: 'gt', label: '大于' },
      { value: 'gte', label: '大于等于' },
      { value: 'lt', label: '小于' },
      { value: 'lte', label: '小于等于' },
    ],
  },
  {
    field: 'plan_id',
    label: '订阅',
    input: 'plan',
    operators: [
      { value: 'eq', label: '是' },
      { value: 'null', label: '未订阅' },
      { value: 'notnull', label: '已订阅' },
    ],
  },
  {
    field: 'activity_status',
    label: '活跃状态',
    input: 'activity',
    operators: [{ value: 'eq', label: '是' }],
  },
  {
    field: 'transfer_enable',
    label: '流量',
    input: 'number',
    placeholder: '输入流量值',
    unit: 'GB',
    step: 1,
    operators: [
      { value: 'eq', label: '等于' },
      { value: 'gt', label: '大于' },
      { value: 'gte', label: '大于等于' },
      { value: 'lt', label: '小于' },
      { value: 'lte', label: '小于等于' },
    ],
  },
  {
    field: 'total_used',
    label: '已用流量',
    input: 'number',
    placeholder: '输入已用流量',
    unit: 'GB',
    step: 1,
    operators: [
      { value: 'eq', label: '等于' },
      { value: 'gt', label: '大于' },
      { value: 'gte', label: '大于等于' },
      { value: 'lt', label: '小于' },
      { value: 'lte', label: '小于等于' },
    ],
  },
  {
    field: 'online_count',
    label: '在线设备',
    input: 'number',
    placeholder: '输入在线设备数',
    step: 1,
    operators: [
      { value: 'eq', label: '等于' },
      { value: 'gt', label: '大于' },
      { value: 'gte', label: '大于等于' },
      { value: 'lt', label: '小于' },
      { value: 'lte', label: '小于等于' },
    ],
  },
  {
    field: 'expired_at',
    label: '到期时间',
    input: 'date',
    operators: [
      { value: 'gte', label: '晚于' },
      { value: 'lte', label: '早于' },
      { value: 'null', label: '长期有效' },
      { value: 'notnull', label: '已设置到期时间' },
    ],
  },
  {
    field: 'uuid',
    label: 'UUID',
    input: 'text',
    placeholder: '输入 UUID',
    operators: [
      { value: 'like', label: '包含' },
      { value: 'notlike', label: '不包含' },
      { value: 'eq', label: '等于' },
    ],
  },
  {
    field: 'token',
    label: 'Token',
    input: 'text',
    placeholder: '输入 Token',
    operators: [
      { value: 'like', label: '包含' },
      { value: 'notlike', label: '不包含' },
      { value: 'eq', label: '等于' },
    ],
  },
  {
    field: 'banned',
    label: '账号状态',
    input: 'status',
    operators: [{ value: 'eq', label: '是' }],
  },
  {
    field: 'remarks',
    label: '备注',
    input: 'text',
    placeholder: '输入备注关键字',
    operators: [
      { value: 'like', label: '包含' },
      { value: 'notlike', label: '不包含' },
      { value: 'eq', label: '等于' },
    ],
  },
] as const

export const COMMISSION_TYPE_OPTIONS = [
  { label: '跟随系统', value: 0 },
  { label: '周期返佣', value: 1 },
  { label: '一次性返佣', value: 2 },
] as const

const GIGABYTE = 1024 ** 3

function createFilterKey(): string {
  return `user-filter-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`
}

function toNumber(value: unknown): number {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : 0
}

export function bytesToGigabytes(value: unknown): number | null {
  const numeric = toNumber(value)
  if (numeric <= 0) {
    return null
  }

  return Number((numeric / GIGABYTE).toFixed(2))
}

export function gigabytesToBytes(value: number | null | undefined): number {
  const numeric = Number(value)
  if (!Number.isFinite(numeric) || numeric <= 0) {
    return 0
  }

  return Math.round(numeric * GIGABYTE)
}

export function normalizeTimestampSeconds(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const numeric = Number(value)
  return Number.isFinite(numeric) && numeric > 0 ? Math.floor(numeric) : null
}

export function getUserAdvancedFieldDefinition(field: UserAdvancedFieldKey): UserAdvancedFieldDefinition {
  return USER_ADVANCED_FIELD_DEFINITIONS.find((item) => item.field === field) ?? USER_ADVANCED_FIELD_DEFINITIONS[0]
}

export function createEmptyUserAdvancedFilter(): UserAdvancedFilterItem {
  return {
    key: createFilterKey(),
    field: 'email',
    operator: 'like',
    value: '',
    logic: 'and',
  }
}

export function cloneUserAdvancedFilters(filters: UserAdvancedFilterItem[]): UserAdvancedFilterItem[] {
  return filters.map((item) => ({
    key: item.key || createFilterKey(),
    field: item.field,
    operator: item.operator,
    value: item.value ?? '',
    logic: item.logic ?? 'and',
  }))
}

function requiresFilterValue(operator: UserAdvancedOperator): boolean {
  return operator !== 'null' && operator !== 'notnull'
}

function normalizeAdvancedFilterValue(item: UserAdvancedFilterItem): string | null {
  if (!requiresFilterValue(item.operator)) {
    return `${item.operator}:1`
  }

  if (item.value === null || item.value === undefined || item.value === '') {
    return null
  }

  if (item.field === 'transfer_enable' || item.field === 'total_used') {
    const numeric = Number(item.value)
    if (!Number.isFinite(numeric) || numeric < 0) {
      return null
    }
    return `${item.operator}:${Math.round(numeric * GIGABYTE)}`
  }

  if (item.field === 'expired_at') {
    const timestamp = normalizeTimestampSeconds(item.value)
    return timestamp ? `${item.operator}:${timestamp}` : null
  }

  if (item.field === 'id' || item.field === 'plan_id' || item.field === 'activity_status' || item.field === 'online_count' || item.field === 'banned') {
    const numeric = Number(item.value)
    return Number.isFinite(numeric) ? `${item.operator}:${numeric}` : null
  }

  const normalized = String(item.value).trim()
  return normalized ? `${item.operator}:${normalized}` : null
}

function getPlanNameById(plans: AdminPlanOption[], id: string | number): string {
  const numericId = Number(id)
  const target = plans.find((plan) => Number(plan.id) === numericId)
  return target?.name || `订阅 #${numericId}`
}

function formatAdvancedFilterValue(item: UserAdvancedFilterItem, plans: AdminPlanOption[]): string {
  if (!requiresFilterValue(item.operator)) {
    return item.operator === 'null' ? '未设置' : '已设置'
  }

  if (item.field === 'plan_id') {
    return getPlanNameById(plans, item.value ?? '')
  }

  if (item.field === 'banned') {
    return Number(item.value) === 1 ? '封禁' : '正常'
  }

  if (item.field === 'activity_status') {
    return Number(item.value) === 1 ? '活跃' : '非活跃'
  }

  if (item.field === 'transfer_enable' || item.field === 'total_used') {
    return `${Number(item.value)} GB`
  }

  if (item.field === 'expired_at') {
    const seconds = normalizeTimestampSeconds(item.value)
    return seconds ? new Date(seconds * 1000).toLocaleString('zh-CN', { hour12: false }) : '未设置'
  }

  return String(item.value)
}

export function splitEmailAddress(email: string): { prefix: string; suffix: string } | null {
  const normalized = email.trim()
  const atIndex = normalized.lastIndexOf('@')
  if (atIndex <= 0 || atIndex === normalized.length - 1) {
    return null
  }

  return {
    prefix: normalized.slice(0, atIndex),
    suffix: normalized.slice(atIndex + 1),
  }
}

export function getUserUsagePercent(user: Pick<AdminUserListItem, 'transfer_enable' | 'total_used'>): number {
  const total = toNumber(user.transfer_enable)
  if (total <= 0) {
    return 0
  }

  return Math.min(100, Number(((toNumber(user.total_used) / total) * 100).toFixed(1)))
}

export function getUserStatusMeta(user: Pick<AdminUserListItem, 'banned' | 'expired_at' | 'plan_id'>): UserStatusMeta {
  if (user.banned) {
    return { label: '封禁', type: 'danger' }
  }

  if (!user.plan_id) {
    return { label: '未订阅', type: 'info' }
  }

  if (user.expired_at && user.expired_at < Math.floor(Date.now() / 1000)) {
    return { label: '已到期', type: 'warning' }
  }

  return { label: '正常', type: 'success' }
}

export function createEmptyUserForm(): UserFormModel {
  return {
    email: '',
    password: '',
    uploadGb: null,
    downloadGb: null,
    totalTrafficGb: null,
    expiredAt: null,
    planId: null,
    banned: false,
    commissionType: 0,
    commissionRate: null,
    discount: null,
    speedLimit: null,
    deviceLimit: null,
    balance: null,
    commissionBalance: null,
    inviteUserEmail: '',
    isAdmin: false,
    isStaff: false,
    remarks: '',
  }
}

export function toUserFormModel(user?: AdminUserListItem | null): UserFormModel {
  if (!user) {
    return createEmptyUserForm()
  }

  return {
    id: user.id,
    email: user.email,
    password: '',
    uploadGb: bytesToGigabytes(user.u),
    downloadGb: bytesToGigabytes(user.d),
    totalTrafficGb: bytesToGigabytes(user.transfer_enable),
    expiredAt: normalizeTimestampSeconds(user.expired_at),
    planId: user.plan_id,
    banned: Boolean(user.banned),
    commissionType: user.commission_type ?? 0,
    commissionRate: user.commission_rate ?? null,
    discount: user.discount ?? null,
    speedLimit: user.speed_limit ?? null,
    deviceLimit: user.device_limit ?? null,
    balance: user.balance ?? null,
    commissionBalance: user.commission_balance ?? null,
    inviteUserEmail: user.invite_user?.email ?? '',
    isAdmin: Boolean(user.is_admin),
    isStaff: Boolean(user.is_staff),
    remarks: user.remarks ?? '',
  }
}

export function toUserUpdatePayload(form: UserFormModel): AdminUserUpdatePayload {
  return {
    id: Number(form.id),
    email: form.email.trim(),
    password: form.password.trim() || undefined,
    u: gigabytesToBytes(form.uploadGb),
    d: gigabytesToBytes(form.downloadGb),
    transfer_enable: gigabytesToBytes(form.totalTrafficGb),
    expired_at: normalizeTimestampSeconds(form.expiredAt),
    plan_id: form.planId,
    banned: form.banned,
    commission_type: form.commissionType,
    commission_rate: form.commissionRate,
    discount: form.discount,
    speed_limit: form.speedLimit,
    device_limit: form.deviceLimit,
    balance: form.balance ?? 0,
    commission_balance: form.commissionBalance ?? 0,
    invite_user_email: form.inviteUserEmail.trim() || null,
    is_admin: form.isAdmin,
    is_staff: form.isStaff,
    remarks: form.remarks.trim() || null,
  }
}

export function buildUserFilters(
  keyword: string,
  status: string,
  planId: string,
  advancedFilters: UserAdvancedFilterItem[] = [],
): AdminUserFilter[] {
  const filters: AdminUserFilter[] = []

  if (keyword.trim()) {
    filters.push({ id: 'email', value: `like:${keyword.trim()}` })
  }

  if (status === 'active') {
    filters.push({ id: 'banned', value: 'eq:0' })
  }

  if (status === 'banned') {
    filters.push({ id: 'banned', value: 'eq:1' })
  }

  if (planId && planId !== 'all') {
    filters.push({ id: 'plan_id', value: `eq:${Number(planId)}` })
  }

  for (const item of advancedFilters) {
    const normalizedValue = normalizeAdvancedFilterValue(item)
    if (!normalizedValue) {
      continue
    }

    filters.push({
      id: item.field,
      value: normalizedValue,
      logic: item.logic,
    })
  }

  return filters
}

export function hasUserFilters(
  keyword: string,
  status: string,
  planId: string,
  advancedFilters: UserAdvancedFilterItem[] = [],
): boolean {
  return buildUserFilters(keyword, status, planId, advancedFilters).length > 0
}

export function summarizeUserFilters(
  keyword: string,
  status: string,
  planId: string,
  advancedFilters: UserAdvancedFilterItem[] = [],
  plans: AdminPlanOption[] = [],
): string[] {
  const summaries: string[] = []

  if (keyword.trim()) {
    summaries.push(`邮箱包含 ${keyword.trim()}`)
  }

  if (status === 'active') {
    summaries.push('快捷状态：正常')
  }

  if (status === 'banned') {
    summaries.push('快捷状态：封禁')
  }

  if (planId && planId !== 'all') {
    summaries.push(`快捷订阅：${getPlanNameById(plans, planId)}`)
  }

  for (const item of advancedFilters) {
    const definition = getUserAdvancedFieldDefinition(item.field)
    const operatorLabel = definition.operators.find((option) => option.value === item.operator)?.label ?? item.operator
    const prefix = item.logic === 'or' ? '或' : '且'
    const valueText = formatAdvancedFilterValue(item, plans)
    summaries.push(`${prefix} ${definition.label} ${operatorLabel} ${valueText}`.trim())
  }

  return summaries
}
