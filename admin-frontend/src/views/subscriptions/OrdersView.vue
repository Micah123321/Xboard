<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, RefreshRight, Search, TopRight } from '@element-plus/icons-vue'
import {
  cancelOrder,
  fetchOrders,
  getOrderDetail,
  getPlans,
  markOrderPaid,
  updateOrderCommissionStatus,
} from '@/api/admin'
import type {
  AdminOrderDetail,
  AdminOrderListItem,
  AdminPlanListItem,
  AdminTableSort,
} from '@/types/api'
import {
  COMMISSION_STATUS_OPTIONS,
  ORDER_PERIOD_OPTIONS,
  ORDER_STATUS_OPTIONS,
  ORDER_TYPE_OPTIONS,
  buildOrderFetchFilters,
  formatOrderAmount,
  formatOrderDateTime,
  getCommissionStatusFilterLabel,
  getCommissionStatusMeta,
  getOrderFilterLabel,
  getOrderPeriodFilterLabel,
  getOrderPeriodLabel,
  getOrderStatusFilterLabel,
  getOrderStatusMeta,
  getOrderTypeMeta,
  type OrderFilterValue,
  type OrderPeriodKey,
} from '@/utils/orders'
import OrderAssignDrawer from './OrderAssignDrawer.vue'
import OrderDetailDrawer from './OrderDetailDrawer.vue'

const loading = ref(false)
const metaLoading = ref(false)
const errorMessage = ref('')

const orders = ref<AdminOrderListItem[]>([])
const plans = ref<AdminPlanListItem[]>([])
const total = ref(0)
const current = ref(1)
const pageSize = ref(20)

const keyword = ref('')
const typeFilter = ref<OrderFilterValue<number>>('all')
const periodFilter = ref<OrderFilterValue<OrderPeriodKey>>('all')
const statusFilter = ref<OrderFilterValue<number>>('all')
const commissionFilter = ref<OrderFilterValue<number>>('all')
const sortState = ref<AdminTableSort>({ id: 'created_at', desc: true })

const assignVisible = ref(false)
const detailVisible = ref(false)
const detailLoading = ref(false)
const detailOrder = ref<AdminOrderDetail | null>(null)
const paying = ref(false)
const cancelling = ref(false)
const updatingCommission = ref(false)

const filterButtonLabels = computed(() => ({
  type: typeFilter.value === 'all' ? '类型' : `类型 · ${getOrderFilterLabel(typeFilter.value)}`,
  period: periodFilter.value === 'all' ? '周期' : `周期 · ${getOrderPeriodFilterLabel(periodFilter.value)}`,
  status: statusFilter.value === 'all' ? '订单状态' : `订单状态 · ${getOrderStatusFilterLabel(statusFilter.value)}`,
  commission: commissionFilter.value === 'all'
    ? '佣金状态'
    : `佣金状态 · ${getCommissionStatusFilterLabel(commissionFilter.value)}`,
}))

async function loadPlans() {
  metaLoading.value = true
  try {
    const response = await getPlans()
    plans.value = response.data ?? []
  } catch (error) {
    ElMessage.warning(error instanceof Error ? error.message : '套餐列表加载失败，分配订单将暂时不可用')
  } finally {
    metaLoading.value = false
  }
}

async function loadOrders() {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await fetchOrders({
      current: current.value,
      pageSize: pageSize.value,
      filter: buildOrderFetchFilters({
        keyword: keyword.value,
        type: typeFilter.value,
        period: periodFilter.value,
        status: statusFilter.value,
        commissionStatus: commissionFilter.value,
      }),
      sort: sortState.value ? [sortState.value] : undefined,
    })

    orders.value = response.data ?? []
    total.value = response.total ?? 0
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '订单列表加载失败'
  } finally {
    loading.value = false
  }
}

function refreshOrders(resetPage: boolean = false) {
  if (resetPage && current.value !== 1) {
    current.value = 1
    return
  }

  void loadOrders()
}

function handleDropdownSelect(kind: 'type' | 'period' | 'status' | 'commission', value: string) {
  if (kind === 'type') {
    typeFilter.value = value === 'all' ? 'all' : Number(value)
  }

  if (kind === 'period') {
    periodFilter.value = value as OrderFilterValue<OrderPeriodKey>
  }

  if (kind === 'status') {
    statusFilter.value = value === 'all' ? 'all' : Number(value)
  }

  if (kind === 'commission') {
    commissionFilter.value = value === 'all' ? 'all' : Number(value)
  }

  refreshOrders(true)
}

function clearFilters() {
  keyword.value = ''
  typeFilter.value = 'all'
  periodFilter.value = 'all'
  statusFilter.value = 'all'
  commissionFilter.value = 'all'
  sortState.value = { id: 'created_at', desc: true }
  refreshOrders(true)
}

async function openDetail(order: AdminOrderListItem) {
  detailVisible.value = true
  detailLoading.value = true
  detailOrder.value = null

  try {
    const response = await getOrderDetail(order.id)
    detailOrder.value = response.data
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '订单详情加载失败')
    detailVisible.value = false
  } finally {
    detailLoading.value = false
  }
}

async function reloadDetail() {
  if (!detailOrder.value) {
    return
  }

  const response = await getOrderDetail(detailOrder.value.id)
  detailOrder.value = response.data
}

async function handleMarkPaid() {
  if (!detailOrder.value) {
    return
  }

  try {
    await ElMessageBox.confirm(`确认将订单 ${detailOrder.value.trade_no} 标记为已支付吗？`, '标记已支付', {
      type: 'warning',
    })
    paying.value = true
    await markOrderPaid(detailOrder.value.trade_no)
    ElMessage.success('订单已标记为支付成功')
    await Promise.all([loadOrders(), reloadDetail()])
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }
    ElMessage.error(error instanceof Error ? error.message : '订单支付状态更新失败')
  } finally {
    paying.value = false
  }
}

async function handleCancelOrder() {
  if (!detailOrder.value) {
    return
  }

  try {
    await ElMessageBox.confirm(`确认取消订单 ${detailOrder.value.trade_no} 吗？`, '取消订单', {
      type: 'warning',
    })
    cancelling.value = true
    await cancelOrder(detailOrder.value.trade_no)
    ElMessage.success('订单已取消')
    await Promise.all([loadOrders(), reloadDetail()])
  } catch (error) {
    if (error === 'cancel' || error === 'close') {
      return
    }
    ElMessage.error(error instanceof Error ? error.message : '订单取消失败')
  } finally {
    cancelling.value = false
  }
}

async function handleCommissionStatusUpdate(value: number) {
  if (!detailOrder.value) {
    return
  }

  updatingCommission.value = true
  try {
    await updateOrderCommissionStatus(detailOrder.value.trade_no, value)
    ElMessage.success('佣金状态已更新')
    await Promise.all([loadOrders(), reloadDetail()])
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '佣金状态更新失败')
  } finally {
    updatingCommission.value = false
  }
}

function handleAssignSuccess() {
  assignVisible.value = false
  refreshOrders(true)
}

function handleSortChange(payload: { prop: string; order: 'ascending' | 'descending' | null }) {
  if (!payload.prop || !payload.order) {
    sortState.value = { id: 'created_at', desc: true }
    refreshOrders(false)
    return
  }

  sortState.value = {
    id: payload.prop,
    desc: payload.order === 'descending',
  }
  refreshOrders(false)
}

watch([current, pageSize], () => {
  void loadOrders()
})

onMounted(() => {
  void Promise.all([loadPlans(), loadOrders()]).catch(() => {
    ElMessage.error('订单管理页面初始化失败')
  })
})
</script>

<template>
  <div class="orders-page">
    <section class="orders-intro">
      <div class="orders-copy">
        <p class="orders-kicker">Subscriptions</p>
        <h1>订单管理</h1>
        <span>在这里可以查看用户订单，包括分配、查看、删除等操作。</span>
      </div>
    </section>

    <section class="orders-shell">
      <header class="orders-toolbar">
        <div class="toolbar-left">
          <ElButton type="primary" @click="assignVisible = true">
            <ElIcon><Plus /></ElIcon>
            添加订单
          </ElButton>

          <ElInput
            v-model="keyword"
            clearable
            placeholder="搜索订单..."
            class="toolbar-search"
            @keyup.enter="refreshOrders(true)"
          >
            <template #prefix>
              <ElIcon><Search /></ElIcon>
            </template>
          </ElInput>

          <ElDropdown trigger="click" @command="handleDropdownSelect('type', $event)">
            <ElButton class="filter-pill">
              <ElIcon><Plus /></ElIcon>
              {{ filterButtonLabels.type }}
            </ElButton>
            <template #dropdown>
              <ElDropdownMenu>
                <ElDropdownItem command="all">全部类型</ElDropdownItem>
                <ElDropdownItem
                  v-for="item in ORDER_TYPE_OPTIONS"
                  :key="item.value"
                  :command="String(item.value)"
                >
                  {{ item.label }}
                </ElDropdownItem>
              </ElDropdownMenu>
            </template>
          </ElDropdown>

          <ElDropdown trigger="click" @command="handleDropdownSelect('period', $event)">
            <ElButton class="filter-pill">
              <ElIcon><Plus /></ElIcon>
              {{ filterButtonLabels.period }}
            </ElButton>
            <template #dropdown>
              <ElDropdownMenu>
                <ElDropdownItem command="all">全部周期</ElDropdownItem>
                <ElDropdownItem
                  v-for="item in ORDER_PERIOD_OPTIONS"
                  :key="item.value"
                  :command="item.value"
                >
                  {{ item.label }}
                </ElDropdownItem>
              </ElDropdownMenu>
            </template>
          </ElDropdown>

          <ElDropdown trigger="click" @command="handleDropdownSelect('status', $event)">
            <ElButton class="filter-pill">
              <ElIcon><Plus /></ElIcon>
              {{ filterButtonLabels.status }}
            </ElButton>
            <template #dropdown>
              <ElDropdownMenu>
                <ElDropdownItem command="all">全部订单状态</ElDropdownItem>
                <ElDropdownItem
                  v-for="item in ORDER_STATUS_OPTIONS"
                  :key="item.value"
                  :command="String(item.value)"
                >
                  {{ item.label }}
                </ElDropdownItem>
              </ElDropdownMenu>
            </template>
          </ElDropdown>

          <ElDropdown trigger="click" @command="handleDropdownSelect('commission', $event)">
            <ElButton class="filter-pill">
              <ElIcon><Plus /></ElIcon>
              {{ filterButtonLabels.commission }}
            </ElButton>
            <template #dropdown>
              <ElDropdownMenu>
                <ElDropdownItem command="all">全部佣金状态</ElDropdownItem>
                <ElDropdownItem
                  v-for="item in COMMISSION_STATUS_OPTIONS"
                  :key="item.value"
                  :command="String(item.value)"
                >
                  {{ item.label }}
                </ElDropdownItem>
              </ElDropdownMenu>
            </template>
          </ElDropdown>
        </div>

        <div class="toolbar-right">
          <ElButton class="toolbar-ghost" @click="clearFilters">
            重置筛选
          </ElButton>
          <ElButton class="toolbar-ghost" :loading="loading || metaLoading" @click="refreshOrders(false)">
            <ElIcon><RefreshRight /></ElIcon>
            刷新
          </ElButton>
        </div>
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
        empty-text="当前筛选条件下暂无订单"
        @sort-change="handleSortChange"
      >
        <ElTableColumn label="订单号" min-width="180">
          <template #default="{ row }">
            <ElButton text class="order-link" @click="openDetail(row)">
              <span class="order-link__code mono">{{ row.trade_no }}</span>
              <ElIcon><TopRight /></ElIcon>
            </ElButton>
          </template>
        </ElTableColumn>

        <ElTableColumn label="类型" width="112">
          <template #default="{ row }">
            <span class="type-pill" :class="`is-${getOrderTypeMeta(row.type).tone}`">
              {{ getOrderTypeMeta(row.type).label }}
            </span>
          </template>
        </ElTableColumn>

        <ElTableColumn label="订阅计划" min-width="230">
          <template #default="{ row }">
            <div class="plan-cell">
              <strong>{{ row.plan?.name || '未绑定套餐' }}</strong>
              <span>{{ getOrderPeriodLabel(row.period) }}</span>
            </div>
          </template>
        </ElTableColumn>

        <ElTableColumn prop="period" label="周期" width="110">
          <template #default="{ row }">
            <span class="period-pill">{{ getOrderPeriodLabel(row.period) }}</span>
          </template>
        </ElTableColumn>

        <ElTableColumn prop="total_amount" label="支付金额" min-width="140" sortable="custom">
          <template #default="{ row }">
            <strong class="amount-cell">{{ formatOrderAmount(row.total_amount) }}</strong>
          </template>
        </ElTableColumn>

        <ElTableColumn prop="status" label="订单状态" min-width="150" sortable="custom">
          <template #default="{ row }">
            <span class="status-pill" :class="`is-${getOrderStatusMeta(row.status).tone}`">
              <span class="status-dot" />
              {{ getOrderStatusMeta(row.status).label }}
            </span>
          </template>
        </ElTableColumn>

        <ElTableColumn prop="commission_balance" label="佣金金额" min-width="140" sortable="custom">
          <template #default="{ row }">
            <span>{{ row.commission_balance ? formatOrderAmount(row.commission_balance) : '-' }}</span>
          </template>
        </ElTableColumn>

        <ElTableColumn prop="commission_status" label="佣金状态" min-width="150" sortable="custom">
          <template #default="{ row }">
            <span class="status-pill" :class="`is-${getCommissionStatusMeta(row.commission_status, row.commission_balance).tone}`">
              <span class="status-dot" />
              {{ getCommissionStatusMeta(row.commission_status, row.commission_balance).label }}
            </span>
          </template>
        </ElTableColumn>

        <ElTableColumn prop="created_at" label="创建时间" min-width="180" sortable="custom">
          <template #default="{ row }">
            <span>{{ formatOrderDateTime(row.created_at) }}</span>
          </template>
        </ElTableColumn>
      </ElTable>

      <footer class="table-footer">
        <span>已选择 0 项，共 {{ total }} 项</span>
        <ElPagination
          v-model:current-page="current"
          v-model:page-size="pageSize"
          :page-sizes="[20, 50, 100]"
          layout="sizes, prev, pager, next"
          :total="total"
          background
        />
      </footer>
    </section>

    <OrderAssignDrawer
      :visible="assignVisible"
      :plans="plans"
      @update:visible="assignVisible = $event"
      @success="handleAssignSuccess"
    />

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
  </div>
</template>

<style scoped lang="scss" src="./OrdersView.scss"></style>
