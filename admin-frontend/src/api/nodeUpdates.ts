import { adminClient } from './client'
import type { Page, Settings, Policy, Release, ReleaseInput, Installation, Batch, BatchInput, Task, TaskState, UpdateEvent, Preview, Scope, Enrollment, Coverage, Discovery, DiscoveryFilters } from '@/types/nodeUpdates'

export class UpdateError extends Error {
 code: string
 retryable: boolean
 status: number
 excluded: Preview['excluded']
 constructor(code: string, message: string, retryable: boolean, status: number, excluded: Preview['excluded'] = []) {
  super(message); this.code = code; this.retryable = retryable; this.status = status; this.excluded = excluded
 }
}
const root = '/server/update'
async function request<T>(method: string, path: string, data?: unknown, key?: string, params?: object, signal?: AbortSignal): Promise<T> {
 // Accept HTTP responses here so the shared legacy interceptor does not discard protocol error codes.
 const response = await adminClient.request({ method, url: root + path, data, params, signal,
  validateStatus: () => true, headers: key ? { 'Idempotency-Key': key } : undefined })
 if (response.status < 200 || response.status >= 300) {
  const error = response.data?.error
  throw new UpdateError(error?.code || 'request_failed', error?.message || '节点更新请求失败', error?.retryable === true, response.status, error?.excluded || response.data?.excluded || [])
 }
 return (path === '/discoveries' ? response.data.data ?? response.data : response.data.data) as T
}
export const updates = {
 discoveries: (page: number, filters: DiscoveryFilters, signal?: AbortSignal) => request<Page<Discovery>>('GET', '/discoveries', undefined, undefined, { page, page_size: 20, ...filters }, signal),
 settings: (signal?: AbortSignal) => request<Settings>('GET', '/settings', undefined, undefined, undefined, signal),
 saveSettings: (data: Settings, key: string) => request<Settings>('PATCH', '/settings', data, key),
 releases: (page: number, signal?: AbortSignal) => request<Page<Release>>('GET', '/releases', undefined, undefined, { page, page_size: 20 }, signal),
 publish: (data: ReleaseInput, key: string) => request<Release>('POST', '/releases', data, key),
 revokeRelease: (id: string, key: string) => request<Release>('POST', '/releases/' + id + '/revoke', {}, key),
 enroll: (scope: Scope, key: string) => request<Enrollment>('POST', '/enrollments', { scope }, key),
 installations: (page: number, filters: object, signal?: AbortSignal) => request<Page<Installation>>('GET', '/installations', undefined, undefined, { page, page_size: 20, ...filters }, signal),
 revokeInstallation: (id: string, key: string) => request<{ id: string; revoked_at: string }>('POST', '/installations/' + id + '/revoke', {}, key),
 policy: (id: string, data: Policy, key: string) => request<Policy>('PATCH', '/installations/' + id + '/policy', data, key),
 preview: (data: Pick<BatchInput, 'release_id' | 'installation_ids'>) => request<Preview>('POST', '/batches/preview', data),
 createBatch: (data: BatchInput, key: string) => request<Batch>('POST', '/batches', data, key),
 batches: (page: number, signal?: AbortSignal) => request<Page<Batch>>('GET', '/batches', undefined, undefined, { page, page_size: 20 }, signal),
 batch: (id: string, signal?: AbortSignal) => request<Batch>('GET', '/batches/' + id, undefined, undefined, undefined, signal),
 batchAction: (id: string, action: 'pause' | 'resume' | 'cancel', key: string) => request<Batch>('POST', '/batches/' + id + '/' + action, {}, key),
 tasks: (id: string, page: number, state?: TaskState, signal?: AbortSignal) => request<Page<Task>>('GET', '/batches/' + id + '/tasks', undefined, undefined, { page, page_size: 20, ...(state ? { state } : {}) }, signal),
 events: (id: string, page: number, signal?: AbortSignal) => request<Page<UpdateEvent>>('GET', '/tasks/' + id + '/events', undefined, undefined, { page, page_size: 20 }, signal),
 resolve: (id: string, data: { state: 'failed' | 'rolled_back' | 'rollback_failed'; reason: string; agent_stopped: true }, key: string) => request<Task>('POST', '/tasks/' + id + '/resolve', data, key),
 coverage: (params: { machine_id: number } | { node_id: number }) => request<Coverage>('GET', '/coverage', undefined, undefined, params),
}

// A failed submission retains its key for the same payload; success ends that operation.
export function createIdempotencyKeys() {
 const keys = new Map<string, string>()
 return {
  get(operation: string, payload: unknown) { const fingerprint = JSON.stringify([operation, payload]); let key = keys.get(fingerprint); if (!key) { key = crypto.randomUUID(); keys.set(fingerprint, key) } return key },
  clear(operation: string, payload: unknown) { keys.delete(JSON.stringify([operation, payload])) },
 }
}
