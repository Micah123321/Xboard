import { adminClient } from './client'
import type { Activity, ActivityInput, ActivityPage } from '@/types/activities'

export class ActivityConflict extends Error {}
interface Envelope<T> { status?: string; data: T; message?: string; error?: string }
async function request<T>(path: string, body?: unknown, params?: object, signal?: AbortSignal): Promise<T> {
  // Keep 409 available here; the shared error interceptor otherwise drops HTTP status.
  const response = await adminClient.request<Envelope<T>>({ url: '/activities/' + path, method: body ? 'POST' : 'GET', data: body, params, signal, validateStatus: status => (status >= 200 && status < 300) || status === 409 })
  if (response.status === 409) throw new ActivityConflict(response.data.message || '活动已被其他管理员更新')
  if (response.data.status && response.data.status !== 'success') throw new Error(response.data.message || response.data.error || '操作失败')
  return response.data.data
}
export const fetchActivities = (current = 1, page_size = 12, signal?: AbortSignal) => request<ActivityPage>('fetch', undefined, { current, page_size }, signal)
export const saveActivity = (input: ActivityInput, signal?: AbortSignal) => request<Activity | true>('save', input, undefined, signal)
export const publishActivity = (row: Activity, published: boolean, signal?: AbortSignal) => request<Activity | true>('publish', { id: row.id, version: row.version, published }, undefined, signal)
export const deleteActivity = (row: Activity, signal?: AbortSignal) => request<true>('delete', { id: row.id, version: row.version }, undefined, signal)
