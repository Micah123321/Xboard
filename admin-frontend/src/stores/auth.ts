import { defineStore } from 'pinia'
import { ref } from 'vue'
import { login as apiLogin } from '@/api/passport'
import { getSystemStatus } from '@/api/admin'
import { getToken, setToken, removeToken, hasToken } from '@/utils/token'
import axios from 'axios'

export const useAuthStore = defineStore('auth', () => {
  const authHeader = ref<string | null>(getToken())
  const isAdmin = ref(false)
  const isLoading = ref(false)
  const validated = ref(false)

  async function login(email: string, password: string, remember: boolean = false) {
    isLoading.value = true
    try {
      const res = await apiLogin({ email, password })

      if (!res.data.is_admin) {
        throw new Error('该账号无管理员权限')
      }

      authHeader.value = res.data.auth_data
      isAdmin.value = res.data.is_admin
      setToken(res.data.auth_data, remember)

      const probeOk = await validateAdmin()
      if (!probeOk) {
        validated.value = true
        isAdmin.value = true
      }

      return true
    } catch (err) {
      authHeader.value = null
      isAdmin.value = false
      removeToken()
      throw err
    } finally {
      isLoading.value = false
    }
  }

  async function validateAdmin(): Promise<boolean> {
    if (!hasToken()) {
      return false
    }
    try {
      await getSystemStatus()
      validated.value = true
      isAdmin.value = true
      return true
    } catch (err) {
      if (axios.isAxiosError(err)) {
        const status = err.response?.status
        if (status === 401 || status === 403) {
          logout()
          return false
        }
      }
      console.warn('[Xboard] Admin probe failed, proceeding with login token')
      return false
    }
  }

  function logout() {
    authHeader.value = null
    isAdmin.value = false
    validated.value = false
    removeToken()
  }

  function initFromStorage() {
    const token = getToken()
    if (token) {
      authHeader.value = token
    }
  }

  return { authHeader, isAdmin, isLoading, validated, login, logout, validateAdmin, initFromStorage }
})
