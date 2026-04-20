export const DEFAULT_AFTER_LOGIN = '/dashboard'

export function normalizeRedirectTarget(value: unknown, fallback: string = DEFAULT_AFTER_LOGIN): string {
  if (typeof value !== 'string') {
    return fallback
  }

  const normalized = value.trim().replace(/^#/, '')
  if (!normalized || !normalized.startsWith('/') || normalized.startsWith('//')) {
    return fallback
  }

  if (normalized.startsWith('/login')) {
    return fallback
  }

  return normalized
}

export function buildLoginHash(redirect?: string): string {
  const safeTarget = normalizeRedirectTarget(redirect, '')
  return safeTarget ? `#/login?redirect=${encodeURIComponent(safeTarget)}` : '#/login'
}
