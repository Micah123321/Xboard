import MarkdownIt from 'markdown-it'
import type {
  AdminPluginConfigField,
  AdminPluginConfigOption,
  AdminPluginItem,
  AdminPluginTypeItem,
  AdminPluginTypeValue,
} from '@/types/api'

export type PluginTabValue = AdminPluginTypeValue | 'all'
export type PluginStatusFilter = 'all' | 'enabled' | 'installed_disabled' | 'uninstalled' | 'upgrade'
export type NormalizedPluginFieldType = 'boolean' | 'text' | 'json' | 'number' | 'select' | 'string'
export type PluginConfigDraft = Record<string, string | number | boolean>

export interface PluginStatusMeta {
  label: string
  tone: '' | 'success' | 'warning' | 'info' | 'danger'
  helper: string
}

export interface NormalizedPluginConfigField extends Omit<AdminPluginConfigField, 'options'> {
  key: string
  type: NormalizedPluginFieldType
  options: AdminPluginConfigOption[]
}

const markdown = new MarkdownIt({
  html: true,
  breaks: true,
  linkify: true,
})

export const PLUGIN_STATUS_FILTER_OPTIONS: Array<{ label: string; value: PluginStatusFilter }> = [
  { label: '全部状态', value: 'all' },
  { label: '已启用', value: 'enabled' },
  { label: '已安装未启用', value: 'installed_disabled' },
  { label: '未安装', value: 'uninstalled' },
  { label: '可升级', value: 'upgrade' },
]

export function buildPluginTabs(types: AdminPluginTypeItem[]): Array<{ label: string; value: PluginTabValue }> {
  return [
    ...types.map((item) => ({
      label: item.label,
      value: item.value,
    })),
    { label: '所有插件', value: 'all' as const },
  ]
}

export function getPluginTypeLabel(type: string, types: Array<{ value: string; label: string }>): string {
  return types.find((item) => item.value === type)?.label || type || '未知类型'
}

export function getPluginStatusMeta(plugin: AdminPluginItem): PluginStatusMeta {
  if (plugin.need_upgrade) {
    return {
      label: plugin.is_enabled ? '待升级（运行中）' : '待升级',
      tone: 'warning',
      helper: '检测到本地插件版本高于当前已安装版本',
    }
  }

  if (!plugin.is_installed) {
    return {
      label: '未安装',
      tone: 'info',
      helper: '插件目录已存在，可直接安装',
    }
  }

  if (plugin.is_enabled) {
    return {
      label: '已启用',
      tone: 'success',
      helper: '插件已加载到当前系统',
    }
  }

  return {
    label: '已安装未启用',
    tone: '',
    helper: '插件已安装，但当前未启用',
  }
}

export function matchPluginStatus(plugin: AdminPluginItem, filter: PluginStatusFilter): boolean {
  switch (filter) {
    case 'enabled':
      return plugin.is_installed && plugin.is_enabled
    case 'installed_disabled':
      return plugin.is_installed && !plugin.is_enabled
    case 'uninstalled':
      return !plugin.is_installed
    case 'upgrade':
      return plugin.need_upgrade
    default:
      return true
  }
}

export function filterPlugins(plugins: AdminPluginItem[], keyword: string, status: PluginStatusFilter): AdminPluginItem[] {
  const normalizedKeyword = keyword.trim().toLowerCase()

  return plugins.filter((plugin) => {
    if (!matchPluginStatus(plugin, status)) {
      return false
    }

    if (!normalizedKeyword) {
      return true
    }

    const haystack = [
      plugin.name,
      plugin.code,
      plugin.description,
      plugin.author,
      plugin.version,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase()

    return haystack.includes(normalizedKeyword)
  })
}

export function countEnabledPlugins(plugins: AdminPluginItem[]): number {
  return plugins.filter((plugin) => plugin.is_installed && plugin.is_enabled).length
}

export function countUpgradeablePlugins(plugins: AdminPluginItem[]): number {
  return plugins.filter((plugin) => plugin.need_upgrade).length
}

export function countUserPlugins(plugins: AdminPluginItem[]): number {
  return plugins.filter((plugin) => plugin.can_be_deleted).length
}

export function hasPluginConfig(plugin: AdminPluginItem | null | undefined): boolean {
  return Boolean(plugin && Object.keys(plugin.config || {}).length)
}

export function hasPluginReadme(plugin: AdminPluginItem | null | undefined): boolean {
  return Boolean(plugin?.readme?.trim())
}

export function renderPluginReadme(source: string): string {
  return markdown.render(source || '')
}

function normalizeFieldType(field: AdminPluginConfigField): NormalizedPluginFieldType {
  const rawType = String(field.type || 'string').trim().toLowerCase()

  if (rawType === 'boolean' || rawType === 'bool' || rawType === 'switch') return 'boolean'
  if (rawType === 'text' || rawType === 'textarea') return 'text'
  if (rawType === 'json') return 'json'
  if (rawType === 'number' || rawType === 'int' || rawType === 'float') return 'number'
  if (rawType === 'select' || rawType === 'enum') return 'select'
  return 'string'
}

function normalizeFieldOptions(
  options: AdminPluginConfigField['options'],
): AdminPluginConfigOption[] {
  if (Array.isArray(options)) {
    return options.map((item) => (
      typeof item === 'object' && item !== null && 'label' in item && 'value' in item
        ? item as AdminPluginConfigOption
        : {
            label: String(item),
            value: item as string | number | boolean,
          }
    ))
  }

  if (options && typeof options === 'object') {
    return Object.entries(options).map(([value, label]) => ({
      label: String(label),
      value,
    }))
  }

  return []
}

function normalizeDraftValue(field: NormalizedPluginConfigField): string | number | boolean {
  if (field.type === 'boolean') {
    return Boolean(field.value)
  }

  if (field.type === 'number') {
    const numeric = Number(field.value)
    return Number.isFinite(numeric) ? numeric : 0
  }

  if (field.type === 'json') {
    if (field.value === null || field.value === undefined || field.value === '') {
      return ''
    }
    return typeof field.value === 'string'
      ? field.value
      : JSON.stringify(field.value, null, 2)
  }

  if (field.value === null || field.value === undefined) {
    return ''
  }

  return String(field.value)
}

export function getPluginConfigFields(plugin: AdminPluginItem | null | undefined): NormalizedPluginConfigField[] {
  if (!plugin) return []

  return Object.entries(plugin.config || {}).map(([key, field]) => {
    const normalizedField: NormalizedPluginConfigField = {
      ...field,
      key,
      type: normalizeFieldType(field),
      options: normalizeFieldOptions(field.options),
    }
    return normalizedField
  })
}

export function createPluginConfigDraft(plugin: AdminPluginItem | null | undefined): PluginConfigDraft {
  return getPluginConfigFields(plugin).reduce((acc, field) => {
    acc[field.key] = normalizeDraftValue(field)
    return acc
  }, {} as PluginConfigDraft)
}

export function serializePluginConfigDraft(
  plugin: AdminPluginItem,
  draft: PluginConfigDraft,
): Record<string, unknown> {
  return getPluginConfigFields(plugin).reduce((acc, field) => {
    const value = draft[field.key]

    if (field.type === 'boolean') {
      acc[field.key] = Boolean(value)
      return acc
    }

    if (field.type === 'number') {
      if (value === '' || value === null || value === undefined) {
        acc[field.key] = null
        return acc
      }

      const numeric = Number(value)
      if (!Number.isFinite(numeric)) {
        throw new Error(`配置项「${field.label || field.key}」必须是有效数字`)
      }
      acc[field.key] = numeric
      return acc
    }

    if (field.type === 'json') {
      const raw = String(value ?? '').trim()
      if (!raw) {
        acc[field.key] = null
        return acc
      }

      try {
        acc[field.key] = JSON.parse(raw)
      } catch {
        throw new Error(`配置项「${field.label || field.key}」不是有效 JSON`)
      }
      return acc
    }

    acc[field.key] = String(value ?? '')
    return acc
  }, {} as Record<string, unknown>)
}
