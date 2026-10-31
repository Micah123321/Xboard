import { adminClient } from './client'
import type { ApiResponse } from '@/types/api'
import type { AiTopupSettings, AiTopupSettingsInput, AiTopupFilters, AiTopupRecords, AiTopupOrder } from '@/types/aiTopup'

function data<T>(response: ApiResponse<T>): T {
  if (response.status && response.status !== 'success') throw new Error(response.message || response.error || '操作失败')
  return response.data
}
export const getAiTopupSettings = async () => data((await adminClient.get<ApiResponse<AiTopupSettings>>('/ai-topup/settings')).data)
export const saveAiTopupSettings = async (input: AiTopupSettingsInput) => data((await adminClient.post<ApiResponse<AiTopupSettings>>('/ai-topup/settings', input)).data)
export const getAiTopupRecords = async (filters: AiTopupFilters) => {
  const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
  return data((await adminClient.get<ApiResponse<AiTopupRecords>>('/ai-topup/records', { params })).data)
}
export const reconcileAiTopup = async (id: number) => data((await adminClient.post<ApiResponse<AiTopupOrder>>('/ai-topup/reconcile', { id }, { timeout: 45000 })).data)
