import MarkdownIt from 'markdown-it'
import type { AdminNoticeItem, AdminNoticeSavePayload } from '@/types/api'

export interface NoticeFormModel {
  id?: number
  title: string
  content: string
  imgUrl: string
  tags: string[]
  show: boolean
  popup: boolean
}

const markdown = new MarkdownIt({
  html: true,
  breaks: true,
  linkify: true,
})

function normalizeText(value: unknown): string {
  return typeof value === 'string' ? value.trim() : ''
}

function normalizeTagList(tags: unknown): string[] {
  if (!Array.isArray(tags)) {
    return []
  }

  return [...new Set(tags
    .map((tag) => normalizeNoticeTag(String(tag)))
    .filter(Boolean))]
}

function stripMarkup(source: string): string {
  return source
    .replace(/<[^>]+>/g, ' ')
    .replace(/[`*_>#-]/g, ' ')
    .replace(/\[(.*?)\]\((.*?)\)/g, '$1')
    .replace(/\s+/g, ' ')
    .trim()
}

export function createEmptyNoticeForm(): NoticeFormModel {
  return {
    title: '',
    content: '',
    imgUrl: '',
    tags: [],
    show: true,
    popup: false,
  }
}

export function normalizeNoticeTag(raw: string): string {
  return raw.trim().replace(/\s+/g, ' ')
}

export function normalizeNoticeItem(notice: AdminNoticeItem): AdminNoticeItem {
  return {
    ...notice,
    title: normalizeText(notice.title),
    content: typeof notice.content === 'string' ? notice.content : '',
    img_url: normalizeText(notice.img_url),
    tags: normalizeTagList(notice.tags),
    show: Boolean(notice.show),
    popup: Boolean(notice.popup),
  }
}

export function toNoticeFormModel(notice?: AdminNoticeItem | null): NoticeFormModel {
  const form = createEmptyNoticeForm()
  if (!notice) {
    return form
  }

  const normalized = normalizeNoticeItem(notice)
  return {
    id: normalized.id,
    title: normalized.title,
    content: normalized.content,
    imgUrl: normalized.img_url || '',
    tags: [...(normalized.tags ?? [])],
    show: Boolean(normalized.show),
    popup: Boolean(normalized.popup),
  }
}

export function toNoticeSavePayload(form: NoticeFormModel): AdminNoticeSavePayload {
  return {
    id: form.id,
    title: form.title.trim(),
    content: form.content.trim(),
    img_url: normalizeText(form.imgUrl) || null,
    tags: form.tags,
    show: Boolean(form.show),
    popup: Boolean(form.popup),
  }
}

export function filterNotices(notices: AdminNoticeItem[], keyword: string): AdminNoticeItem[] {
  const normalized = keyword.trim().toLowerCase()
  if (!normalized) {
    return notices
  }

  return notices.filter((notice) => [
    notice.id,
    notice.title,
    notice.content,
    notice.tags?.join(' '),
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
    .includes(normalized))
}

export function countEnabledNotices(notices: AdminNoticeItem[], field: 'show' | 'popup'): number {
  return notices.filter((notice) => Boolean(notice[field])).length
}

export function moveNoticeOrder(notices: AdminNoticeItem[], fromIndex: number, direction: -1 | 1): AdminNoticeItem[] {
  const targetIndex = fromIndex + direction
  if (targetIndex < 0 || targetIndex >= notices.length) {
    return notices
  }

  const next = [...notices]
  const [current] = next.splice(fromIndex, 1)
  next.splice(targetIndex, 0, current)
  return next
}

export function summarizeNoticeContent(source: string, maxLength = 78): string {
  const plainText = stripMarkup(source)
  if (!plainText) {
    return '暂无公告摘要'
  }

  if (plainText.length <= maxLength) {
    return plainText
  }

  return `${plainText.slice(0, maxLength).trimEnd()}…`
}

export function renderNoticeContent(source: string): string {
  return markdown.render(source || '')
}
