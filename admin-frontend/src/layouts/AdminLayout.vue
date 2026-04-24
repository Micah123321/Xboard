<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useAppStore } from '@/stores/app'
import {
  Connection,
  Odometer,
  Tickets,
  SwitchButton,
  Fold,
  Expand,
  User,
  UserFilled,
  Lock,
  Share,
  ShoppingBag,
  CollectionTag,
  Document,
  Discount,
  Present,
  Setting,
  Box,
  Brush,
  Bell,
  CreditCard,
  Reading,
} from '@element-plus/icons-vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const app = useAppStore()
const isMobile = ref(false)

const sidebarWidth = computed(() => app.sidebarCollapsed ? '72px' : '220px')
const currentTitle = computed(() => String(route.meta.title || '控制台'))
const currentKicker = computed(() => String(route.meta.kicker || 'Xboard Admin'))

type MenuItem = {
  index: string
  title: string
  icon: unknown
  disabled?: boolean
  badge?: string
}

const menuItems: MenuItem[] = [
  { index: '/dashboard', title: '仪表盘', icon: Odometer },
]

const nodeManagementItems: MenuItem[] = [
  { index: '/nodes', title: '节点管理', icon: Connection },
  { index: '/node-groups', title: '权限组管理', icon: Lock },
  { index: '/node-routes', title: '路由管理', icon: Share },
]

const managementItems: MenuItem[] = [
  { index: '/users', title: '用户管理', icon: User },
  { index: '/tickets', title: '工单管理', icon: Tickets },
]

const subscriptionItems: MenuItem[] = [
  { index: '/subscriptions/plans', title: '套餐管理', icon: CollectionTag },
  { index: '/subscriptions/orders', title: '订单管理', icon: Document, disabled: true, badge: '即将开放' },
  { index: '/subscriptions/coupons', title: '优惠券管理', icon: Discount, disabled: true, badge: '即将开放' },
  { index: '/subscriptions/gift-cards', title: '礼品卡管理', icon: Present, disabled: true, badge: '即将开放' },
]

const systemManagementItems: MenuItem[] = [
  { index: '/system/config', title: '系统配置', icon: Setting },
  { index: '/system/plugins', title: '插件管理', icon: Box },
  { index: '/system/themes', title: '主题配置', icon: Brush },
  { index: '/system/notices', title: '公告管理', icon: Bell },
  { index: '/system/payments', title: '支付配置', icon: CreditCard },
  { index: '/system/knowledge', title: '知识库管理', icon: Reading },
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
        :default-openeds="['node-management', 'management', 'subscription', 'system-management']"
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

          <ElSubMenu index="node-management">
            <template #title>
              <ElIcon><Connection /></ElIcon>
              <span>节点管理</span>
            </template>

            <ElMenuItem
              v-for="item in nodeManagementItems"
              :key="item.index"
              :index="item.index"
            >
              <ElIcon><component :is="item.icon" /></ElIcon>
              <template #title>{{ item.title }}</template>
            </ElMenuItem>
          </ElSubMenu>

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

          <ElSubMenu index="subscription">
            <template #title>
              <ElIcon><ShoppingBag /></ElIcon>
              <span>订阅管理</span>
            </template>

            <ElMenuItem
              v-for="item in subscriptionItems"
              :key="item.index"
              :index="item.index"
              :disabled="item.disabled"
            >
              <ElIcon><component :is="item.icon" /></ElIcon>
              <template #title>
                <span class="menu-title">{{ item.title }}</span>
                <span v-if="item.badge && !app.sidebarCollapsed" class="menu-badge">
                  {{ item.badge }}
                </span>
              </template>
            </ElMenuItem>
          </ElSubMenu>

          <ElSubMenu index="system-management">
            <template #title>
              <ElIcon><Setting /></ElIcon>
              <span>系统管理</span>
            </template>

            <ElMenuItem
              v-for="item in systemManagementItems"
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

.admin-menu :deep(.el-menu-item.is-disabled) {
  opacity: 0.72;
}

.menu-title {
  display: inline-flex;
  align-items: center;
}

.menu-badge {
  margin-left: 8px;
  display: inline-flex;
  align-items: center;
  height: 20px;
  padding: 0 8px;
  border-radius: 999px;
  background: rgba(0, 113, 227, 0.08);
  color: #0071e3;
  font-size: 11px;
}

.admin-menu :deep(.el-sub-menu.is-opened > .el-sub-menu__title) {
  color: var(--xboard-text-strong);
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
