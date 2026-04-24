import { adminClient } from './client'
import type {
  AdminCouponFetchParams,
  AdminCouponGeneratePayload,
  AdminCouponListItem,
  AdminConfigGroupKey,
  AdminConfigMappings,
  AdminOrderAssignPayload,
  AdminOrderDetail,
  AdminOrderFetchParams,
  AdminOrderListItem,
  AdminKnowledgeDetail,
  AdminKnowledgeListItem,
  AdminKnowledgeSavePayload,
  AdminNoticeItem,
  AdminNoticeSavePayload,
  AdminNodeItem,
  AdminNodeUpdatePayload,
  AdminPaymentConfigFields,
  AdminPaymentListItem,
  AdminPaymentSavePayload,
  AdminQueueFailedJobResult,
  AdminPaginationResult,
  AdminPlanListItem,
  AdminPlanSavePayload,
  AdminThemeConfigRecord,
  AdminThemeListResult,
  AdminPluginItem,
  AdminPluginTypeItem,
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

export function fetchOrders(params: AdminOrderFetchParams): Promise<AdminPaginationResult<AdminOrderListItem>> {
  return adminClient
    .get<AdminPaginationResult<AdminOrderListItem>>('/order/fetch', { params })
    .then((res) => res.data)
}

export function getOrderDetail(id: number): Promise<ApiResponse<AdminOrderDetail>> {
  return unwrapPost<AdminOrderDetail>('/order/detail', { id })
}

export function assignOrder(payload: AdminOrderAssignPayload): Promise<ApiResponse<string>> {
  return unwrapPost<string>('/order/assign', payload as unknown as Record<string, unknown>)
}

export function markOrderPaid(tradeNo: string): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/order/paid', { trade_no: tradeNo })
}

export function cancelOrder(tradeNo: string): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/order/cancel', { trade_no: tradeNo })
}

export function updateOrderCommissionStatus(tradeNo: string, commissionStatus: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/order/update', {
    trade_no: tradeNo,
    commission_status: commissionStatus,
  })
}

export function getThemes(): Promise<ApiResponse<AdminThemeListResult>> {
  return unwrap<AdminThemeListResult>('/theme/getThemes')
}

export function getThemeConfig(name: string): Promise<ApiResponse<AdminThemeConfigRecord>> {
  return unwrapPost<AdminThemeConfigRecord>('/theme/getThemeConfig', { name })
}

export function saveThemeConfig(
  name: string,
  config: AdminThemeConfigRecord,
): Promise<ApiResponse<AdminThemeConfigRecord>> {
  return unwrapPost<AdminThemeConfigRecord>('/theme/saveThemeConfig', { name, config })
}

export function uploadTheme(file: File): Promise<ApiResponse<boolean>> {
  const formData = new FormData()
  formData.append('file', file)
  return adminClient
    .post<ApiResponse<boolean>>('/theme/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    .then((res) => res.data)
}

export function fetchCoupons(params: AdminCouponFetchParams = {}): Promise<AdminPaginationResult<AdminCouponListItem>> {
  return adminClient
    .get<AdminPaginationResult<AdminCouponListItem>>('/coupon/fetch', {
      params: {
        current: params.current,
        pageSize: params.pageSize,
      },
    })
    .then((res) => res.data)
}

export function saveCoupon(payload: AdminCouponGeneratePayload): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/coupon/generate', payload as unknown as Record<string, unknown>)
}

export function updateCoupon(id: number, payload: Pick<AdminCouponListItem, 'show'>): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/coupon/update', {
    id,
    ...payload,
  })
}

export function deleteCoupon(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/coupon/drop', { id })
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

export function getKnowledges(): Promise<ApiResponse<AdminKnowledgeListItem[]>> {
  return unwrap<AdminKnowledgeListItem[]>('/knowledge/fetch')
}

export function getKnowledgeById(id: number): Promise<ApiResponse<AdminKnowledgeDetail>> {
  return unwrap<AdminKnowledgeDetail>('/knowledge/fetch', { id })
}

export function getKnowledgeCategories(): Promise<ApiResponse<string[]>> {
  return unwrap<string[]>('/knowledge/getCategory')
}

export function saveKnowledge(payload: AdminKnowledgeSavePayload): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/knowledge/save', payload as unknown as Record<string, unknown>)
}

export function toggleKnowledgeVisibility(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/knowledge/show', { id })
}

export function deleteKnowledge(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/knowledge/drop', { id })
}

export function sortKnowledges(ids: number[]): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/knowledge/sort', { ids })
}

export function fetchNotices(): Promise<ApiResponse<AdminNoticeItem[]>> {
  return unwrap<AdminNoticeItem[]>('/notice/fetch')
}

export function saveNotice(payload: AdminNoticeSavePayload): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/notice/save', payload as unknown as Record<string, unknown>)
}

export function toggleNoticeVisibility(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/notice/show', { id })
}

export function deleteNotice(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/notice/drop', { id })
}

export function sortNotices(ids: number[]): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/notice/sort', { ids })
}

export function fetchPayments(): Promise<ApiResponse<AdminPaymentListItem[]>> {
  return unwrap<AdminPaymentListItem[]>('/payment/fetch')
}

export function getPaymentMethods(): Promise<ApiResponse<string[]>> {
  return unwrap<string[]>('/payment/getPaymentMethods')
}

export function getPaymentForm(payload: {
  payment: string
  id?: number
}): Promise<ApiResponse<AdminPaymentConfigFields>> {
  return unwrapPost<AdminPaymentConfigFields>('/payment/getPaymentForm', payload as unknown as Record<string, unknown>)
}

export function savePayment(payload: AdminPaymentSavePayload): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/payment/save', payload as unknown as Record<string, unknown>)
}

export function togglePaymentVisibility(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/payment/show', { id })
}

export function deletePayment(id: number): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/payment/drop', { id })
}

export function sortPayments(ids: number[]): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/payment/sort', { ids })
}

export function getPluginTypes(): Promise<ApiResponse<AdminPluginTypeItem[]>> {
  return unwrap<AdminPluginTypeItem[]>('/plugin/types')
}

export function getPlugins(params: {
  type?: string
} = {}): Promise<ApiResponse<AdminPluginItem[]>> {
  return unwrap<AdminPluginItem[]>('/plugin/getPlugins', params)
}

export function installPlugin(code: string): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plugin/install', { code })
}

export function uninstallPlugin(code: string): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plugin/uninstall', { code })
}

export function enablePlugin(code: string): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plugin/enable', { code })
}

export function disablePlugin(code: string): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plugin/disable', { code })
}

export function getPluginConfig(code: string): Promise<ApiResponse<Record<string, unknown>>> {
  return unwrap<Record<string, unknown>>('/plugin/config', { code })
}

export function savePluginConfig(code: string, config: Record<string, unknown>): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plugin/config', { code, config })
}

export function upgradePlugin(code: string): Promise<ApiResponse<boolean>> {
  return unwrapPost<boolean>('/plugin/upgrade', { code })
}

export function uploadPluginPackage(file: File): Promise<ApiResponse<boolean>> {
  const formData = new FormData()
  formData.append('file', file)

  return adminClient
    .post<ApiResponse<boolean>>('/plugin/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    .then((res) => res.data)
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
