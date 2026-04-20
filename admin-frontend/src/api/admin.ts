import { adminClient } from './client'
import type {
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
}): Promise<TrafficRankResponse> {
  return adminClient
    .get<TrafficRankResponse>('/stat/getTrafficRank', {
      params: {
        type: params.type,
        start_time: params.startTime,
        end_time: params.endTime,
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
