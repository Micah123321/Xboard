export interface AiTopupTier { min_usd: number; unit_price_cny: string }
export interface AiTopupSettings {
  enabled: boolean
  provider_ready: boolean
  tiers: AiTopupTier[]
  min_cny_cents?: number
  max_cny_cents?: number
  effective_min_usd?: number | null
  effective_max_usd?: number | null
  min_usd: number
  max_usd: number
  bonus_rate_bps: number
  traffic_gb_per_cny: number
  order_ttl_minutes: number
  site_url: string
  pricing_url: string
  transparency_url: string
  version: number
}
export type AiTopupSettingsInput = Pick<AiTopupSettings, 'enabled' | 'tiers' | 'min_usd' | 'max_usd' | 'bonus_rate_bps' | 'traffic_gb_per_cny' | 'order_ttl_minutes'>
export interface AiTopupOrder {
  id: number
  user_id: number
  email?: string
  trade_no: string
  status: 'pending' | 'cancelled' | 'paid'
  issue_status: 'pending' | 'generating' | 'reconciling' | 'issued' | 'redeemed' | 'revoked'
  amount_usd: string
  unit_price_cny: string
  total_amount: number
  handling_amount: number
  payment_id: number | null
  created_at: number
  expires_at: number
  paid_at: number | null
  issued_at: number | null
  redeemed_at: number | null
  traffic_activated_at: number | null
  gift_traffic_bytes: number
  last_error: string | null
}
export interface AiTopupFilters { current: number; page_size: number; user_id: string; trade_no: string; status: string; issue_status: string }
export interface AiTopupRecords { records: AiTopupOrder[]; total: number; current: number; page_size: number }
