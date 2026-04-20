import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getSecurePath, getApiBaseUrl, getAppTitle, getLogo, getVersion } from '@/utils/runtime'

export const useAppStore = defineStore('app', () => {
  const securePath = ref('')
  const apiBaseUrl = ref('')
  const title = ref('Xboard Admin')
  const logo = ref('')
  const version = ref('')
  const sidebarCollapsed = ref(false)
  const isDark = ref(false)

  function initConfig() {
    securePath.value = getSecurePath()
    apiBaseUrl.value = getApiBaseUrl()
    title.value = getAppTitle()
    logo.value = getLogo()
    version.value = getVersion()

    const saved = localStorage.getItem('xboard_theme_dark')
    if (saved !== null) {
      isDark.value = saved === 'true'
    }
    applyTheme()
  }

  function toggleSidebar() {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }

  function toggleTheme() {
    isDark.value = !isDark.value
    localStorage.setItem('xboard_theme_dark', String(isDark.value))
    applyTheme()
  }

  function applyTheme() {
    document.documentElement.classList.toggle('dark', isDark.value)
  }

  return { securePath, apiBaseUrl, title, logo, version, sidebarCollapsed, isDark, initConfig, toggleSidebar, toggleTheme }
})
