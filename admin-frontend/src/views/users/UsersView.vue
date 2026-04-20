<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { MoreFilled, Plus, RefreshRight, Search } from '@element-plus/icons-vue'
import { deleteUser, fetchUsers, getPlans, resetUserSecret, updateUser } from '@/api/admin'
import type { AdminPlanOption, AdminUserListItem } from '@/types/api'
import { formatDateTime, formatTraffic } from '@/utils/dashboard'
import { buildUserFilters, getUserStatusMeta, getUserUsagePercent } from '@/utils/users'
import UserFormDrawer from './UserFormDrawer.vue'

type DrawerMode = 'create' | 'edit'
type UserAction = 'edit' | 'copy' | 'reset-secret' | 'toggle-ban' | 'delete'

const loading = ref(false)
const plansLoading = ref(false)
const users = ref<AdminUserListItem[]>([])
const plans = ref<AdminPlanOption[]>([])
const total = ref(0)
const current = ref(1)
const pageSize = ref(20)
const keyword = ref('')
const statusFilter = ref('all')
const planFilter = ref('all')

const drawerVisible = ref(false)
const drawerMode = ref<DrawerMode>('create')
const activeUser = ref<AdminUserListItem | null>(null)

const pageStats = computed(() => [
  { label: '用户总数', value: String(total.value) },
  { label: '当前页', value: String(current.value) },
  { label: '已筛选套餐', value: planFilter.value === 'all' ? '全部' : '单套餐' },
])

async function loadPlans() {
  plansLoading.value = true
  try {
    const response = await getPlans()
    plans.value = response.data ?? []
  } finally {
    plansLoading.value = false
  }
}

async function loadUsers() {
  loading.value = true
  try {
    const response = await fetchUsers({
      current: current.value,
      pageSize: pageSize.value,
      filter: buildUserFilters(keyword.value, statusFilter.value, planFilter.value),
      sort: [{ id: 'id', desc: true }],
    })

    users.value = response.data
    total.value = response.total
  } finally {
    loading.value = false
  }
}

function openCreateDrawer() {
  drawerMode.value = 'create'
  activeUser.value = null
  drawerVisible.value = true
}

function openEditDrawer(user: AdminUserListItem) {
  drawerMode.value = 'edit'
  activeUser.value = user
  drawerVisible.value = true
}

async function copySubscribeUrl(user: AdminUserListItem) {
  if (!navigator.clipboard?.writeText) {
    ElMessage.warning('当前环境不支持复制，请手动复制订阅地址')
    return
  }

  await navigator.clipboard.writeText(user.subscribe_url)
  ElMessage.success('订阅地址已复制')
}

async function toggleBan(user: AdminUserListItem) {
  const nextValue = !user.banned
  const actionText = nextValue ? '封禁' : '恢复'

  await ElMessageBox.confirm(`确认${actionText}用户 ${user.email} 吗？`, `${actionText}用户`, {
    type: 'warning',
  })

  await updateUser({ id: user.id, banned: nextValue })
  ElMessage.success(`用户已${actionText}`)
  await loadUsers()
}

async function handleAction(action: UserAction, user: AdminUserListItem) {
  if (action === 'edit') {
    openEditDrawer(user)
    return
  }

  if (action === 'copy') {
    await copySubscribeUrl(user)
    return
  }

  if (action === 'reset-secret') {
    await ElMessageBox.confirm(`确认重置 ${user.email} 的 UUID 与订阅地址吗？`, '重置密钥', {
      type: 'warning',
    })
    await resetUserSecret(user.id)
    ElMessage.success('UUID 与订阅地址已重置')
    await loadUsers()
    return
  }

  if (action === 'toggle-ban') {
    await toggleBan(user)
    return
  }

  await ElMessageBox.confirm(`删除用户 ${user.email} 后无法恢复，确认继续吗？`, '删除用户', {
    type: 'warning',
  })
  await deleteUser(user.id)
  ElMessage.success('用户已删除')
  await loadUsers()
}

function handleSearch() {
  current.value = 1
  void loadUsers()
}

function handleReset() {
  keyword.value = ''
  statusFilter.value = 'all'
  planFilter.value = 'all'
  current.value = 1
  void loadUsers()
}

watch(pageSize, () => {
  current.value = 1
  void loadUsers()
})

watch(current, () => {
  void loadUsers()
})

onMounted(() => {
  void Promise.all([loadPlans(), loadUsers()]).catch(() => {
    ElMessage.error('用户管理页面初始化失败')
  })
})
</script>

<template>
  <div class="users-page">
    <section class="users-hero">
      <div class="users-copy">
        <p class="users-kicker">Users</p>
        <h1>用户管理工作台。</h1>
        <span>用一页完成搜索、筛选、编辑与账户维护，保留 Apple 风格的轻量信息层次。</span>
      </div>

      <div class="hero-stats">
        <article v-for="item in pageStats" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
      </div>
    </section>

    <section class="table-shell">
      <header class="table-toolbar">
        <div class="toolbar-fields">
          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索用户邮箱..."
            class="toolbar-input"
            @keyup.enter="handleSearch"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>

          <ElSelect v-model="statusFilter" class="toolbar-select" placeholder="用户状态">
            <ElOption label="全部状态" value="all" />
            <ElOption label="正常" value="active" />
            <ElOption label="封禁" value="banned" />
          </ElSelect>

          <ElSelect
            v-model="planFilter"
            class="toolbar-select"
            :loading="plansLoading"
            placeholder="订阅计划"
          >
            <ElOption label="全部订阅" value="all" />
            <ElOption
              v-for="plan in plans"
              :key="plan.id"
              :label="plan.name"
              :value="String(plan.id)"
            />
          </ElSelect>
        </div>

        <div class="toolbar-actions">
          <ElButton @click="handleReset">
            <ElIcon><RefreshRight /></ElIcon>
            重置筛选
          </ElButton>
          <ElButton type="primary" @click="openCreateDrawer">
            <ElIcon><Plus /></ElIcon>
            创建用户
          </ElButton>
        </div>
      </header>

      <ElTable :data="users" v-loading="loading" class="users-table" row-key="id">
        <ElTableColumn prop="id" label="ID" width="92" />
        <ElTableColumn label="邮箱" min-width="220">
          <template #default="{ row }">
            <div class="email-cell">
              <strong>{{ row.email }}</strong>
              <span>{{ row.group?.name || '未分组' }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="108">
          <template #default="{ row }">
            <ElTag :type="getUserStatusMeta(row).type" effect="plain" round>
              {{ getUserStatusMeta(row).label }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="订阅" min-width="170">
          <template #default="{ row }">
            <div class="stack-cell">
              <strong>{{ row.plan?.name || '无订阅' }}</strong>
              <span>{{ row.device_limit ? `设备限制 ${row.device_limit}` : '未设设备限制' }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="已用流量" min-width="152">
          <template #default="{ row }">
            <div class="traffic-cell">
              <strong>{{ formatTraffic(row.total_used) }}</strong>
              <ElProgress
                :percentage="getUserUsagePercent(row)"
                :stroke-width="6"
                :show-text="false"
                color="#0071e3"
              />
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="总流量" width="120">
          <template #default="{ row }">
            {{ formatTraffic(row.transfer_enable) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="余额" width="118">
          <template #default="{ row }">
            ¥{{ Number(row.balance || 0).toFixed(2) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="到期时间" width="140">
          <template #default="{ row }">
            {{ row.expired_at ? formatDateTime(row.expired_at) : '长期有效' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="104" fixed="right">
          <template #default="{ row }">
            <ElDropdown trigger="click" @command="(command) => handleAction(command as UserAction, row)">
              <ElButton text class="action-trigger">
                <ElIcon><MoreFilled /></ElIcon>
              </ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem command="edit">编辑</ElDropdownItem>
                  <ElDropdownItem command="copy">复制订阅 URL</ElDropdownItem>
                  <ElDropdownItem command="reset-secret">重置 UUID 及订阅 URL</ElDropdownItem>
                  <ElDropdownItem command="toggle-ban">
                    {{ row.banned ? '恢复正常' : '封禁用户' }}
                  </ElDropdownItem>
                  <ElDropdownItem command="delete" divided>删除</ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
      </ElTable>

      <footer class="table-footer">
        <span>已加载 {{ users.length }} 条，共 {{ total }} 条</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          :total="total"
          background
        />
      </footer>
    </section>

    <UserFormDrawer
      v-model:visible="drawerVisible"
      :mode="drawerMode"
      :user="activeUser"
      :plans="plans"
      @success="() => loadUsers()"
    />
  </div>
</template>

<style scoped>
.users-page {
  display: grid;
  gap: 24px;
}

.users-hero {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  padding: 30px 32px;
  border-radius: 28px;
  background: #000000;
}

.users-copy {
  display: grid;
  gap: 10px;
  max-width: 620px;
}

.users-kicker {
  font-size: 11px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.68);
}

.users-copy h1 {
  font-size: clamp(34px, 5vw, 52px);
  line-height: 1.08;
  letter-spacing: -0.28px;
  color: #ffffff;
}

.users-copy span {
  color: rgba(255, 255, 255, 0.72);
  line-height: 1.47;
}

.hero-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  min-width: 360px;
}

.hero-stats article {
  display: grid;
  gap: 6px;
  padding: 18px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.08);
}

.hero-stats span {
  color: rgba(255, 255, 255, 0.64);
  font-size: 12px;
}

.hero-stats strong {
  color: #ffffff;
  font-size: 22px;
}

.table-shell {
  display: grid;
  gap: 18px;
  padding: 24px;
  border-radius: 26px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.table-toolbar,
.toolbar-fields,
.toolbar-actions,
.table-footer {
  display: flex;
  align-items: center;
  gap: 12px;
}

.table-toolbar,
.table-footer {
  justify-content: space-between;
}

.toolbar-fields {
  flex: 1;
  flex-wrap: wrap;
}

.toolbar-input {
  width: min(360px, 100%);
}

.toolbar-select {
  width: 160px;
}

.users-table :deep(th.el-table__cell) {
  color: var(--xboard-text-secondary);
  background: #fbfbfd;
}

.users-table :deep(.el-table__row td.el-table__cell) {
  padding-top: 16px;
  padding-bottom: 16px;
}

.email-cell,
.stack-cell,
.traffic-cell {
  display: grid;
  gap: 6px;
}

.email-cell strong,
.stack-cell strong {
  color: var(--xboard-text-strong);
}

.email-cell span,
.stack-cell span,
.table-footer span {
  color: var(--xboard-text-muted);
}

.traffic-cell {
  min-width: 132px;
}

.action-trigger {
  font-size: 18px;
}

@media (max-width: 1080px) {
  .users-hero,
  .table-toolbar,
  .table-footer {
    flex-direction: column;
    align-items: stretch;
  }

  .hero-stats {
    min-width: 0;
    grid-template-columns: 1fr;
  }

  .toolbar-actions {
    justify-content: flex-end;
  }
}
</style>
