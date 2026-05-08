<script setup lang="ts">
import { watch } from 'vue'
import { MoreFilled, RefreshRight, TopRight } from '@element-plus/icons-vue'
import {
  canQuickConfirmCommission,
  formatOrderAmount,
  formatOrderDateTime,
  getCommissionStatusMeta,
  getOrderPeriodLabel,
  getOrderStatusMeta,
  getOrderTypeMeta,
} from '@/utils/orders'
import OrderDetailDrawer from '@/views/subscriptions/OrderDetailDrawer.vue'
import { useTicketOrdersDialog } from './useTicketOrdersDialog'

const props = defineProps<{
  visible: boolean
  userId: number | null
  userEmail?: string
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
}>()

const {
  loading,
  errorMessage,
  orders,
  total,
  current,
  pageSize,
  detailVisible,
  detailLoading,
  detailOrder,
  paying,
  cancelling,
  updatingCommission,
  identityLabel,
  loadOrders,
  refreshOrders,
  openDetail,
  resetDetailState,
  handleMarkPaid,
  handleCancelOrder,
  handleCommissionStatusUpdate,
  isRowActionWorking,
  handleRowAction,
  handleSortChange,
} = useTicketOrdersDialog(props)

function closeDialog() {
  resetDetailState()
  emit('update:visible', false)
}

watch(
  () => [props.visible, props.userId],
  ([visible, userId]) => {
    if (!visible || !userId) {
      if (!visible) {
        resetDetailState()
      }
      return
    }

    current.value = 1
    void loadOrders()
  },
  { immediate: true },
)

watch([current, pageSize], () => {
  void loadOrders()
})
</script>

<template>
  <ElDialog
    :model-value="props.visible"
    width="min(960px, 94vw)"
    class="ticket-orders-dialog"
    append-to-body
    destroy-on-close
    @close="closeDialog"
    @update:model-value="emit('update:visible', $event)"
  >
    <template #header>
      <div class="dialog-header">
        <div>
          <p>Orders</p>
          <h2>用户订单</h2>
        </div>
        <span>{{ identityLabel }}</span>
      </div>
    </template>

    <div class="orders-panel">
      <header class="orders-toolbar">
        <span>共 {{ total }} 条订单</span>
        <ElButton text class="ghost-action" :loading="loading" @click="refreshOrders(false)">
          <ElIcon><RefreshRight /></ElIcon>
          刷新
        </ElButton>
      </header>

      <ElAlert
        v-if="errorMessage"
        class="orders-alert"
        type="error"
        :closable="false"
        show-icon
        :title="errorMessage"
      >
        <template #default>
          <ElButton size="small" @click="refreshOrders(false)">重新加载</ElButton>
        </template>
      </ElAlert>

      <ElTable
        :data="orders"
        v-loading="loading"
        class="orders-table"
        row-key="id"
        empty-text="当前用户暂无订单"
        @sort-change="handleSortChange"
      >
        <ElTableColumn label="订单号" min-width="178">
          <template #default="{ row }">
            <ElButton text class="order-link" @click="openDetail(row)">
              <span class="order-link__code mono">{{ row.trade_no }}</span>
              <ElIcon><TopRight /></ElIcon>
            </ElButton>
          </template>
        </ElTableColumn>
        <ElTableColumn label="类型" width="104">
          <template #default="{ row }">
            <span class="type-pill" :class="`is-${getOrderTypeMeta(row.type).tone}`">
              {{ getOrderTypeMeta(row.type).label }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn label="订阅计划" min-width="180">
          <template #default="{ row }">
            <div class="plan-cell">
              <strong>{{ row.plan?.name || '未绑定套餐' }}</strong>
              <span>{{ getOrderPeriodLabel(row.period) }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="total_amount" label="支付金额" min-width="128" sortable="custom">
          <template #default="{ row }">
            <strong class="amount-cell">{{ formatOrderAmount(row.total_amount) }}</strong>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="status" label="订单状态" min-width="132" sortable="custom">
          <template #default="{ row }">
            <span class="status-pill" :class="`is-${getOrderStatusMeta(row.status).tone}`">
              <span class="status-dot" />
              {{ getOrderStatusMeta(row.status).label }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="commission_status" label="佣金状态" min-width="132" sortable="custom">
          <template #default="{ row }">
            <span class="status-pill" :class="`is-${getCommissionStatusMeta(row.commission_status, row.commission_balance, row.actual_commission_balance).tone}`">
              <span class="status-dot" />
              {{ getCommissionStatusMeta(row.commission_status, row.commission_balance, row.actual_commission_balance).label }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="created_at" label="创建时间" min-width="166" sortable="custom">
          <template #default="{ row }">
            {{ formatOrderDateTime(row.created_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="82" fixed="right">
          <template #default="{ row }">
            <ElDropdown trigger="click" @command="(command) => handleRowAction(command as 'detail' | 'confirm-commission', row)">
              <ElButton text class="action-trigger" :loading="isRowActionWorking(row)">
                <ElIcon><MoreFilled /></ElIcon>
              </ElButton>
              <template #dropdown>
                <ElDropdownMenu>
                  <ElDropdownItem command="detail">查看详情</ElDropdownItem>
                  <ElDropdownItem
                    v-if="canQuickConfirmCommission(row)"
                    command="confirm-commission"
                    divided
                  >
                    确认佣金
                  </ElDropdownItem>
                </ElDropdownMenu>
              </template>
            </ElDropdown>
          </template>
        </ElTableColumn>
      </ElTable>

      <footer class="dialog-footer">
        <span>共 {{ total }} 条记录</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          :total="total"
          background
        />
      </footer>
    </div>

    <OrderDetailDrawer
      :visible="detailVisible"
      :loading="detailLoading"
      :order="detailOrder"
      :paying="paying"
      :cancelling="cancelling"
      :updating-commission="updatingCommission"
      @update:visible="detailVisible = $event"
      @paid="handleMarkPaid"
      @cancel="handleCancelOrder"
      @update-commission-status="handleCommissionStatusUpdate"
    />
  </ElDialog>
</template>

<style scoped lang="scss" src="./TicketOrdersDialog.scss"></style>
