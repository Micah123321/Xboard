import type { AdminNodeBatchUpdatePayload } from '@/types/api'

export interface NodeBatchEditForm {
  updateHost: boolean
  host: string
  updateRate: boolean
  rate: number
  updateGroups: boolean
  groupIds: number[]
  updateAutoOnline: boolean
  autoOnline: boolean
  updateGfwCheck: boolean
  gfwCheckEnabled: boolean
  updateBandwidthCheck: boolean
  bandwidthCheckEnabled: boolean
  updateSubscriptionLabel: boolean
  labelAction: 'assign' | 'clear'
  subscriptionLabelId: number | null
}

export function buildNodeBatchEditPayload(form: NodeBatchEditForm): Omit<AdminNodeBatchUpdatePayload, 'ids'> {
  return {
    ...(form.updateHost ? { host: form.host.trim() } : {}),
    ...(form.updateRate ? { rate: Number(form.rate) } : {}),
    ...(form.updateGroups ? { group_ids: [...new Set(form.groupIds.map(String))] } : {}),
    ...(form.updateAutoOnline ? { auto_online: form.autoOnline } : {}),
    ...(form.updateGfwCheck ? { gfw_check_enabled: form.gfwCheckEnabled } : {}),
    ...(form.updateBandwidthCheck ? { bandwidth_check_enabled: form.bandwidthCheckEnabled } : {}),
    ...(form.updateSubscriptionLabel ? { subscription_label_id: form.labelAction === 'clear' ? null : form.subscriptionLabelId } : {}),
  }
}
