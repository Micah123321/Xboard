import type {
  AdminThemeConfigField,
  AdminThemeConfigRecord,
  AdminThemeListResult,
  AdminThemeSummary,
} from '@/types/api'

export type ThemeConfigFormState = Record<string, string>

export interface ResolvedThemeSummary extends AdminThemeSummary {
  key: string
}

function normalizeThemeValue(
  value: string | number | boolean | null | undefined,
  field: AdminThemeConfigField,
): string {
  if (value === null || value === undefined || value === '') {
    if (field.default_value === null || field.default_value === undefined) {
      return ''
    }
    return String(field.default_value)
  }
  return String(value)
}

export function resolveThemes(result?: AdminThemeListResult | null): ResolvedThemeSummary[] {
  const activeTheme = result?.active ?? ''
  return Object.entries(result?.themes ?? {})
    .map(([key, theme]) => ({ ...theme, key }))
    .sort((left, right) => {
      if (left.name === activeTheme) return -1
      if (right.name === activeTheme) return 1
      if (Boolean(left.is_system) !== Boolean(right.is_system)) {
        return left.is_system ? -1 : 1
      }
      return left.name.localeCompare(right.name, 'zh-CN')
    })
}

export function createThemeConfigFormState(
  fields: AdminThemeConfigField[],
  config: AdminThemeConfigRecord | null | undefined,
): ThemeConfigFormState {
  return fields.reduce<ThemeConfigFormState>((state, field) => {
    state[field.field_name] = normalizeThemeValue(config?.[field.field_name], field)
    return state
  }, {})
}

export function serializeThemeConfigForm(
  form: ThemeConfigFormState,
  fields: AdminThemeConfigField[],
): AdminThemeConfigRecord {
  return fields.reduce<AdminThemeConfigRecord>((state, field) => {
    state[field.field_name] = form[field.field_name] ?? ''
    return state
  }, {})
}
