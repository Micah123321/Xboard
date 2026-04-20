let cachedSecurePath: string | null = null

export function getApiBaseUrl(): string {
  return import.meta.env.VITE_API_BASE_URL || '/api/v2'
}

export function initSecurePath(): string {
  if (window.settings?.secure_path) {
    cachedSecurePath = window.settings.secure_path
    return window.settings.secure_path
  }

  const envPath = import.meta.env.VITE_ADMIN_PATH
  if (envPath) {
    cachedSecurePath = envPath
    return envPath
  }

  console.error('[Xboard] secure_path 未配置。请设置 window.settings.secure_path 或环境变量 VITE_ADMIN_PATH')
  cachedSecurePath = ''
  return ''
}

export function getSecurePath(): string {
  if (cachedSecurePath === null) {
    return initSecurePath()
  }
  return cachedSecurePath
}

export function getAppTitle(): string {
  return window.settings?.title || 'Xboard Admin'
}

export function getLogo(): string {
  return window.settings?.logo || ''
}

export function getVersion(): string {
  return window.settings?.version || ''
}
