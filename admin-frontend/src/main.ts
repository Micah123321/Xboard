import { createApp } from 'vue'
import { createPinia } from 'pinia'
import router from './router'
import App from './App.vue'

import 'element-plus/theme-chalk/dark/css-vars.css'
import 'element-plus/dist/index.css'
import './styles/index.scss'

import { setupGuards } from './router/guards'
import { initSecurePath } from './utils/runtime'
import { useAppStore } from './stores/app'
import { useAuthStore } from './stores/auth'

initSecurePath()

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)

const appStore = useAppStore()
appStore.initConfig()

const authStore = useAuthStore()
authStore.initFromStorage()

setupGuards(router)
app.use(router)

app.mount('#app')
