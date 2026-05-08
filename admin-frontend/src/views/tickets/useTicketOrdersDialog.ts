import { computed, ref, type Ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  cancelOrder,
  fetchOrders,
  getOrderDetail,
  markOrderPaid,
  updateOrderCommissionStatus,
} from '@/api/admin'
import type { AdminOrderDetail, AdminOrderListItem, AdminTableSort } from '@/types/api'
import { canQuickConfirmCommission } from '@/utils/orders'

type OrderRowAction = 'detail' | 'confirm-commission'

export interface TicketOrdersDialogProps {
  visible: boolean
  userId: number | null
  userEmail?: string
}

function isCancelError(error: unknown): boolean {
  return error === 'cancel' || error === 'close'
}

export function useTicketOrdersDialog(props: TicketOrdersDialogProps) {
  const loading = ref(false)
  const errorMessage = ref('')
  const orders = ref<AdminOrderListItem[]>([])
  const total = ref(0)
  const current = ref(1)
  const pageSize = ref(20)
  const sortState = ref<AdminTableSort>({ id: 'created_at', desc: true })

  const detailVisible = ref(false)
  const detailLoading = ref(false)
  const detailOrder = ref<AdminOrderDetail | null>(null)
  const paying = ref(false)
  const cancelling = ref(false)
  const updatingCommission = ref(false)
  const quickConfirmTradeNo = ref('')

  const identityLabel = computed(() => props.userEmail || (props.userId ? `用户 #${props.userId}` : '未知用户'))

  async function loadOrders() {
    if (!props.visible || !props.userId) {
      orders.value = []
      total.value = 0
      return
    }

    loading.value = true
    errorMessage.value = ''

    try {
      const response = await fetchOrders({
        current: current.value,
        pageSize: pageSize.value,
        filter: [{ id: 'user_id', value: [props.userId] }],
        sort: [sortState.value],
      })
      orders.value = response.data ?? []
      total.value = response.total ?? 0
    } catch (error) {
      orders.value = []
      total.value = 0
      errorMessage.value = error instanceof Error ? error.message : '订单列表加载失败'
    } finally {
      loading.value = false
    }
  }

  function refreshOrders(resetPage = false) {
    if (resetPage && current.value !== 1) {
      current.value = 1
      return
    }

    void loadOrders()
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

  function resetDetailState() {
    detailVisible.value = false
    detailOrder.value = null
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
      if (!isCancelError(error)) {
        ElMessage.error(error instanceof Error ? error.message : '订单支付状态更新失败')
      }
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
      if (!isCancelError(error)) {
        ElMessage.error(error instanceof Error ? error.message : '订单取消失败')
      }
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

  function isRowActionWorking(order: Pick<AdminOrderListItem, 'trade_no'>) {
    return quickConfirmTradeNo.value === order.trade_no
  }

  async function handleQuickConfirmCommission(order: AdminOrderListItem) {
    if (!canQuickConfirmCommission(order)) {
      return
    }

    try {
      await ElMessageBox.confirm(
        `确认将订单 ${order.trade_no} 的佣金状态更新为“发放中”吗？`,
        '确认佣金',
        { type: 'warning' },
      )
      quickConfirmTradeNo.value = order.trade_no
      await updateOrderCommissionStatus(order.trade_no, 1)
      ElMessage.success('佣金已确认')
      await loadOrders()
    } catch (error) {
      if (!isCancelError(error)) {
        ElMessage.error(error instanceof Error ? error.message : '佣金确认失败')
      }
    } finally {
      quickConfirmTradeNo.value = ''
    }
  }

  async function handleRowAction(command: OrderRowAction, order: AdminOrderListItem) {
    if (command === 'detail') {
      await openDetail(order)
      return
    }

    await handleQuickConfirmCommission(order)
  }

  function handleSortChange(payload: { prop: string; order: 'ascending' | 'descending' | null }) {
    sortState.value = payload.prop && payload.order
      ? { id: payload.prop, desc: payload.order === 'descending' }
      : { id: 'created_at', desc: true }
    refreshOrders(false)
  }

  return {
    loading,
    errorMessage,
    orders,
    total,
    current: current as Ref<number>,
    pageSize: pageSize as Ref<number>,
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
  }
}
