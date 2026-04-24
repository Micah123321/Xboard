import type {
  AdminPaymentConfigField,
  AdminPaymentConfigFields,
  AdminPaymentListItem,
  AdminPaymentSavePayload,
} from '@/types/api'

export interface PaymentFormModel {
  id?: number
  name: string
  icon: string
  payment: string
  notifyDomain: string
  handlingFeePercent: number | null
  handlingFeeFixed: number | null
  config: Record<string, string>
}

function normalizeNullableNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : null
}

function normalizeFieldValue(field: AdminPaymentConfigField | undefined): string {
  if (!field) {
    return ''
  }

  if (field.value === null || field.value === undefined) {
    return ''
  }

  return String(field.value)
}

export function createEmptyPaymentForm(): PaymentFormModel {
  return {
    name: '',
    icon: '',
    payment: '',
    notifyDomain: '',
    handlingFeePercent: null,
    handlingFeeFixed: null,
    config: {},
  }
}

export function normalizePayment(payment: AdminPaymentListItem): AdminPaymentListItem {
  return {
    ...payment,
    enable: Boolean(payment.enable),
    config: payment.config ?? {},
    notify_domain: payment.notify_domain ?? null,
    notify_url: payment.notify_url ?? '',
    handling_fee_fixed: normalizeNullableNumber(payment.handling_fee_fixed),
    handling_fee_percent: normalizeNullableNumber(payment.handling_fee_percent),
    sort: Number(payment.sort || 0),
  }
}

export function sortPaymentsByOrder(payments: AdminPaymentListItem[]): AdminPaymentListItem[] {
  return [...payments].sort((left, right) => {
    const leftSort = Number(left.sort || 0)
    const rightSort = Number(right.sort || 0)
    if (leftSort !== rightSort) {
      return leftSort - rightSort
    }
    return left.id - right.id
  })
}

export function filterPayments(payments: AdminPaymentListItem[], keyword: string): AdminPaymentListItem[] {
  const normalized = keyword.trim().toLowerCase()
  if (!normalized) {
    return payments
  }

  return payments.filter((payment) => {
    const haystack = [
      payment.id,
      payment.name,
      payment.payment,
      payment.notify_url,
      payment.notify_domain,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return haystack.includes(normalized)
  })
}

export function countEnabledPayments(payments: AdminPaymentListItem[]): number {
  return payments.filter((payment) => Boolean(payment.enable)).length
}

export function countCustomNotifyDomains(payments: AdminPaymentListItem[]): number {
  return payments.filter((payment) => Boolean(String(payment.notify_domain || '').trim())).length
}

export function movePaymentOrder<T>(list: T[], index: number, direction: -1 | 1): T[] {
  const targetIndex = index + direction
  if (targetIndex < 0 || targetIndex >= list.length) {
    return list
  }

  const next = [...list]
  const [item] = next.splice(index, 1)
  next.splice(targetIndex, 0, item)
  return next
}

export function formatPaymentFee(payment: Pick<AdminPaymentListItem, 'handling_fee_percent' | 'handling_fee_fixed'>): string {
  const segments: string[] = []
  const percent = normalizeNullableNumber(payment.handling_fee_percent)
  const fixed = normalizeNullableNumber(payment.handling_fee_fixed)

  if (percent !== null && percent > 0) {
    segments.push(`${percent}% 手续费`)
  }

  if (fixed !== null && fixed > 0) {
    segments.push(`固定 ¥${fixed}`)
  }

  return segments.join(' + ') || '无额外手续费'
}

export function normalizePaymentConfigFields(fields: AdminPaymentConfigFields | null | undefined): AdminPaymentConfigFields {
  if (!fields) {
    return {}
  }

  return Object.entries(fields).reduce<AdminPaymentConfigFields>((result, [key, field]) => {
    result[key] = {
      type: field.type || 'string',
      label: field.label || key,
      placeholder: field.placeholder || '',
      description: field.description || '',
      value: normalizeFieldValue(field),
      options: field.options || [],
    }
    return result
  }, {})
}

export function extractPaymentConfigValues(fields: AdminPaymentConfigFields): Record<string, string> {
  return Object.entries(fields).reduce<Record<string, string>>((result, [key, field]) => {
    result[key] = normalizeFieldValue(field)
    return result
  }, {})
}

export function toPaymentFormModel(payment?: AdminPaymentListItem | null): PaymentFormModel {
  const base = createEmptyPaymentForm()
  if (!payment) {
    return base
  }

  return {
    id: payment.id,
    name: payment.name || '',
    icon: payment.icon || '',
    payment: payment.payment || '',
    notifyDomain: payment.notify_domain || '',
    handlingFeePercent: normalizeNullableNumber(payment.handling_fee_percent),
    handlingFeeFixed: normalizeNullableNumber(payment.handling_fee_fixed),
    config: Object.entries(payment.config || {}).reduce<Record<string, string>>((result, [key, value]) => {
      result[key] = value === null || value === undefined ? '' : String(value)
      return result
    }, {}),
  }
}

export function toPaymentSavePayload(
  form: PaymentFormModel,
  fields: AdminPaymentConfigFields,
): AdminPaymentSavePayload {
  const config = Object.entries(fields).reduce<Record<string, string>>((result, [key, field]) => {
    const currentValue = form.config[key] ?? ''
    result[key] = field.type === 'text'
      ? currentValue
      : currentValue.trim()
    return result
  }, {})

  return {
    id: form.id,
    name: form.name.trim(),
    icon: form.icon.trim() || null,
    payment: form.payment,
    config,
    notify_domain: form.notifyDomain.trim() || null,
    handling_fee_fixed: form.handlingFeeFixed,
    handling_fee_percent: form.handlingFeePercent,
  }
}
