<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { DataAnalysis, Search, View } from '@element-plus/icons-vue'
import { useRoute, useRouter } from 'vue-router'
import { closeTicket, fetchTickets } from '@/api/admin'
import type { AdminTicketListItem } from '@/types/api'
import { formatDateTime } from '@/utils/dashboard'
import {
  buildTicketFilters,
  getTicketLevelMeta,
  getTicketStatusMeta,
} from '@/utils/tickets'
import TicketWorkspaceDialog from './TicketWorkspaceDialog.vue'

const loading = ref(false)
const route = useRoute()
const router = useRouter()
const tickets = ref<AdminTicketListItem[]>([])
const total = ref(0)
const current = ref(1)
const pageSize = ref(20)
const keyword = ref('')
const statusFilter = ref<'opening' | 'closed' | 'all'>('opening')
const levelFilter = ref('all')

const workspaceVisible = ref(false)
const activeTicketId = ref<number | null>(null)

const headerStats = computed(() => [
  {
    label: statusFilter.value === 'opening' ? '处理中' : statusFilter.value === 'closed' ? '已关闭' : '全部工单',
    value: String(total.value),
  },
  { label: '当前页', value: String(current.value) },
])

const dashboardFocus = computed<'opening' | 'closed' | 'all' | null>(() => {
  const raw = route.query.focus
  const value = Array.isArray(raw) ? raw[0] : raw
  if (value === 'closed') {
    return 'closed'
  }

  if (value === 'all') {
    return 'all'
  }

  if (value === 'opening' || value === 'pending') {
    return 'opening'
  }

  return null
})

const dashboardEntryNotice = computed(() => {
  const raw = route.query.source
  const source = Array.isArray(raw) ? raw[0] : raw
  if (source !== 'dashboard') {
    return ''
  }

  if (dashboardFocus.value === 'opening') {
    return '已从仪表盘进入，这里默认展示待处理工单。'
  }

  return '已从仪表盘进入工单工作台。'
})

const ticketQueryId = computed(() => {
  const raw = route.query.ticket_id
  const value = Array.isArray(raw) ? raw[0] : raw
  const numeric = Number(value)
  return Number.isFinite(numeric) && numeric > 0 ? numeric : null
})

function statusValueToParam() {
  if (statusFilter.value === 'opening') {
    return 0
  }

  if (statusFilter.value === 'closed') {
    return 1
  }

  return undefined
}

async function loadTickets() {
  loading.value = true
  try {
    const extra = buildTicketFilters(keyword.value, levelFilter.value)
    const response = await fetchTickets({
      current: current.value,
      pageSize: pageSize.value,
      status: statusValueToParam(),
      email: extra.email,
      filter: extra.filter,
    })
    tickets.value = response.data
    total.value = response.total
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '工单列表加载失败')
  } finally {
    loading.value = false
  }
}

function openWorkspace(ticket: AdminTicketListItem) {
  activeTicketId.value = ticket.id
  workspaceVisible.value = true
}

function openWorkspaceById(ticketId: number | null) {
  if (!ticketId) {
    return
  }

  activeTicketId.value = ticketId
  workspaceVisible.value = true
}

async function handleCloseFromTable(ticket: AdminTicketListItem) {
  if (ticket.status === 1) {
    return
  }

  await ElMessageBox.confirm(`确认关闭工单 #${ticket.id} 吗？`, '关闭工单', {
    type: 'warning',
  })

  try {
    await closeTicket(ticket.id)
    ElMessage.success('工单已关闭')
    await loadTickets()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '关闭工单失败')
  }
}

function handleSearch() {
  current.value = 1
  void loadTickets()
}

function applyDashboardFocus() {
  if (!dashboardFocus.value) {
    return
  }

  statusFilter.value = dashboardFocus.value
}

function clearDashboardEntry() {
  const nextQuery = { ...route.query }
  delete nextQuery.source
  delete nextQuery.focus
  void router.replace({
    name: 'Tickets',
    query: nextQuery,
  })
}

watch([current, pageSize], () => {
  void loadTickets()
})

watch([statusFilter, levelFilter], () => {
  current.value = 1
  void loadTickets()
})

watch(
  () => route.query.focus,
  () => {
    applyDashboardFocus()
  },
)

watch(
  () => route.query.ticket_id,
  () => {
    openWorkspaceById(ticketQueryId.value)
  },
)

onMounted(() => {
  applyDashboardFocus()
  openWorkspaceById(ticketQueryId.value)
  void loadTickets()
})
</script>

<template>
  <div class="tickets-page">
    <PageHeader title="工单管理" :stats="headerStats" />

    <section class="ticket-board">
      <header class="ticket-toolbar">
        <div class="ticket-filters">
          <ElSegmented
            v-model="statusFilter"
            :options="[
              { label: '处理中', value: 'opening' },
              { label: '已关闭', value: 'closed' },
              { label: '全部', value: 'all' },
            ]"
          />

          <ElSelect v-model="levelFilter" class="toolbar-select" placeholder="优先级">
            <ElOption label="全部优先级" value="all" />
            <ElOption label="低优先" value="0" />
            <ElOption label="中优先" value="1" />
            <ElOption label="高优先" value="2" />
          </ElSelect>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索工单标题或用户邮箱"
            class="toolbar-search"
            @keyup.enter="handleSearch"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>
        </div>
      </header>

      <ElAlert
        v-if="dashboardEntryNotice"
        class="tickets-alert"
        type="info"
        :closable="false"
        show-icon
        :title="dashboardEntryNotice"
      >
        <template #default>
          <ElButton size="small" @click="clearDashboardEntry">关闭提示</ElButton>
        </template>
      </ElAlert>

      <ElTable :data="tickets" v-loading="loading" class="ticket-table" row-key="id">
        <ElTableColumn label="工单号" width="92">
          <template #default="{ row }">
            <ElTag effect="plain" round>#{{ row.id }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="主题" min-width="280">
          <template #default="{ row }">
            <div class="subject-cell">
              <strong>{{ row.subject }}</strong>
              <span>{{ row.user?.email || '未知用户' }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn label="优先级" width="110">
          <template #default="{ row }">
            <ElTag round effect="plain" :type="getTicketLevelMeta(row.level).type">
              {{ getTicketLevelMeta(row.level).label }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="110">
          <template #default="{ row }">
            <ElTag round :type="getTicketStatusMeta(row).type">
              {{ getTicketStatusMeta(row).label }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="最后更新" width="150">
          <template #default="{ row }">
            {{ formatDateTime(row.updated_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="创建时间" width="150">
          <template #default="{ row }">
            {{ formatDateTime(row.created_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="134" fixed="right">
          <template #default="{ row }">
            <div class="action-group">
              <ElButton text class="action-btn" @click="openWorkspace(row)">
                <ElIcon><View /></ElIcon>
              </ElButton>
              <ElButton
                text
                class="action-btn danger-btn"
                :disabled="row.status === 1"
                @click="handleCloseFromTable(row)"
              >
                <ElIcon><DataAnalysis /></ElIcon>
              </ElButton>
            </div>
          </template>
        </ElTableColumn>
      </ElTable>

      <footer class="ticket-footer">
        <span>已选择 0 项，共 {{ total }} 项</span>
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

    <TicketWorkspaceDialog
      v-model:visible="workspaceVisible"
      :ticket-id="activeTicketId"
      :status-preset="statusValueToParam()"
      @updated="loadTickets"
    />
  </div>
</template>

<style scoped>
.tickets-page {
  display: grid;
  gap: 16px;
}

.ticket-board {
  display: grid;
  gap: 18px;
  padding: 24px;
  border-radius: 26px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.ticket-toolbar,
.ticket-filters,
.ticket-footer,
.action-group {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ticket-filters {
  flex-wrap: wrap;
}

.toolbar-select {
  width: 150px;
}

.toolbar-search {
  width: min(320px, 100%);
}

.ticket-table :deep(th.el-table__cell) {
  color: var(--xboard-text-secondary);
  background: #fbfbfd;
}

.ticket-table :deep(.el-table__row td.el-table__cell) {
  padding-top: 16px;
  padding-bottom: 16px;
}

.tickets-alert {
  margin-top: -4px;
}

.subject-cell {
  display: grid;
  gap: 6px;
}

.subject-cell strong {
  color: var(--xboard-text-strong);
}

.subject-cell span,
.ticket-footer span {
  color: var(--xboard-text-muted);
}

.action-btn {
  font-size: 18px;
}

.danger-btn {
  color: var(--xboard-danger);
}

.ticket-footer {
  justify-content: space-between;
}

@media (max-width: 1080px) {
  .ticket-footer {
    flex-direction: column;
    align-items: stretch;
  }

}
</style>
