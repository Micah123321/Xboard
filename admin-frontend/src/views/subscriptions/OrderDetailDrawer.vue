<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { AdminOrderDetail } from '@/types/api'
import {
  COMMISSION_STATUS_UPDATE_OPTIONS,
  canCancelOrder,
  canMarkOrderPaid,
  hasOrderCommission,
  canUpdateCommissionStatus,
  formatOrderAmount,
  formatOrderDateTime,
  getCommissionStatusMeta,
  getOrderPeriodLabel,
  getOrderStatusMeta,
  getOrderTypeMeta,
} from '@/utils/orders'

const props = defineProps<{
  visible: boolean
  loading: boolean
  order?: AdminOrderDetail | null
  paying?: boolean
  cancelling?: boolean
  updatingCommission?: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  paid: []
  cancel: []
  'update-commission-status': [value: number]
}>()

const commissionStatusDraft = ref<number | null>(null)

const statusMeta = computed(() => getOrderStatusMeta(props.order?.status))
const typeMeta = computed(() => getOrderTypeMeta(props.order?.type))
const commissionMeta = computed(() => getCommissionStatusMeta(
  props.order?.commission_status,
  props.order?.commission_balance,
  props.order?.actual_commission_balance,
))
const hasCommission = computed(() => hasOrderCommission(props.order))

const summaryCards = computed(() => [
  { label: '订单状态', value: statusMeta.value.label, detail: typeMeta.value.label },
  { label: '支付金额', value: formatOrderAmount(props.order?.total_amount), detail: '订单总额' },
  { label: '佣金金额', value: formatOrderAmount(props.order?.commission_balance), detail: commissionMeta.value.label },
  { label: '创建时间', value: formatOrderDateTime(props.order?.created_at), detail: '按后台记录时间展示' },
])

const basicFields = computed(() => [
  { label: '订单号', value: props.order?.trade_no || '-' },
  { label: '用户邮箱', value: props.order?.user?.email || '-' },
  { label: '邀请人', value: props.order?.invite_user?.email || '-' },
  { label: '订阅计划', value: props.order?.plan?.name || '-' },
  { label: '订单类型', value: typeMeta.value.label },
  { label: '订阅周期', value: getOrderPeriodLabel(props.order?.period) },
  { label: '回调编号', value: props.order?.callback_no || '-' },
  { label: '支付时间', value: formatOrderDateTime(props.order?.paid_at) },
])

const amountFields = computed(() => [
  { label: '订单金额', value: formatOrderAmount(props.order?.total_amount) },
  { label: '手续费', value: formatOrderAmount(props.order?.handling_amount) },
  { label: '余额支付', value: formatOrderAmount(props.order?.balance_amount) },
  { label: '优惠金额', value: formatOrderAmount(props.order?.discount_amount) },
  { label: '旧订阅折抵', value: formatOrderAmount(props.order?.surplus_amount) },
  { label: '退款金额', value: formatOrderAmount(props.order?.refund_amount) },
])

const actionState = computed(() => ({
  canPay: canMarkOrderPaid(props.order),
  canCancel: canCancelOrder(props.order),
  canUpdateCommission: canUpdateCommissionStatus(props.order),
}))

function closeDrawer() {
  emit('update:visible', false)
}

function submitCommissionStatus() {
  if (commissionStatusDraft.value === null) {
    return
  }

  emit('update-commission-status', commissionStatusDraft.value)
}

watch(
  () => [props.visible, props.order?.commission_status],
  ([visible]) => {
    if (!visible) {
      return
    }

    commissionStatusDraft.value = props.order?.commission_status ?? 0
  },
  { immediate: true },
)
</script>

<template>
  <ElDrawer
    :model-value="props.visible"
    title="订单详情"
    size="min(720px, 100vw)"
    class="order-detail-drawer"
    destroy-on-close
    @close="closeDrawer"
    @update:model-value="emit('update:visible', $event)"
  >
    <div v-if="props.loading" class="detail-loading">
      <ElSkeleton :rows="5" animated />
      <ElSkeleton :rows="6" animated />
    </div>

    <div v-else-if="props.order" class="detail-shell">
      <div class="detail-hero">
        <div class="hero-copy">
          <p>Order Detail</p>
          <h2>{{ props.order.trade_no }}</h2>
          <div class="hero-badges">
            <span class="hero-badge" :class="`is-${statusMeta.tone}`">{{ statusMeta.label }}</span>
            <span class="hero-badge is-neutral">{{ typeMeta.label }}</span>
          </div>
        </div>

        <div class="summary-grid">
          <article v-for="item in summaryCards" :key="item.label">
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
            <p>{{ item.detail }}</p>
          </article>
        </div>
      </div>

      <section class="detail-card">
        <header class="card-header">
          <div>
            <h3>基础信息</h3>
            <p>集中查看当前订单、用户与套餐的关键字段。</p>
          </div>
        </header>

        <div class="description-grid">
          <article v-for="item in basicFields" :key="item.label">
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </article>
        </div>
      </section>

      <section class="detail-card">
        <header class="card-header">
          <div>
            <h3>金额拆解</h3>
            <p>订单金额按后端分为单位存储，这里统一换算成人类可读金额。</p>
          </div>
        </header>

        <div class="description-grid">
          <article v-for="item in amountFields" :key="item.label">
            <span>{{ item.label }}</span>
            <strong>{{ item.value }}</strong>
          </article>
        </div>
      </section>

      <section class="detail-card">
        <header class="card-header">
          <div>
            <h3>佣金状态</h3>
            <p>{{ hasCommission ? '仅对真实佣金订单开放状态维护。' : '当前订单未产生佣金，不进入佣金确认或发放流程。' }}</p>
          </div>
          <span class="hero-badge" :class="`is-${commissionMeta.tone}`">{{ commissionMeta.label }}</span>
        </header>

        <div class="commission-grid">
          <article>
            <span>佣金金额</span>
            <strong>{{ formatOrderAmount(props.order.commission_balance) }}</strong>
          </article>
          <article>
            <span>实际发放</span>
            <strong>{{ formatOrderAmount(props.order.actual_commission_balance) }}</strong>
          </article>
        </div>

        <div v-if="actionState.canUpdateCommission" class="commission-actions">
          <ElSelect v-model="commissionStatusDraft" placeholder="请选择佣金状态">
            <ElOption
              v-for="item in COMMISSION_STATUS_UPDATE_OPTIONS"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </ElSelect>
          <ElButton
            type="primary"
            :loading="props.updatingCommission"
            @click="submitCommissionStatus"
          >
            保存佣金状态
          </ElButton>
        </div>

        <p v-else class="commission-empty-note">
          {{ hasCommission ? '当前佣金状态已完成发放，列表页与详情页均不再提供编辑入口。' : '该订单没有真实佣金，列表页也不会出现在“确认佣金”筛选结果中。' }}
        </p>
      </section>

      <section v-if="props.order.surplus_orders?.length" class="detail-card">
        <header class="card-header">
          <div>
            <h3>折抵订单</h3>
            <p>升级单会展示被折抵的旧订单记录，便于人工追踪。</p>
          </div>
        </header>

        <div class="list-shell">
          <article
            v-for="item in props.order.surplus_orders"
            :key="item.id"
            class="list-row"
          >
            <div>
              <strong>{{ item.trade_no }}</strong>
              <span>{{ getOrderPeriodLabel(item.period) }} · {{ getOrderStatusMeta(item.status).label }}</span>
            </div>
            <strong>{{ formatOrderAmount(item.total_amount) }}</strong>
          </article>
        </div>
      </section>

      <section v-if="props.order.commission_log?.length" class="detail-card">
        <header class="card-header">
          <div>
            <h3>佣金记录</h3>
            <p>展示当前订单已生成的佣金流水，便于核对发放链路。</p>
          </div>
        </header>

        <div class="list-shell">
          <article
            v-for="item in props.order.commission_log"
            :key="item.id"
            class="list-row"
          >
            <div>
              <strong>#{{ item.id }} · 用户 {{ item.invite_user_id }}</strong>
              <span>{{ formatOrderDateTime(item.created_at) }}</span>
            </div>
            <strong>{{ formatOrderAmount(item.get_amount) }}</strong>
          </article>
        </div>
      </section>
    </div>

    <ElEmpty v-else description="暂无订单详情" />

    <template #footer>
      <div class="drawer-actions">
        <ElButton @click="closeDrawer">关闭</ElButton>
        <ElButton
          v-if="actionState.canCancel"
          type="danger"
          plain
          :loading="props.cancelling"
          @click="emit('cancel')"
        >
          取消订单
        </ElButton>
        <ElButton
          v-if="actionState.canPay"
          type="primary"
          :loading="props.paying"
          @click="emit('paid')"
        >
          标记已支付
        </ElButton>
      </div>
    </template>
  </ElDrawer>
</template>

<style scoped>
.detail-loading,
.detail-shell {
  display: grid;
  gap: 18px;
}

.detail-hero {
  display: grid;
  gap: 16px;
  padding: 26px 28px;
  border-radius: 28px;
  background: #000000;
}

.hero-copy {
  display: grid;
  gap: 8px;
}

.hero-copy p {
  margin: 0;
  color: rgba(255, 255, 255, 0.64);
  font-size: 11px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
}

.hero-copy h2 {
  margin: 0;
  color: #ffffff;
  font-size: clamp(28px, 4vw, 38px);
  line-height: 1.08;
  word-break: break-all;
}

.hero-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.hero-badge {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.1);
  color: #ffffff;
  font-size: 12px;
}

.hero-badge.is-success {
  background: rgba(35, 134, 63, 0.18);
  color: #74d692;
}

.hero-badge.is-warning {
  background: rgba(224, 124, 35, 0.2);
  color: #ffcb87;
}

.hero-badge.is-danger {
  background: rgba(201, 52, 40, 0.2);
  color: #ffb4aa;
}

.hero-badge.is-info {
  background: rgba(0, 113, 227, 0.2);
  color: #8cc6ff;
}

.hero-badge.is-neutral {
  background: rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.84);
}

.summary-grid,
.description-grid,
.commission-grid {
  display: grid;
  gap: 12px;
}

.summary-grid {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-grid article,
.description-grid article,
.commission-grid article {
  display: grid;
  gap: 6px;
  padding: 18px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.08);
}

.summary-grid span,
.summary-grid p {
  color: rgba(255, 255, 255, 0.68);
}

.summary-grid strong {
  color: #ffffff;
  font-size: 22px;
  line-height: 1.14;
}

.detail-card {
  display: grid;
  gap: 18px;
  padding: 24px 26px;
  border-radius: 24px;
  background: #ffffff;
  box-shadow: var(--xboard-shadow);
}

.card-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.card-header h3 {
  margin: 0;
  color: var(--xboard-text-strong);
  font-size: 24px;
  line-height: 1.12;
}

.card-header p {
  margin: 8px 0 0;
  color: var(--xboard-text-secondary);
  line-height: 1.5;
}

.description-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.description-grid article,
.commission-grid article {
  background: #fbfbfd;
}

.description-grid span,
.commission-grid span,
.list-row span {
  color: var(--xboard-text-muted);
}

.description-grid strong,
.commission-grid strong,
.list-row strong {
  color: var(--xboard-text-strong);
  line-height: 1.45;
}

.commission-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.commission-actions {
  display: flex;
  gap: 12px;
}

.commission-empty-note {
  margin: 0;
  color: var(--xboard-text-muted);
  line-height: 1.6;
}

.commission-actions :deep(.el-select) {
  flex: 1;
}

.list-shell {
  display: grid;
  gap: 12px;
}

.list-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-radius: 16px;
  background: #fbfbfd;
}

.list-row > div {
  display: grid;
  gap: 4px;
}

.drawer-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  width: 100%;
}

@media (max-width: 960px) {
  .summary-grid,
  .description-grid,
  .commission-grid {
    grid-template-columns: 1fr;
  }

  .card-header,
  .commission-actions,
  .list-row,
  .drawer-actions {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
