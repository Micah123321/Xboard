<script setup lang="ts">
import { MoreFilled, Plus, RefreshRight, Search } from '@element-plus/icons-vue'
import { formatDateTime, formatTraffic } from '@/utils/dashboard'
import { getUserStatusMeta, getUserUsagePercent } from '@/utils/users'
import OrderAssignDrawer from '@/views/subscriptions/OrderAssignDrawer.vue'
import TrafficLogDialog from '@/views/tickets/TrafficLogDialog.vue'
import UserAdvancedFilterDialog from './UserAdvancedFilterDialog.vue'
import UserBatchMailDialog from './UserBatchMailDialog.vue'
import UserFormDrawer from './UserFormDrawer.vue'
import { useUsersManagement } from './useUsersManagement'

type UserAction =
  | 'edit'
  | 'assign-order'
  | 'copy'
  | 'reset-secret'
  | 'view-orders'
  | 'view-invites'
  | 'view-traffic'
  | 'reset-traffic'
  | 'toggle-ban'
  | 'delete'

const {
  loading,
  plansLoading,
  errorMessage,
  users,
  plans,
  total,
  current,
  pageSize,
  keyword,
  statusFilter,
  planFilter,
  advancedFilters,
  advancedFilterVisible,
  batchMailVisible,
  batchMailSubmitting,
  assignOrderVisible,
  assignOrderEmail,
  trafficLogVisible,
  trafficLogUserId,
  trafficLogUserEmail,
  drawerVisible,
  drawerMode,
  activeUser,
  selectedUsers,
  pageStats,
  appliedFilterSummaries,
  batchTargetLabel,
  batchActionDisabled,
  refreshUsers,
  handleSearch,
  handleReset,
  clearAdvancedFilters,
  applyAdvancedFilters,
  handleSelectionChange,
  openCreateDrawer,
  handleUserSaved,
  handleAction,
  handleBatchCommand,
  submitBatchMail,
  handleAssignOrderSuccess,
} = useUsersManagement()
</script>

<template>
  <div class="users-page">
    <section class="users-hero">
      <div class="users-copy">
        <p class="users-kicker">Users</p>
        <h1>用户管理工作台。</h1>
        <span>现在可以在同一页完成快捷筛选、高级筛选、行级维护与批量操作，继续保持 Apple 风格的轻量运营节奏。</span>
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

          <ElSelect v-model="statusFilter" class="toolbar-select" placeholder="用户状态" @change="handleSearch">
            <ElOption label="全部状态" value="all" />
            <ElOption label="正常" value="active" />
            <ElOption label="封禁" value="banned" />
          </ElSelect>

          <ElSelect
            v-model="planFilter"
            class="toolbar-select"
            :loading="plansLoading"
            placeholder="订阅计划"
            @change="handleSearch"
          >
            <ElOption label="全部订阅" value="all" />
            <ElOption
              v-for="plan in plans"
              :key="plan.id"
              :label="plan.name"
              :value="String(plan.id)"
            />
          </ElSelect>

          <ElButton class="filter-pill" @click="advancedFilterVisible = true">
            高级筛选
            <span v-if="appliedFilterSummaries.length" class="filter-pill__count">
              {{ appliedFilterSummaries.length }}
            </span>
          </ElButton>
        </div>

        <div class="toolbar-actions">
          <span class="scope-hint">{{ batchTargetLabel }}</span>

          <ElDropdown trigger="click" @command="handleBatchCommand">
            <ElButton class="toolbar-ghost" :disabled="batchActionDisabled">
              <ElIcon><MoreFilled /></ElIcon>
              批量操作
            </ElButton>
            <template #dropdown>
              <ElDropdownMenu>
                <ElDropdownItem command="send-mail">发送邮件</ElDropdownItem>
                <ElDropdownItem command="export-csv">导出 CSV</ElDropdownItem>
                <ElDropdownItem command="ban">批量封禁</ElDropdownItem>
                <ElDropdownItem command="restore">恢复正常</ElDropdownItem>
              </ElDropdownMenu>
            </template>
          </ElDropdown>

          <ElButton class="toolbar-ghost" @click="handleReset">
            <ElIcon><RefreshRight /></ElIcon>
            重置筛选
          </ElButton>

          <ElButton class="toolbar-ghost" :loading="loading" @click="refreshUsers(false)">
            <ElIcon><RefreshRight /></ElIcon>
            刷新
          </ElButton>

          <ElButton type="primary" @click="openCreateDrawer">
            <ElIcon><Plus /></ElIcon>
            创建用户
          </ElButton>
        </div>
      </header>

      <div v-if="appliedFilterSummaries.length" class="filter-summary">
        <span class="filter-summary__label">已生效筛选</span>
        <ElTag
          v-for="item in appliedFilterSummaries"
          :key="item"
          effect="plain"
          round
          class="filter-summary__tag"
        >
          {{ item }}
        </ElTag>
        <ElButton text class="filter-summary__clear" @click="clearAdvancedFilters">
          清空高级筛选
        </ElButton>
      </div>

      <ElAlert
        v-if="errorMessage"
        class="users-alert"
        type="error"
        :closable="false"
        show-icon
        :title="errorMessage"
      >
        <template #default>
          <ElButton size="small" @click="refreshUsers(false)">重新加载</ElButton>
        </template>
      </ElAlert>

      <ElTable
        :data="users"
        v-loading="loading"
        class="users-table"
        row-key="id"
        empty-text="当前筛选条件下暂无用户"
        @selection-change="handleSelectionChange"
      >
        <ElTableColumn type="selection" width="52" reserve-selection />
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
        <ElTableColumn label="在线设备" width="118">
          <template #default="{ row }">
            <div class="stack-cell">
              <strong>{{ row.online_count ?? 0 }}</strong>
              <span>当前在线</span>
            </div>
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
        <ElTableColumn label="注册时间" width="140">
          <template #default="{ row }">
            {{ formatDateTime(row.created_at) }}
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
                  <ElDropdownItem command="assign-order">分配订单</ElDropdownItem>
                  <ElDropdownItem command="copy">复制订阅URL</ElDropdownItem>
                  <ElDropdownItem command="reset-secret">重置UUID及订阅URL</ElDropdownItem>
                  <ElDropdownItem command="view-orders">TA的订单</ElDropdownItem>
                  <ElDropdownItem command="view-invites">TA的邀请</ElDropdownItem>
                  <ElDropdownItem command="view-traffic">TA的流量记录</ElDropdownItem>
                  <ElDropdownItem command="reset-traffic">重置流量</ElDropdownItem>
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
        <span>已选择 {{ selectedUsers.length }} 项，共 {{ total }} 项</span>
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
      @success="handleUserSaved"
    />

    <UserAdvancedFilterDialog
      v-model:visible="advancedFilterVisible"
      :filters="advancedFilters"
      :plans="plans"
      @apply="applyAdvancedFilters"
    />

    <UserBatchMailDialog
      v-model:visible="batchMailVisible"
      :loading="batchMailSubmitting"
      :target-label="batchTargetLabel"
      @submit="submitBatchMail"
    />

    <OrderAssignDrawer
      v-model:visible="assignOrderVisible"
      :plans="plans"
      :initial-email="assignOrderEmail"
      @success="handleAssignOrderSuccess"
    />

    <TrafficLogDialog
      v-model:visible="trafficLogVisible"
      :user-id="trafficLogUserId"
      :user-email="trafficLogUserEmail"
    />
  </div>
</template>

<style scoped lang="scss" src="./UsersView.scss"></style>
