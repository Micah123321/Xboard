export interface AiCreditSettings {
  enabled: boolean
  title: string
  description: string
  site_url: string
  pricing_url: string
  transparency_url: string
  rate_usd_per_gb: string
  min_paid_cents: number
  include_balance: boolean
  legacy_enabled: boolean
  starts_at: number | null
  ends_at: number | null
  plan_ids: number[]
  bridge_url: string
  bridge_token_set: boolean
  provider_ready: boolean
  version: number
}
export interface AiCreditGrant {
  id: number
  user_id: number
  email?: string
  order_id: number
  trade_no: string
  plan_name: string
  traffic_gb: number | string
  paid_amount_cents: number
  amount_usd: string
  source: string
  status: string
  created_at: number | null
  issued_at: number | null
  redeemed_at: number | null
  last_error: string | null
}
export interface AiCreditSummary {
  eligible_count: number
  issued_count: number
  redeemed_count: number
  reconciling_count: number
  eligible_amount_usd: string
  issued_amount_usd: string
}
export interface AiCreditRecords { records: AiCreditGrant[]; total: number; summary: AiCreditSummary }
export interface AiCreditPreview {
  eligible_count: number
  total_amount_usd: string
  records: Pick<AiCreditGrant, 'user_id' | 'email' | 'order_id' | 'trade_no' | 'plan_name' | 'traffic_gb' | 'paid_amount_cents' | 'amount_usd'>[]
  truncated: boolean
}
