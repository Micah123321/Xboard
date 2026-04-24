import { adminClient } from './client'
import type {
  AdminConfigGroupKey,
  AdminConfigMappings,
  AdminNodeItem,
  AdminNodeUpdatePayload,
  AdminQueueFailedJobResult,
  AdminPaginationResult,
  AdminPlanListItem,
  AdminPlanSavePayload,
  AdminServerGroupItem,
  AdminTicketDetail,
  AdminTicketFetchParams,
  AdminTicketListItem,
  AdminTrafficLogResult,
  AdminUserFetchParams,
  AdminUserGeneratePayload,
  AdminUserListItem,
  AdminUserUpdatePayload,
  ApiResponse,
  DashboardStats,
  OrderTrendData,
  QueueStats,
  SystemStatus,
  TrafficRankResponse,
} from '@/types/api'

function unwrap<T>(url: string, params?: Record<string, unknown>): Promise<ApiResponse<T>> {
  return adminClient
    .get<ApiResponse<T>>(url, { params })
    .then((res) => res.data)
}

function unwrapPost<T>(url: string, data?: Record<string, unknown>): Promise<ApiResponse<T>> {
  return adminClient
    .post<ApiResponse<T>>(url, data)
    .then((res) => res.data)
}

function splitEmail(email: string): { email_prefix: string; email_suffix: string } {
  const normalized = email.trim()
  const atIndex = normalized.lastIndexOf('@')
  if (atIndex <= 0 || atIndex === normalized.length - 1) {
    throw new Error('请输入有效的邮箱地址')
  }

  return {
    email_prefix: normalized.slice(0, atIndex),
    email_suffix: normalized.slice(atIndex + 1),
  }
}

export function getDashboardStats(): Promise<ApiResponse<DashboardStats>> {
  return unwrap<DashboardStats>('/stat/getStats')
}

export function getOrderTrend(params: {
  startDate: string
  endDate: string
  type?: 'paid_total' | 'paid_count' | 'commission_total' | 'commission_count'
}): Promise<ApiResponse<OrderTrendData>> {
  return unwrap<OrderTrendData>('/stat/getOrder', {
    start_date: params.startDate,
    end_date: params.endDate,
    type: params.type,
  })
}

export function getTrafficRank(params: {
  type: 'node' | 'user'
  startTime: number
  endTime: number
  limit?: 10 | 20
}): Promise<TrafficRankResponse> {
  return adminClient
    .get<TrafficRankResponse>('/stat/getTrafficRank', {
      params: {
        type: params.type,
        start_time: params.startTime,
        end_time: params.endTime,
        limit: params.limit,
      },
    })
    .then((res) => res.data)
}

export function getSystemStatus(): Promise<ApiResponse<SystemStatus>> {
  return unwrap<SystemStatus>('/system/getSystemStatus')
}

export function getQueueStats(): Promise<ApiResponse<QueueStats>> {
  return unwrap<QueueStats>('/system/getQueueStats')
}

export function getHorizonFailedJobs(params: {
  current?: number
  pageSize?: number
} = {}): Promise<AdminQueueFailedJobResult> {
  return adminClient
    .get<AdminQueueFailedJobResult>('/system/getHorizonFailedJobs', {
      params: {
        current: params.current,
        page_size: params.pageSize,
      },
    })
    .then((res) => res.data)
}

export function getPlans(): Promise<ApiResponse<AdminPlanListItem[]>> {
  return unwrap<AdminPlanListItem[]>('/plan/fetch')
}

export function fetchAdminConfig(key?: AdminConfigGroupKey): Promise<ApiResponse<AdminConfigMappings>> {
  return unwrap<AdminConfigMappings>('/config/fetch', key ? { key } : undefined)
}

export function saveAdminConfig(payload: Record<string, unknown>): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/config/save', payload)
}

export function testAdminMail(): Promise<ApiResponse<Record<string, unknown>>> {
  return unwrapPost<Record<string, unknown>>('/config/testSendMail', {})
}

export function setTelegramWebhook(payload: {
  telegram_bot_token?: string
} = {}): Promise<ApiResponse<Record<string, unknown>>> {
  return unwrapPost<Record<string, unknown>>('/config/setTelegramWebhook', payload)
}

export function savePlan(payload: AdminPlanSavePayload): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plan/save', payload as unknown as Record<string, unknown>)
}

export function updatePlan(id: number, payload: Partial<Pick<AdminPlanListItem, 'show' | 'renew' | 'sell'>>): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plan/update', {
    id,
    ...payload,
  })
}

export function deletePlan(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plan/drop', { id })
}

export function sortPlans(ids: number[]): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plan/sort', { ids })
}

export function getServerGroups(): Promise<ApiResponse<AdminServerGroupItem[]>> {
  return unwrap<AdminServerGroupItem[]>('/server/group/fetch')
}

export function fetchNodes(): Promise<ApiResponse<AdminNodeItem[]>> {
  return unwrap<AdminNodeItem[]>('/server/manage/getNodes')
}

export function updateNode(payload: AdminNodeUpdatePayload): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/server/manage/update', payload as unknown as Record<string, unknown>)
}

export function copyNode(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/server/manage/copy', { id })
}

export function deleteNode(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/server/manage/drop', { id })
}

export function fetchUsers(params: AdminUserFetchParams): Promise<AdminPaginationResult<AdminUserListItem>> {
  return adminClient
    .get<AdminPaginationResult<AdminUserListItem>>('/user/fetch', { params })
    .then((res) => res.data)
}

export function getUserById(id: number): Promise<ApiResponse<AdminUserListItem>> {
  return unwrap<AdminUserListItem>('/user/getUserInfoById', { id })
}

export function createUser(payload: AdminUserGeneratePayload): Promise<ApiResponse<boolean>> {
  const email = splitEmail(payload.email)
  return unwrapPost<boolean>('/user/generate', {
    ...email,
    password: payload.password,
    plan_id: payload.plan_id,
    expired_at: payload.expired_at,
  })
}

export function updateUser(payload: AdminUserUpdatePayload): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/user/update', payload as unknown as Record<string, unknown>)
}

export function resetUserSecret(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/user/resetSecret', { id })
}

export function deleteUser(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/user/destroy', { id })
}

export function fetchTickets(params: AdminTicketFetchParams): Promise<AdminPaginationResult<AdminTicketListItem>> {
  return adminClient
    .get<AdminPaginationResult<AdminTicketListItem>>('/ticket/fetch', { params })
    .then((res) => res.data)
}

export function getTicketById(id: number): Promise<ApiResponse<AdminTicketDetail>> {
  return unwrap<AdminTicketDetail>('/ticket/fetch', { id })
}

export function replyTicket(id: number, message: string): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/ticket/reply', { id, message })
}

export function closeTicket(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/ticket/close', { id })
}

export function fetchUserTrafficLogs(params: {
  userId: number
  pageSize?: number
  page?: number
  minTotal?: number
  startTime?: number
  endTime?: number
}): Promise<AdminTrafficLogResult> {
  return adminClient
    .get<AdminTrafficLogResult>('/stat/getStatUser', {
      params: {
        user_id: params.userId,
        pageSize: params.pageSize,
        page: params.page,
        min_total: params.minTotal,
        start_time: params.startTime,
        end_time: params.endTime,
      },
    })
    .then((res) => res.data)
}
