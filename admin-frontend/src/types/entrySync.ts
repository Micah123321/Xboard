export interface EntrySyncAccount {
  id: string
  name: string
  username: string
  password_set: boolean
  enabled: boolean
}

export type EntrySyncAccountPayload = Omit<EntrySyncAccount, 'password_set'> & {
  password?: string
  clear_password?: boolean
}

export interface EntrySyncSettings {
  enabled: boolean
  base_url: string
  accounts?: EntrySyncAccount[]
  username?: string
  password_set?: boolean
  proxy: string
  timeout: number
  server_ids: number[]
  ports: number[]
}

export type EntrySyncSettingsPayload = Omit<EntrySyncSettings, 'accounts' | 'username' | 'password_set'> & {
  accounts: EntrySyncAccountPayload[]
}

export interface EntrySyncNode {
  id: number
  name: string
  parent_id: number | null
  host: string
  port: string
  type: string
  entry_points_count: number
}

export interface EntrySyncChange {
  server_id: number
  field: string
  port: number
  rule_id: string
  account_name?: string
  from: string
  to: string
}

export interface SyncResult {
  servers: number
  updated: number
  matched: number
  accounts_checked?: number
  account_summaries?: { id: string; name: string; rules: number }[]
  unchanged: number
  skipped: number
  changes: EntrySyncChange[]
  warnings: string[]
}

export interface EntrySyncRun {
  id: string
  status: 'running' | 'succeeded' | 'failed'
  dry_run: boolean
  source: 'manual' | 'schedule' | 'cli'
  started_at: number
  finished_at: number | null
  result: SyncResult | null
  error: string | null
}

export interface EntrySyncStatus {
  last_run: EntrySyncRun | null
  scheduler: { last_checked_at: number | null }
}

export interface EntrySyncSettingsResponse extends EntrySyncStatus {
  settings: EntrySyncSettings
  nodes: EntrySyncNode[]
}
