export type ActivitySource = 'custom' | 'invite' | 'ai_credit' | 'ai_topup'
export type ActivityStatus = 'draft' | 'scheduled' | 'active' | 'ended' | 'unavailable'
export interface ActivityInput {
  id?: number
  version?: number
  code: string | null
  source: ActivitySource
  title: string
  badge: string
  summary: string
  benefits: string[]
  image_url: string
  target_url: string
  button_text: string
  theme: 'navy' | 'indigo' | 'teal'
  sort: number
  published: boolean
  starts_at: number | null
  ends_at: number | null
}
export interface Activity extends ActivityInput {
  id: number
  version: number
  status: ActivityStatus
  created_at: number
  updated_at: number
}
export interface ActivityPage { records: Activity[]; total: number; current: number; page_size: number }
