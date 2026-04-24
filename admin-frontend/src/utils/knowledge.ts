import MarkdownIt from 'markdown-it'
import type {
  AdminKnowledgeDetail,
  AdminKnowledgeListItem,
  AdminKnowledgeSavePayload,
} from '@/types/api'

export interface KnowledgeFormModel {
  id?: number
  title: string
  category: string
  language: string
  show: boolean
  body: string
}

export interface KnowledgeLanguageOption {
  label: string
  value: string
}

const markdown = new MarkdownIt({
  html: true,
  breaks: true,
  linkify: true,
})

export const KNOWLEDGE_LANGUAGE_OPTIONS: KnowledgeLanguageOption[] = [
  { label: '简体中文', value: 'zh-CN' },
  { label: 'English', value: 'en-US' },
]

function normalizeText(value: string): string {
  return value.trim().replace(/\s+/g, ' ')
}

export function createEmptyKnowledgeForm(): KnowledgeFormModel {
  return {
    title: '',
    category: '',
    language: 'zh-CN',
    show: true,
    body: '',
  }
}

export function normalizeKnowledgeItem(item: AdminKnowledgeListItem): AdminKnowledgeListItem {
  return {
    ...item,
    category: typeof item.category === 'string' ? item.category.trim() : item.category,
    show: Boolean(item.show),
  }
}

export function toKnowledgeFormModel(item?: AdminKnowledgeDetail | null): KnowledgeFormModel {
  if (!item) {
    return createEmptyKnowledgeForm()
  }

  return {
    id: item.id,
    title: item.title || '',
    category: typeof item.category === 'string' ? item.category : '',
    language: item.language || 'zh-CN',
    show: Boolean(item.show),
    body: item.body || '',
  }
}

export function toKnowledgeSavePayload(form: KnowledgeFormModel): AdminKnowledgeSavePayload {
  return {
    id: form.id,
    title: form.title.trim(),
    category: normalizeText(form.category),
    language: form.language,
    show: Boolean(form.show),
    body: form.body.trim(),
  }
}

export function renderKnowledgeBody(source: string): string {
  return markdown.render(source || '')
}

export function getKnowledgeCategoryLabel(category: string | null | undefined): string {
  if (typeof category !== 'string') {
    return '未分类'
  }

  const normalized = normalizeText(category)
  return normalized || '未分类'
}

export function normalizeKnowledgeCategories(
  categories: string[],
  items: Array<Pick<AdminKnowledgeListItem, 'category'>>,
): string[] {
  const next = new Set<string>()

  categories.forEach((item) => {
    const normalized = normalizeText(item || '')
    if (normalized) {
      next.add(normalized)
    }
  })

  items.forEach((item) => {
    const normalized = getKnowledgeCategoryLabel(item.category)
    if (normalized !== '未分类') {
      next.add(normalized)
    }
  })

  return Array.from(next).sort((left, right) => left.localeCompare(right, 'zh-CN'))
}

export function filterKnowledges(
  items: AdminKnowledgeListItem[],
  keyword: string,
  category: string,
): AdminKnowledgeListItem[] {
  const normalizedKeyword = keyword.trim().toLowerCase()
  const normalizedCategory = normalizeText(category)

  return items.filter((item) => {
    const hitKeyword = !normalizedKeyword || [
      item.id,
      item.title,
      getKnowledgeCategoryLabel(item.category),
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()
      .includes(normalizedKeyword)

    const hitCategory = !normalizedCategory || getKnowledgeCategoryLabel(item.category) === normalizedCategory
    return hitKeyword && hitCategory
  })
}

export function countVisibleKnowledges(items: AdminKnowledgeListItem[]): number {
  return items.filter((item) => Boolean(item.show)).length
}

export function moveKnowledgeOrder(
  items: AdminKnowledgeListItem[],
  fromIndex: number,
  direction: -1 | 1,
): AdminKnowledgeListItem[] {
  const targetIndex = fromIndex + direction
  if (targetIndex < 0 || targetIndex >= items.length) {
    return items
  }

  const next = [...items]
  const [current] = next.splice(fromIndex, 1)
  next.splice(targetIndex, 0, current)
  return next
}
