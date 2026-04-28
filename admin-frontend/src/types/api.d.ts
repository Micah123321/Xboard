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
  users_count?: number | string | null
  server_count?: number | string | null
}

export interface AdminServerGroupSavePayload {
  id?: number
  name: string
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

export type AdminGiftCardTemplateType = 1 | 2 | 3
export type AdminGiftCardCodeStatus = 0 | 1 | 2 | 3

export interface AdminGiftCardTemplateConditions {
  new_user_only?: boolean
  new_user_max_days?: number | null
  paid_user_only?: boolean
  allowed_plans?: number[]
  require_invite?: boolean
}

export interface AdminGiftCardTemplateRewards {
  balance?: number | null
  transfer_enable?: number | null
  expire_days?: number | null
  device_limit?: number | null
  reset_package?: boolean
  plan_id?: number | null
  plan_validity_days?: number | null
  invite_reward_rate?: number | null
  random_rewards?: Array<Record<string, unknown>>
}

export interface AdminGiftCardTemplateLimits {
  max_use_per_user?: number | null
  cooldown_hours?: number | null
}

export interface AdminGiftCardTemplateSpecialConfig {
  start_time?: number | null
  end_time?: number | null
  festival_bonus?: number | null
}

export interface AdminGiftCardTemplateItem {
  id: number
  name: string
  description?: string | null
  type: AdminGiftCardTemplateType
  type_name: string
  status: boolean | number
  conditions?: AdminGiftCardTemplateConditions | null
  rewards: AdminGiftCardTemplateRewards
  limits?: AdminGiftCardTemplateLimits | null
  special_config?: AdminGiftCardTemplateSpecialConfig | null
  icon?: string | null
  background_image?: string | null
  theme_color?: string | null
  sort?: number | null
  admin_id?: number | null
  created_at?: number | string | null
  updated_at?: number | string | null
  codes_count?: number
  used_count?: number
}

export interface AdminGiftCardTemplatePayload {
  id?: number
  name: string
  description?: string | null
  type: AdminGiftCardTemplateType
  status: boolean
  conditions?: AdminGiftCardTemplateConditions
  rewards: AdminGiftCardTemplateRewards
  limits?: AdminGiftCardTemplateLimits
  special_config?: AdminGiftCardTemplateSpecialConfig
  icon?: string | null
  background_image?: string | null
  theme_color?: string | null
  sort?: number
}

export interface AdminGiftCardCodeItem {
  id: number
  template_id: number
  template_name: string
  code: string
  batch_id?: string | null
  status: AdminGiftCardCodeStatus
  status_name: string
  user_id?: number | null
  user_email?: string | null
  used_at?: number | string | null
  expires_at?: number | string | null
  usage_count: number
  max_usage: number
  created_at?: number | string | null
}

export interface AdminGiftCardCodeGeneratePayload {
  template_id: number
  count: number
  prefix?: string
  expires_hours?: number | null
  max_usage?: number
}

export interface AdminGiftCardCodeUpdatePayload {
  id: number
  expires_at?: number | null
  max_usage?: number
  status?: AdminGiftCardCodeStatus
}

export interface AdminGiftCardUsageItem {
  id: number
  code: string
  template_name: string
  user_email: string
  invite_user_email?: string | null
  rewards_given?: Record<string, unknown> | null
  invite_rewards?: Record<string, unknown> | null
  multiplier_applied?: number | null
  created_at?: number | string | null
}

export interface AdminGiftCardStatisticsTotal {
  templates_count: number
  active_templates_count: number
  codes_count: number
  used_codes_count: number
  usages_count: number
}

export interface AdminGiftCardDailyUsage {
  date: string
  count: number
}

export interface AdminGiftCardTypeStat {
  template_name: string
  type_name: string
  count: number
}

export interface AdminGiftCardStatistics {
  total_stats: AdminGiftCardStatisticsTotal
  daily_usages: AdminGiftCardDailyUsage[]
  type_stats: AdminGiftCardTypeStat[]
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

export interface AdminOrderPaymentRef {
  id: number
  name: string
  payment: string
  icon?: string | null
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
  payment_channel?: string | null
  payment_method?: string | null
  payment_amount?: number | null
  payment_ip?: string | null
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
  payment?: AdminOrderPaymentRef | null
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
  online_count?: number | null
  last_online_at?: number | null
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

export interface AdminUserBulkScopePayload {
  scope?: 'selected' | 'filtered' | 'all'
  user_ids?: number[]
  filter?: AdminUserFilter[]
}

export interface AdminUserBulkMailPayload extends AdminUserBulkScopePayload {
  subject: string
  content: string
  sort?: string
  sort_type?: 'ASC' | 'DESC'
}

export interface AdminUserBulkBanPayload extends AdminUserBulkScopePayload {
  banned?: boolean | number
  sort?: string
  sort_type?: 'ASC' | 'DESC'
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

export type AdminNodeRouteAction = 'block' | 'direct' | 'dns' | 'proxy'

export interface AdminNodeRouteItem {
  id: number
  remarks: string
  match: string[]
  action: AdminNodeRouteAction
  action_value?: string | null
  created_at?: number | string | null
  updated_at?: number | string | null
}

export interface AdminNodeRouteSavePayload {
  id?: number
  remarks: string
  match: string[]
  action: AdminNodeRouteAction
  action_value?: string | null
}

export interface AdminNodeParentRef {
  id: number
  name: string
}

export type AdminNodeType =
  | 'shadowsocks'
  | 'vmess'
  | 'trojan'
  | 'hysteria'
  | 'vless'
  | 'tuic'
  | 'socks'
  | 'naive'
  | 'http'
  | 'mieru'
  | 'anytls'

export interface AdminNodeMetrics {
  active_connections?: number
  active_users?: number
  kernel_status?: boolean
  traffic_limit?: {
    enabled?: boolean
    limit?: number
    used?: number
    suspended?: boolean
    last_reset_at?: number
    next_reset_at?: number
    suspended_at?: number
    status?: string
  } | null
  updated_at?: number
}

export interface AdminNodeTrafficStats {
  today: TrafficAmount
  yesterday: TrafficAmount
  month: TrafficAmount
  total: TrafficAmount
}

export interface AdminNodeTrafficLimitSnapshot {
  enabled: boolean
  limit: number
  used: number
  percent: number
  suspended: boolean
  last_reset_at?: number
  cycle_start_at?: number
  next_reset_at?: number
  suspended_at?: number
  status?: string
  scope_key?: string
  scope_node_ids?: number[]
}

export interface AdminNodeRateTimeRange {
  start: string
  end: string
  rate: number
}

export interface AdminNodeItem {
  id: number
  name: string
  type: AdminNodeType | string
  code?: string | null
  host: string
  port: number | string | null
  server_port?: number | null
  group_ids?: Array<number | string> | null
  route_ids?: Array<number | string> | null
  tags?: string[] | null
  show: boolean
  auto_online?: boolean
  gfw_check_enabled?: boolean
  gfw_auto_hidden?: boolean
  gfw_auto_action_at?: number | null
  traffic_limit_enabled?: boolean
  traffic_limit_reset_day?: number | null
  traffic_limit_reset_time?: string | null
  traffic_limit_timezone?: string | null
  traffic_limit_status?: string | null
  traffic_limit_last_reset_at?: number | null
  traffic_limit_next_reset_at?: number | null
  traffic_limit_suspended_at?: number | null
  enabled?: boolean
  machine_id?: number | null
  parent_id?: number | null
  rate?: number | null
  transfer_enable?: number | null
  u?: number | null
  d?: number | null
  rate_time_enable?: boolean
  rate_time_ranges?: AdminNodeRateTimeRange[] | null
  sort?: number | null
  protocol_settings?: Record<string, unknown> | null
  online: number
  online_conn: number
  is_online: number
  available_status: number
  last_check_at?: number | null
  last_push_at?: number | null
  metrics?: AdminNodeMetrics | null
  traffic_stats?: AdminNodeTrafficStats | null
  traffic_limit_snapshot?: AdminNodeTrafficLimitSnapshot | null
  groups?: AdminServerGroupItem[]
  parent?: AdminNodeParentRef | null
  gfw_check?: AdminNodeGfwCheck | null
}

export type AdminNodeGfwStatus =
  | 'unchecked'
  | 'pending'
  | 'checking'
  | 'normal'
  | 'blocked'
  | 'partial'
  | 'failed'
  | 'skipped'

export interface AdminNodeGfwCheck {
  id?: number
  status: AdminNodeGfwStatus | string
  inherited?: boolean
  source_node_id?: number | null
  summary?: Record<string, unknown> | null
  operator_summary?: Record<string, unknown> | null
  error_message?: string | null
  checked_at?: number | null
  updated_at?: number | null
}

export interface AdminNodeGfwCheckResult {
  started: Array<{
    id: number
    check_id: number
    status: AdminNodeGfwStatus | string
  }>
  skipped: Array<{
    id: number
    status: AdminNodeGfwStatus | string
    reason?: string
    source_node_id?: number
  }>
  total: number
}

export interface AdminNodeUpdatePayload {
  id: number
  show?: boolean | number
  auto_online?: boolean
  gfw_check_enabled?: boolean
  enabled?: boolean
  machine_id?: number | null
}

export interface AdminNodeBatchUpdatePayload {
  ids: number[]
  host?: string
  rate?: number
  group_ids?: string[]
  auto_online?: boolean
  gfw_check_enabled?: boolean
}

export interface AdminNodeSavePayload {
  id?: number
  type: AdminNodeType
  code?: string
  name: string
  group_ids?: string[]
  route_ids?: string[]
  parent_id?: number | null
  enabled?: boolean
  host: string
  port: number | string
  server_port: number | string
  tags?: string[]
  rate: number
  rate_time_enable?: boolean
  rate_time_ranges?: AdminNodeRateTimeRange[]
  protocol_settings?: Record<string, unknown>
  show?: boolean | number
  auto_online?: boolean
  gfw_check_enabled?: boolean
  transfer_enable?: number | null
  traffic_limit_enabled?: boolean
  traffic_limit_reset_day?: number | null
  traffic_limit_reset_time?: string | null
  traffic_limit_timezone?: string | null
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
