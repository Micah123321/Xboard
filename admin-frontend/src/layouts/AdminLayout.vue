<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAppStore } from '@/stores/app'
import {
  Odometer,
  SwitchButton,
  Fold,
  Expand,
  Sunny,
  Moon,
} from '@element-plus/icons-vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const app = useAppStore()

const sidebarWidth = computed(() => app.sidebarCollapsed ? '64px' : '220px')

const menuItems = [
  { index: '/dashboard', title: '仪表盘', icon: Odometer },
]

function handleMenuSelect(index: string) {
  router.push(index)
}

function handleLogout() {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <ElContainer class="admin-layout">
    <ElAside :width="sidebarWidth" class="admin-aside">
      <div class="aside-logo">
        <h1 v-if="!app.sidebarCollapsed">Xboard</h1>
        <span v-else>X</span>
      </div>
      <ElMenu
        :default-active="route.path"
        :collapse="app.sidebarCollapsed"
        :collapse-transition="false"
        router
        @select="handleMenuSelect"
      >
        <ElMenuItem
          v-for="item in menuItems"
          :key="item.index"
          :index="item.index"
        >
          <ElIcon><component :is="item.icon" /></ElIcon>
          <template #title>{{ item.title }}</template>
        </ElMenuItem>
      </ElMenu>
    </ElAside>

    <ElContainer>
      <ElHeader class="admin-header">
        <div class="header-left">
          <ElIcon
            class="collapse-btn"
            @click="app.toggleSidebar"
          >
            <Fold v-if="!app.sidebarCollapsed" />
            <Expand v-else />
          </ElIcon>
          <ElBreadcrumb separator="/">
            <ElBreadcrumbItem :to="{ path: '/dashboard' }">首页</ElBreadcrumbItem>
            <ElBreadcrumbItem v-if="route.name !== 'Dashboard'">
              {{ route.name }}
            </ElBreadcrumbItem>
          </ElBreadcrumb>
        </div>

        <div class="header-right">
          <ElIcon class="theme-btn" @click="app.toggleTheme">
            <Sunny v-if="app.isDark" />
            <Moon v-else />
          </ElIcon>
          <ElButton text @click="handleLogout">
            <ElIcon><SwitchButton /></ElIcon>
            退出
          </ElButton>
        </div>
      </ElHeader>

      <ElMain class="admin-main">
        <RouterView />
      </ElMain>
    </ElContainer>
  </ElContainer>
</template>

<style scoped>
.admin-layout {
  height: 100vh;
}

.admin-aside {
  background: var(--el-bg-color);
  border-right: 1px solid var(--el-border-color-light);
  overflow: hidden;
  transition: width 0.3s;
}

.aside-logo {
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-bottom: 1px solid var(--el-border-color-light);
  font-size: 18px;
  font-weight: 700;
  color: var(--el-text-color-primary);
}

.aside-logo h1 {
  margin: 0;
  font-size: 18px;
}

.admin-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--el-bg-color);
  border-bottom: 1px solid var(--el-border-color-light);
  padding: 0 20px;
  height: 56px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.collapse-btn {
  font-size: 20px;
  cursor: pointer;
  color: var(--el-text-color-regular);
}

.collapse-btn:hover {
  color: var(--el-color-primary);
}

.header-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.theme-btn {
  font-size: 18px;
  cursor: pointer;
  color: var(--el-text-color-regular);
}

.theme-btn:hover {
  color: var(--el-color-primary);
}

.admin-main {
  background: var(--el-bg-color-page);
  overflow-y: auto;
}

@media (max-width: 768px) {
  .admin-aside {
    position: fixed;
    z-index: 100;
    height: 100vh;
  }
}
</style>
