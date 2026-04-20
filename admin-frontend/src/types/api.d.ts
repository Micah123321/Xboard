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

export interface AdminPaginationResult<T> {
  data: T[]
  total: number
}

export interface AdminGroupOption {
  id: number
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

export interface AdminUserRef {
  id: number
  email: string
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

export interface AdminUserSort {
  id: string
  desc: boolean
}

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
