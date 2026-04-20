import { adminClient } from './client'
import type { ApiResponse, SystemStatus } from '@/types/api'

export function getSystemStatus(): Promise<ApiResponse<SystemStatus>> {
  return adminClient
    .get<ApiResponse<SystemStatus>>('/system/getSystemStatus')
    .then((res) => res.data)
}
