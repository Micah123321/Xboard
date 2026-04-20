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
