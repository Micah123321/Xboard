export interface ApiResponse<T = unknown> {
  status?: 'success' | 'fail' | string
  message?: string
  data: T
  error?: string | null
  code?: number
}

export interface LoginRequest {
  email: string
  password: string
}

export interface LoginResponse {
  token: string
  auth_data: string
  is_admin: boolean
}

export interface TrafficAmount {
  upload: number
  download: number
  total: number
}

export interface DashboardStats {
  todayIncome: number
  dayIncomeGrowth: number
  currentMonthIncome: number
  lastMonthIncome: number
  monthIncomeGrowth: number
  lastMonthIncomeGrowth: number
  currentMonthCommissionPayout: number
  lastMonthCommissionPayout: number
  commissionGrowth: number
  commissionPendingTotal: number
  currentMonthNewUsers: number
  totalUsers: number
  activeUsers: number
  userGrowth: number
  onlineUsers: number
  onlineDevices: number
  ticketPendingTotal: number
  onlineNodes: number
  todayTraffic: TrafficAmount
  monthTraffic: TrafficAmount
  totalTraffic: TrafficAmount
}

export interface OrderTrendPoint {
  date: string
  paid_total: number
  paid_count: number
  commission_total: number
  commission_count: number
  avg_order_amount: number
  avg_commission_amount: number
  value?: number
  type?: string
}

export interface OrderTrendSummary {
  paid_total: number
  paid_count: number
  commission_total: number
  commission_count: number
  start_date: string
  end_date: string
  avg_paid_amount: number
  avg_commission_amount: number
  commission_rate: number
}

export interface OrderTrendData {
  list: OrderTrendPoint[]
  summary: OrderTrendSummary
}

export interface TrafficRankItem {
  id: string
  name: string
  value: number
  previousValue: number
  change: number
  timestamp: string
}

export interface TrafficRankResponse {
  timestamp: string
  data: TrafficRankItem[]
}

export interface SystemStatus {
  schedule: boolean
  horizon: boolean
  schedule_last_runtime: number | string | null
}

export interface QueueWaitEntry {
  [key: string]: string | number | null | undefined
}

export interface QueueStats {
  failedJobs: number
  jobsPerMinute: number
  pausedMasters: number
  periods: {
    failedJobs: number
    recentJobs: number
  }
  processes: number
  queueWithMaxRuntime?: string | null
  queueWithMaxThroughput?: string | null
  recentJobs: number
  status: boolean
  wait?: QueueWaitEntry[]
}

export interface AdminQueueFailedJob {
  id?: number | string | null
  uuid?: string | null
  name?: string | null
  queue?: string | null
  connection?: string | null
  exception?: string | null
  failed_at?: number | string | null
  payload?: unknown
  [key: string]: unknown
}

export interface AdminQueueFailedJobResult extends AdminPaginationResult<AdminQueueFailedJob> {
  current?: number
  page_size?: number
}

export interface AdminPaginationResult<T> {
  data: T[]
  total: number
  current_page?: number
  per_page?: number
  last_page?: number
}

export interface AdminTableSort {
  id: string
  desc: boolean
}

export interface AdminGroupOption {
  id: number
  name: string
}

export interface AdminServerGroupItem extends AdminGroupOption {
  users_count?: number
  server_count?: number
}

export interface AdminPlanOption {
  id: number
  name: string
  sort?: number
  transfer_enable?: number | null
  group_id?: number | null
  users_count?: number
  active_users_count?: number
  group?: AdminGroupOption | null
}

export type AdminConfigGroupKey =
  | 'invite'
  | 'site'
  | 'subscribe'
  | 'frontend'
  | 'server'
  | 'email'
  | 'telegram'
  | 'app'
  | 'safe'
  | 'subscribe_template'

export type AdminConfigGroupValue = Record<string, unknown>

export type AdminConfigMappings = Partial<Record<AdminConfigGroupKey, AdminConfigGroupValue>>

export interface AdminKnowledgeListItem {
  id: number
  title: string
  updated_at: number | string | null
  category: string | null
  show: boolean
}

export interface AdminKnowledgeDetail extends AdminKnowledgeListItem {
  body: string
  language: string
  sort?: number | null
  created_at?: number | string | null
}

export interface AdminKnowledgeSavePayload {
  id?: number
  category: string
  language: string
  title: string
  body: string
  show: boolean
}

export type AdminPluginTypeValue = 'feature' | 'payment'

export interface AdminPluginTypeItem {
  value: AdminPluginTypeValue
  label: string
  description: string
  icon?: string
}

export interface AdminPluginConfigOption {
  label: string
  value: string | number | boolean
}

export interface AdminPluginConfigField {
  type: string
  label: string
  placeholder: string
  description: string
  value: unknown
  options: AdminPluginConfigOption[] | Record<string, string> | Array<string | number | boolean>
}

export interface AdminPluginItem {
  code: string
  name: string
  version: string
  description: string
  author: string
  type: AdminPluginTypeValue | string
  is_installed: boolean
  is_enabled: boolean
  is_protected: boolean
  can_be_deleted: boolean
  config: Record<string, AdminPluginConfigField>
  readme: string
  need_upgrade: boolean
  admin_menus?: unknown
  admin_crud?: unknown
}

export type AdminThemeFieldType = 'input' | 'textarea' | 'select'

export interface AdminThemeConfigField {
  label: string
  placeholder?: string
  field_name: string
  field_type: AdminThemeFieldType
  select_options?: Record<string, string>
  default_value?: string | number | boolean | null
}

export interface AdminThemeSummary {
  name: string
  description?: string
  version?: string
  images?: string
  configs?: AdminThemeConfigField[]
  can_delete?: boolean
  is_system?: boolean
}

export interface AdminThemeListResult {
  themes: Record<string, AdminThemeSummary>
  active: string
}

export type AdminThemeConfigRecord = Record<string, string | number | boolean | null>

export interface AdminPlanPriceMap {
  monthly?: number | null
  quarterly?: number | null
  half_yearly?: number | null
  yearly?: number | null
  two_yearly?: number | null
  three_yearly?: number | null
  onetime?: number | null
  reset_traffic?: number | null
  [key: string]: number | null | undefined
}

export interface AdminPlanListItem extends AdminPlanOption {
  show: boolean
  renew: boolean
  sell: boolean
  prices?: AdminPlanPriceMap | null
  tags?: string[] | null
  content?: string | null
  reset_traffic_method?: number | null
  capacity_limit?: number | null
  device_limit?: number | null
  speed_limit?: number | null
  sort: number
  created_at: number
  updated_at: number
}

export interface AdminPlanSavePayload {
  id?: number
  name: string
  content?: string
  reset_traffic_method?: number | null
  transfer_enable: number
  prices?: AdminPlanPriceMap
  group_id?: number | null
  speed_limit?: number | null
  device_limit?: number | null
  capacity_limit?: number | null
  tags?: string[]
  force_update?: boolean
}

export interface AdminNoticeItem {
  id: number
  title: string
  content: string
  img_url?: string | null
  tags?: string[] | null
  show: boolean | number
  popup?: boolean | number | null
  sort?: number | null
  created_at?: number | string | null
  updated_at?: number | string | null
}

export interface AdminNoticeSavePayload {
  id?: number
  title: string
  content: string
  img_url?: string | null
  tags?: string[]
  show?: boolean
  popup?: boolean
}

export interface AdminPaymentConfigField {
  type: string
  label: string
  placeholder: string
  description: string
  value: string
  options: AdminPluginConfigOption[] | Record<string, string> | Array<string | number | boolean>
}

export type AdminPaymentConfigFields = Record<string, AdminPaymentConfigField>

export interface AdminPaymentListItem {
  id: number
  uuid: string
  payment: string
  name: string
  icon?: string | null
  config?: Record<string, unknown> | null
  notify_domain?: string | null
  notify_url?: string | null
  handling_fee_fixed?: number | null
  handling_fee_percent?: number | string | null
  enable: boolean | number
  sort?: number | null
  created_at?: number | string | null
  updated_at?: number | string | null
}

export interface AdminPaymentSavePayload {
  id?: number
  name: string
  icon?: string | null
  payment: string
  config: Record<string, string>
  notify_domain?: string | null
  handling_fee_fixed?: number | null
  handling_fee_percent?: number | null
}

export type AdminCouponType = 1 | 2

export interface AdminCouponListItem {
  id: number
  show: boolean
  name: string
  type: AdminCouponType
  value: number
  code: string
  limit_use?: number | null
  limit_use_with_user?: number | null
  limit_plan_ids?: string[] | null
  limit_period?: string[] | null
  started_at: number
  ended_at: number
  created_at: number
  updated_at: number
}

export interface AdminCouponFetchParams {
  current?: number
  pageSize?: number
}

export interface AdminCouponGeneratePayload {
  id?: number
  generate_count?: number
  name: string
  type: AdminCouponType
  value: number
  started_at: number
  ended_at: number
  limit_use?: number | null
  limit_use_with_user?: number | null
  limit_plan_ids?: number[]
  limit_period?: string[]
  code?: string
}

export interface AdminUserRef {
  id: number
  email: string
}

export interface AdminOrderUserRef {
  id: number
  email: string
  balance?: number | null
  commission_balance?: number | null
  plan_id?: number | null
}

export interface AdminCommissionLogItem {
  id: number
  invite_user_id: number
  user_id: number
  trade_no: string
  order_amount: number
  get_amount: number
  created_at: number
  updated_at: number
}

export interface AdminOrderListItem {
  id: number
  invite_user_id?: number | null
  user_id: number
  plan_id: number | null
  coupon_id?: number | null
  payment_id?: number | null
  type: number
  period: string
  trade_no: string
  callback_no?: string | null
  total_amount: number
  handling_amount?: number | null
  discount_amount?: number | null
  surplus_amount?: number | null
  refund_amount?: number | null
  balance_amount?: number | null
  surplus_order_ids?: number[] | null
  status: number
  commission_status?: number | null
  commission_balance?: number | null
  actual_commission_balance?: number | null
  paid_at?: number | null
  created_at: number
  updated_at: number
  plan?: AdminPlanOption | null
}

export interface AdminOrderDetail extends AdminOrderListItem {
  user?: AdminOrderUserRef | null
  invite_user?: AdminOrderUserRef | null
  commission_log?: AdminCommissionLogItem[]
  surplus_orders?: AdminOrderListItem[]
}

export interface AdminOrderFilter {
  id: string
  value: string | number | Array<string | number>
}

export interface AdminOrderFetchParams {
  current: number
  pageSize: number
  filter?: AdminOrderFilter[]
  sort?: AdminTableSort[]
  is_commission?: boolean
}

export interface AdminOrderAssignPayload {
  email: string
  plan_id: number
  period: string
  total_amount: number
}

export interface AdminUserListItem {
  id: number
  email: string
  token: string
  uuid: string
  plan_id: number | null
  group_id: number | null
  transfer_enable: number
  u: number
  d: number
  total_used: number
  expired_at: number | null
  balance: number
  commission_balance: number
  commission_rate: number | null
  commission_type: number | null
  discount: number | null
  speed_limit: number | null
  device_limit: number | null
  remarks: string | null
  banned: boolean
  is_admin: boolean
  is_staff: boolean
  created_at: number
  updated_at: number
  subscribe_url: string
  plan?: AdminPlanOption | null
  group?: AdminGroupOption | null
  invite_user?: AdminUserRef | null
}

export interface AdminUserFilter {
  id: string
  value: string | number | boolean | Array<string | number>
  logic?: 'and' | 'or'
}

export type AdminUserSort = AdminTableSort

export interface AdminUserFetchParams {
  current: number
  pageSize: number
  filter?: AdminUserFilter[]
  sort?: AdminUserSort[]
}

export interface AdminUserGeneratePayload {
  email: string
  password: string
  plan_id?: number | null
  expired_at?: number | null
}

export interface AdminUserUpdatePayload {
  id: number
  email?: string
  password?: string
  transfer_enable?: number
  expired_at?: number | null
  banned?: boolean | number
  plan_id?: number | null
  commission_rate?: number | null
  discount?: number | null
  is_admin?: boolean
  is_staff?: boolean
  u?: number
  d?: number
  balance?: number
  commission_type?: number | null
  commission_balance?: number
  remarks?: string | null
  speed_limit?: number | null
  device_limit?: number | null
  invite_user_email?: string | null
}

export interface AdminTicketMessage {
  id: number
  ticket_id: number
  user_id: number
  message: string
  created_at: number
  updated_at: number
  is_from_user: boolean
  is_from_admin: boolean
}

export interface AdminTicketListItem {
  id: number
  user_id: number
  subject: string
  level: string | number | null
  status: number
  reply_status: number | null
  last_reply_user_id: number | null
  created_at: number
  updated_at: number
  user: AdminUserListItem
}

export interface AdminTicketDetail extends AdminTicketListItem {
  messages: AdminTicketMessage[]
}

export interface AdminTicketFetchParams {
  current?: number
  pageSize?: number
  status?: number
  reply_status?: number[]
  email?: string
  filter?: AdminUserFilter[]
  sort?: AdminUserSort[]
}

export interface AdminTrafficLogItem {
  id: number
  user_id?: number
  d: number
  u: number
  record_at: number
  display_at: number
  record_type: string | null
  server_rate: number
  server_id: number | null
  server_type: string | null
  server_name: string | null
  node_name: string | null
  node_key: string | null
  device_name: string
  device_ips: string[]
  device_count: number
  created_at: number | string | null
  updated_at: number | string | null
}

export interface AdminTrafficLogResult extends AdminPaginationResult<AdminTrafficLogItem> {
  summary: TrafficAmount
}

export interface AdminNodeParentRef {
  id: number
  name: string
}

export interface AdminNodeMetrics {
  active_connections?: number
  active_users?: number
  kernel_status?: boolean
  updated_at?: number
}

export interface AdminNodeItem {
  id: number
  name: string
  type: string
  host: string
  port: number | string | null
  server_port?: number | null
  group_ids?: Array<number | string> | null
  route_ids?: Array<number | string> | null
  show: boolean
  enabled?: boolean
  parent_id?: number | null
  rate?: number | null
  sort?: number | null
  online: number
  online_conn: number
  is_online: number
  available_status: number
  last_check_at?: number | null
  last_push_at?: number | null
  metrics?: AdminNodeMetrics | null
  groups?: AdminServerGroupItem[]
  parent?: AdminNodeParentRef | null
}

export interface AdminNodeUpdatePayload {
  id: number
  show?: boolean | number
  enabled?: boolean
  machine_id?: number | null
}

declare global {
  interface Window {
    settings?: {
      secure_path?: string
      title?: string
      logo?: string
      version?: string
      theme_sidebar?: string
      theme_header?: string
      theme_color?: string
      background_url?: string
    }
  }
}
