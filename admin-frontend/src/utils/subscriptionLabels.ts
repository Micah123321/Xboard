import type { AdminPlanListItem, AdminSubscriptionLabelSavePayload } from '@/types/api'

export function formatSubscriptionPlan(id: number, plans: readonly AdminPlanListItem[], loadError = ''): string {
  const plan = plans.find((item) => item.id === id)
  return plan ? `${plan.name} (#${id})${plan.show ? '' : '（隐藏）'}` : `套餐 #${id}（${loadError ? '信息待重试' : '已删除'}）`
}

export function validateSubscriptionLabel(form: AdminSubscriptionLabelSavePayload, plans: readonly AdminPlanListItem[]): string | null {
  if (!form.name.trim()) return '请输入标签名称'
  const validIds = new Set(plans.map((plan) => plan.id))
  if (!form.plan_ids.some((id) => Number.isInteger(id) && validIds.has(id))) return '请至少选择一个有效套餐（隐藏套餐也可选择）'
  if (form.plan_ids.some((id) => !Number.isInteger(id) || !validIds.has(id))) return '请移除已删除的套餐后再保存'
  return null
}
