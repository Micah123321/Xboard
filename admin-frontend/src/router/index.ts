import { createRouter, createWebHashHistory } from 'vue-router'
import type { RouteRecordRaw } from 'vue-router'

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/login/LoginView.vue'),
    meta: { public: true, title: '管理员登录', kicker: 'Xboard Admin' },
  },
  {
    path: '/',
    name: 'AdminRoot',
    component: () => import('@/layouts/AdminLayout.vue'),
    children: [
      {
        path: '',
        redirect: '/dashboard',
      },
      {
        path: 'dashboard',
        name: 'Dashboard',
        component: () => import('@/views/dashboard/DashboardView.vue'),
        meta: { title: '仪表盘', kicker: 'Overview' },
      },
      {
        path: 'users',
        name: 'Users',
        component: () => import('@/views/users/UsersView.vue'),
        meta: { title: '用户管理', kicker: 'Users' },
      },
      {
        path: 'nodes',
        name: 'Nodes',
        component: () => import('@/views/nodes/NodesView.vue'),
        meta: { title: '节点管理', kicker: 'Nodes' },
      },
      {
        path: 'node-groups',
        name: 'NodeGroups',
        component: () => import('@/views/nodes/NodeGroupsView.vue'),
        meta: { title: '权限组管理', kicker: 'Node Groups' },
      },
      {
        path: 'node-routes',
        name: 'NodeRoutes',
        component: () => import('@/views/nodes/NodeRoutesView.vue'),
        meta: { title: '路由管理', kicker: 'Node Routes' },
      },
      {
        path: 'tickets',
        name: 'Tickets',
        component: () => import('@/views/tickets/TicketsView.vue'),
        meta: { title: '工单管理', kicker: 'Tickets' },
      },
      {
        path: 'subscriptions/plans',
        name: 'SubscriptionPlans',
        component: () => import('@/views/subscriptions/PlansView.vue'),
        meta: { title: '订阅套餐', kicker: 'Plans' },
      },
      {
        path: 'system/config',
        name: 'SystemConfig',
        component: () => import('@/views/system/SystemConfigView.vue'),
        meta: { title: '系统配置', kicker: 'System Management' },
      },
      {
        path: 'system/plugins',
        name: 'SystemPlugins',
        component: () => import('@/views/system/SystemPlaceholderView.vue'),
        meta: { title: '插件管理', kicker: 'System Management' },
      },
      {
        path: 'system/themes',
        name: 'SystemThemes',
        component: () => import('@/views/system/SystemPlaceholderView.vue'),
        meta: { title: '主题配置', kicker: 'System Management' },
      },
      {
        path: 'system/notices',
        name: 'SystemNotices',
        component: () => import('@/views/system/SystemPlaceholderView.vue'),
        meta: { title: '公告管理', kicker: 'System Management' },
      },
      {
        path: 'system/payments',
        name: 'SystemPayments',
        component: () => import('@/views/system/SystemPlaceholderView.vue'),
        meta: { title: '支付配置', kicker: 'System Management' },
      },
      {
        path: 'system/knowledge',
        name: 'SystemKnowledge',
        component: () => import('@/views/system/SystemPlaceholderView.vue'),
        meta: { title: '知识库管理', kicker: 'System Management' },
      },
    ],
  },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

export default router
