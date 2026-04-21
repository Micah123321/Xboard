<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { DataAnalysis, Search, View } from '@element-plus/icons-vue'
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

watch([current, pageSize], () => {
  void loadTickets()
})

watch([statusFilter, levelFilter], () => {
  current.value = 1
  void loadTickets()
})

onMounted(() => {
  void loadTickets()
})
</script>

<template>
  <div class="tickets-page">
    <section class="tickets-hero">
      <div class="tickets-copy">
        <p class="tickets-kicker">Tickets</p>
        <h1>工单管理</h1>
        <span>在这里可以查看用户工单，包括查看、回复、关闭与流量日志辅助排查。</span>
      </div>

      <div class="hero-stats">
        <article v-for="item in headerStats" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
      </div>
    </section>

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
  gap: 24px;
}

.tickets-hero {
  display: flex;
  justify-content: space-between;
  gap: 24px;
  padding: 30px 32px;
  border-radius: 28px;
  background: #000000;
}

.tickets-copy {
  display: grid;
  gap: 10px;
  max-width: 680px;
}

.tickets-kicker {
  font-size: 11px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.68);
}

.tickets-copy h1 {
  font-size: clamp(36px, 5vw, 52px);
  line-height: 1.08;
  letter-spacing: -0.28px;
  color: #ffffff;
}

.tickets-copy span {
  color: rgba(255, 255, 255, 0.72);
  line-height: 1.47;
}

.hero-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  min-width: 260px;
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
  .tickets-hero,
  .ticket-footer {
    flex-direction: column;
    align-items: stretch;
  }

  .hero-stats {
    min-width: 0;
  }
}
</style>
