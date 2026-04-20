import type { AdminUserListItem, AdminUserUpdatePayload } from '@/types/api'

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

export const COMMISSION_TYPE_OPTIONS = [
  { label: '跟随系统', value: 0 },
  { label: '周期返佣', value: 1 },
  { label: '一次性返佣', value: 2 },
] as const

const GIGABYTE = 1024 ** 3

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

export function buildUserFilters(keyword: string, status: string, planId: string): Array<{ id: string; value: string | number[] }> {
  const filters: Array<{ id: string; value: string | number[] }> = []

  if (keyword.trim()) {
    filters.push({ id: 'email', value: keyword.trim() })
  }

  if (status === 'active') {
    filters.push({ id: 'banned', value: [0] })
  }

  if (status === 'banned') {
    filters.push({ id: 'banned', value: [1] })
  }

  if (planId && planId !== 'all') {
    filters.push({ id: 'plan_id', value: [Number(planId)] })
  }

  return filters
}
