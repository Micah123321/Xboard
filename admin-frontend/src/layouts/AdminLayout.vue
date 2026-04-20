<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAppStore } from '@/stores/app'
import {
  Odometer,
  Tickets,
  SwitchButton,
  Fold,
  Expand,
  User,
  UserFilled,
} from '@element-plus/icons-vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const app = useAppStore()
const isMobile = ref(false)

const sidebarWidth = computed(() => app.sidebarCollapsed ? '72px' : '220px')
const currentTitle = computed(() => String(route.meta.title || '控制台'))
const currentKicker = computed(() => String(route.meta.kicker || 'Xboard Admin'))

const menuItems = [
  { index: '/dashboard', title: '仪表盘', icon: Odometer },
]

const managementItems = [
  { index: '/users', title: '用户管理', icon: User },
  { index: '/tickets', title: '工单管理', icon: Tickets },
]

function syncViewport() {
  isMobile.value = window.innerWidth < 960
  if (isMobile.value) {
    app.sidebarCollapsed = true
  }
}

function handleMenuSelect(index: string) {
  if (isMobile.value) {
    app.sidebarCollapsed = true
  }
  router.push(index)
}

function handleLogout() {
  auth.logout()
  router.push('/login')
}

onMounted(() => {
  syncViewport()
  window.addEventListener('resize', syncViewport)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', syncViewport)
})
</script>

<template>
  <ElContainer class="admin-layout">
    <ElAside :width="sidebarWidth" class="admin-aside">
      <div class="aside-logo">
        <div class="aside-mark">X</div>
        <div v-if="!app.sidebarCollapsed" class="aside-brand">
          <p>Xboard</p>
          <h1>{{ app.title }}</h1>
        </div>
      </div>

      <ElMenu
        :default-active="route.path"
        :default-openeds="['management']"
        :collapse="app.sidebarCollapsed"
        :collapse-transition="false"
        router
        class="admin-menu"
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

          <ElSubMenu index="management">
            <template #title>
              <ElIcon><UserFilled /></ElIcon>
              <span>用户管理</span>
            </template>

            <ElMenuItem
              v-for="item in managementItems"
              :key="item.index"
              :index="item.index"
            >
              <ElIcon><component :is="item.icon" /></ElIcon>
              <template #title>{{ item.title }}</template>
            </ElMenuItem>
          </ElSubMenu>
      </ElMenu>
    </ElAside>

    <ElContainer class="admin-stage">
      <ElHeader class="admin-header">
        <div class="header-left">
          <ElIcon
            class="collapse-btn"
            @click="app.toggleSidebar"
          >
            <Fold v-if="!app.sidebarCollapsed" />
            <Expand v-else />
          </ElIcon>

          <div class="page-copy">
            <p>{{ currentKicker }}</p>
            <h2>{{ currentTitle }}</h2>
          </div>
        </div>

        <div class="header-right">
          <div class="header-info">
            <span class="header-info__label">secure_path</span>
            <strong>/{{ app.securePath || 'admin' }}</strong>
          </div>
          <ElButton text class="logout-btn" @click="handleLogout">
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
  background: #f5f5f7;
}

.admin-aside {
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border-right: 1px solid rgba(0, 0, 0, 0.06);
  overflow: hidden;
  transition: width 0.3s;
  padding: 18px 12px 12px;
  box-shadow: 0 0 1px rgba(0, 0, 0, 0.08);
}

.aside-logo {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 8px 20px;
}

.aside-mark {
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  border-radius: 999px;
  background: #1d1d1f;
  color: #ffffff;
  font-size: 14px;
  font-weight: 600;
}

.aside-brand {
  display: grid;
  gap: 2px;
}

.aside-brand p {
  margin: 0;
  font-size: 12px;
  color: var(--xboard-text-muted);
}

.aside-brand h1 {
  margin: 0;
  font-size: 18px;
  line-height: 1.1;
  color: var(--xboard-text-strong);
}

.admin-menu {
  flex: 1;
  background: #ffffff;
  border-right: 0;
}

.admin-menu :deep(.el-menu-item) {
  margin-bottom: 8px;
  border-radius: 12px;
  color: var(--xboard-text-secondary);
  height: 44px;
}

.admin-menu :deep(.el-menu-item.is-active) {
  background: rgba(0, 113, 227, 0.08);
  color: #0071e3;
}

.admin-menu :deep(.el-sub-menu__title) {
  border-radius: 12px;
  color: var(--xboard-text-secondary);
  height: 44px;
}

.admin-menu :deep(.el-sub-menu .el-menu-item) {
  margin-left: 8px;
}

.admin-stage {
  background: #f5f5f7;
}

.admin-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(255, 255, 255, 0.72);
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  backdrop-filter: saturate(180%) blur(20px);
  padding: 0 24px;
  height: 64px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 18px;
}

.collapse-btn {
  font-size: 20px;
  cursor: pointer;
  color: var(--xboard-text-secondary);
}

.collapse-btn:hover {
  color: #0071e3;
}

.page-copy {
  display: grid;
  gap: 4px;
}

.page-copy p {
  margin: 0;
  font-size: 12px;
  color: var(--xboard-text-muted);
}

.page-copy h2 {
  margin: 0;
  font-size: 28px;
  line-height: 1.1;
  color: var(--xboard-text-strong);
}

.header-right {
  display: flex;
  align-items: center;
  gap: 14px;
}

.header-info {
  display: grid;
  gap: 2px;
  text-align: right;
}

.header-info__label {
  font-size: 12px;
  color: var(--xboard-text-secondary);
}

.header-info strong {
  font-size: 14px;
  color: var(--xboard-text-strong);
  font-weight: 600;
}

.logout-btn {
  color: #0071e3;
}

.admin-main {
  background: #f5f5f7;
  overflow-y: auto;
  padding: 24px;
}

@media (max-width: 959px) {
  .admin-aside {
    position: fixed;
    z-index: 30;
    height: 100vh;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
  }

  .admin-header {
    padding: 0 18px;
  }

  .page-copy h2 {
    font-size: 20px;
  }

  .header-info {
    display: none;
  }

  .admin-main {
    padding: 18px;
  }
}
</style>
