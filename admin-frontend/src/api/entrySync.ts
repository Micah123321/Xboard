import { adminClient } from './client'
import type { EntrySyncRun, EntrySyncSettingsPayload, EntrySyncSettingsResponse, EntrySyncStatus } from '@/types/entrySync'

const root = '/server/entry-sync'

export async function getEntrySyncSettings(): Promise<EntrySyncSettingsResponse> {
  const response = await adminClient.get<{ data: EntrySyncSettingsResponse }>(root + '/settings')
  return response.data.data
}

export async function saveEntrySyncSettings(payload: EntrySyncSettingsPayload): Promise<EntrySyncSettingsResponse> {
  const response = await adminClient.post<{ data: EntrySyncSettingsResponse }>(root + '/settings', payload)
  return response.data.data
}

export async function runEntrySync(dryRun: boolean): Promise<EntrySyncRun> {
  const response = await adminClient.post<{ data: EntrySyncRun }>(root + '/run', { dry_run: dryRun }, { timeout: 240000 })
  return response.data.data
}

export async function getEntrySyncStatus(signal?: AbortSignal): Promise<EntrySyncStatus> {
  const response = await adminClient.get<{ data: EntrySyncStatus }>(root + '/status', { signal })
  return response.data.data
}
