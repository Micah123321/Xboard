import MarkdownIt from 'markdown-it'
import type {
  AdminTicketFetchParams,
  AdminTicketListItem,
  AdminTrafficLogItem,
  AdminUserFilter,
} from '@/types/api'

export interface TicketMeta {
  label: string
  type: 'primary' | 'success' | 'warning' | 'danger' | 'info'
}

const markdown = new MarkdownIt({
  html: false,
  breaks: true,
  linkify: true,
})

function normalizeTicketLevelValue(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : null
}

export function getTicketLevelMeta(level: string | number | null | undefined): TicketMeta {
  const numeric = normalizeTicketLevelValue(level)

  if (numeric === 2) {
    return { label: '高优先', type: 'danger' }
  }

  if (numeric === 1) {
    return { label: '中优先', type: 'warning' }
  }

  if (numeric === 0) {
    return { label: '低优先', type: 'info' }
  }

  if (typeof level === 'string' && level.trim()) {
    return { label: level.trim(), type: 'info' }
  }

  return { label: '未设置', type: 'info' }
}

export function getTicketStatusMeta(ticket: Pick<AdminTicketListItem, 'status' | 'reply_status'>): TicketMeta {
  if (ticket.status === 1) {
    return { label: '已关闭', type: 'info' }
  }

  if (ticket.reply_status === 0) {
    return { label: '待回复', type: 'danger' }
  }

  return { label: '处理中', type: 'success' }
}

export function renderTicketMarkdown(source: string): string {
  return markdown.render(source || '')
}

export function buildTicketFilters(keyword: string, levelFilter: string): Pick<AdminTicketFetchParams, 'email' | 'filter'> {
  const filters: AdminUserFilter[] = []
  const normalized = keyword.trim()
  let email: string | undefined

  if (normalized) {
    if (normalized.includes('@')) {
      email = normalized
    } else {
      filters.push({ id: 'subject', value: normalized })
    }
  }

  if (levelFilter !== 'all') {
    filters.push({ id: 'level', value: [Number(levelFilter)] })
  }

  return {
    email,
    filter: filters.length ? filters : undefined,
  }
}

export function getTrafficTotal(log: Pick<AdminTrafficLogItem, 'u' | 'd'>): number {
  return (Number(log.u) || 0) + (Number(log.d) || 0)
}

export function formatServerRate(rate: number | null | undefined): string {
  const numeric = Number(rate)
  if (!Number.isFinite(numeric) || numeric <= 0) {
    return '1x'
  }

  return `${numeric}x`
}
