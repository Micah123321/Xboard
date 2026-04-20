import axios from 'axios'
import type { ApiResponse } from '@/types/api'
import { getApiBaseUrl, getSecurePath } from '@/utils/runtime'
import { getToken, removeToken } from '@/utils/token'
import { buildLoginHash, DEFAULT_AFTER_LOGIN, normalizeRedirectTarget } from '@/utils/navigation'

function redirectToLogin(): void {
  const currentTarget = normalizeRedirectTarget(
    window.location.hash.replace(/^#/, ''),
    DEFAULT_AFTER_LOGIN,
  )

  window.location.hash = currentTarget === DEFAULT_AFTER_LOGIN
    ? '#/login'
    : buildLoginHash(currentTarget)
}

function handleError(error: unknown): never {
  if (axios.isAxiosError(error)) {
    const status = error.response?.status
    const data = error.response?.data as ApiResponse | undefined

    if (status === 401 || status === 403) {
      removeToken()
      redirectToLogin()
    }

    throw new Error(data?.message || error.message || '请求失败')
  }
  throw new Error('网络错误，请检查网络连接')
}

export const passportClient = axios.create({
  baseURL: `${getApiBaseUrl()}/passport`,
  timeout: 15000,
  headers: { 'Content-Type': 'application/json' },
})

export const adminClient = axios.create({
  timeout: 15000,
  headers: { 'Content-Type': 'application/json' },
})

adminClient.interceptors.request.use((config) => {
  const securePath = getSecurePath()
  config.baseURL = securePath
    ? `${getApiBaseUrl()}/${securePath}`
    : getApiBaseUrl()
  const token = getToken()
  if (token) {
    config.headers.Authorization = token
  }
  return config
})

passportClient.interceptors.response.use(
  (res) => res,
  (err) => handleError(err),
)

adminClient.interceptors.response.use(
  (res) => res,
  (err) => handleError(err),
)
