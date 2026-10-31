import { adminClient } from './client'
import type { ApiResponse } from '@/types/api'
import type { AiCreditGrant, AiCreditPreview, AiCreditRecords, AiCreditSettings } from '@/types/aiCredit'

function data<T>(response: ApiResponse<T>): T {
  if (response.status && response.status !== 'success') throw new Error(response.message || response.error || '操作失败')
  return response.data
}
const get = async <T>(path: string, params?: Record<string, unknown>) => data((await adminClient.get<ApiResponse<T>>(path, { params })).data)
const post = async <T>(path: string, body: unknown) => data((await adminClient.post<ApiResponse<T>>(path, body, { timeout: 45000 })).data)
export const getAiCreditSettings = () => get<AiCreditSettings>('/ai-credit/settings')
export const saveAiCreditSettings = (settings: AiCreditSettings, token: string) => post<AiCreditSettings>('/ai-credit/settings', { ...settings, ...(token.trim() ? { bridge_token: token.trim() } : {}) })
export const getAiCreditRecords = (params: { current: number; page_size: number; status: string; user_id: string }) => get<AiCreditRecords>('/ai-credit/records', params)
export const reconcileAiCredit = (grant_id: number) => post<AiCreditGrant>('/ai-credit/reconcile', { grant_id })
export const revokeAiCredit = (grant_id: number, reason: string) => post<AiCreditGrant>('/ai-credit/revoke', { grant_id, reason })
export const previewAiCredit = (limit: number) => post<AiCreditPreview>('/ai-credit/legacy/preview', { limit })
export const backfillAiCredit = (limit: number) => post<{ created: number; skipped: number; has_more: boolean }>('/ai-credit/legacy/backfill', { limit })
